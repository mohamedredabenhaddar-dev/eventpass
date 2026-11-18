<?php

namespace Tests\Integration;

use App\Enums\AccessAction;
use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Enums\OrganizerRequestStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketAccessState;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessLog;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketCredentialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('eventpass_test', $connection->getDatabaseName());
        $this->assertSame('eventpass_test', $connection->selectOne('SELECT DATABASE() AS database_name')->database_name);
        $this->assertSame('eventpass_test', config('database.connections.mysql.database'));
    }

    public function test_two_clients_cannot_reserve_the_final_ticket_concurrently(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 1);
        [$clientOne, $tokenOne] = $this->makeUser(UserRole::Client);
        [$clientTwo, $tokenTwo] = $this->makeUser(UserRole::Client);

        $results = $this->runConcurrently([
            $this->request('client-one', 'POST', "/api/events/{$event->id}/reservations", ['items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]]], $tokenOne),
            $this->request('client-two', 'POST', "/api/events/{$event->id}/reservations", ['items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]]], $tokenTwo),
        ]);

        $this->assertSame([201, 422], $this->sortedStatuses($results));
        $this->assertSame(0, $ticketType->fresh()->available_quantity);
        $this->assertSame(1, Reservation::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, ReservationItem::query()->whereHas('reservation', fn ($query) => $query->where('event_id', $event->id))->count());
        $this->assertSame(1, Ticket::query()->whereHas('reservation', fn ($query) => $query->where('event_id', $event->id))->count());
        $this->assertGreaterThanOrEqual(0, $ticketType->fresh()->available_quantity);
    }

    public function test_concurrent_reservations_across_ticket_types_cannot_exceed_event_capacity(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);
        $event->forceFill(['capacity' => 1])->save();
        // Simulate legacy configured inventory that exceeds capacity, so the test exercises
        // the reservation transaction's event-wide guard across different ticket types.
        $firstType = $this->makeTicketType($event, 1);
        $secondType = $this->makeTicketType($event, 1);
        [, $tokenOne] = $this->makeUser(UserRole::Client);
        [, $tokenTwo] = $this->makeUser(UserRole::Client);

        $results = $this->runConcurrently([
            $this->request('capacity-type-one', 'POST', "/api/events/{$event->id}/reservations", [
                'items' => [['ticket_type_id' => $firstType->id, 'quantity' => 1]],
            ], $tokenOne),
            $this->request('capacity-type-two', 'POST', "/api/events/{$event->id}/reservations", [
                'items' => [['ticket_type_id' => $secondType->id, 'quantity' => 1]],
            ], $tokenTwo),
        ]);

        $this->assertSame([201, 422], $this->sortedStatuses($results));
        $activeQuantity = ReservationItem::query()
            ->whereHas('reservation', fn ($query) => $query
                ->where('event_id', $event->id)
                ->whereIn('status', [ReservationStatus::PendingPayment->value, ReservationStatus::Confirmed->value]))
            ->sum('quantity');
        $this->assertSame(1, (int) $activeQuantity);
        $this->assertSame(1, Ticket::query()->whereHas('reservation', fn ($query) => $query->where('event_id', $event->id))->count());
    }

    public function test_two_concurrent_cancellations_restore_inventory_once(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makeReservation($client, $event, $ticketType, 2);

        $results = $this->runConcurrently([
            $this->request('cancel-one', 'PATCH', "/api/reservations/{$reservation->id}/cancel", [], $clientToken),
            $this->request('cancel-two', 'PATCH', "/api/reservations/{$reservation->id}/cancel", [], $clientToken),
        ]);

        $this->assertSame([200, 409], $this->sortedStatuses($results));
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(5, $ticketType->fresh()->available_quantity);
        $this->assertSame(2, Ticket::query()->where('reservation_id', $reservation->id)->where('status', TicketStatus::Cancelled->value)->count());
    }

    public function test_expiration_racing_successful_payment_cannot_confirm_an_expired_reservation(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makePendingReservation($client, $event, $ticketType, 2);
        $payment = $this->createPaymentAttempt($client, $reservation);
        $reservation->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        $results = $this->runConcurrently([
            $this->expireRequest('expire-race', $reservation, $clientToken),
            $this->request('pay-expired', 'POST', "/api/payments/{$payment->id}/simulate-success", [], $clientToken),
        ]);

        $this->assertSame([200, 409], $this->sortedStatuses($results));
        $this->assertSame(true, collect($results)->firstWhere('label', 'expire-race')['service_result']);
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::Expired, $reservation->fresh()->payment_status);
        $this->assertSame(PaymentAttemptStatus::Expired, $payment->fresh()->status);
        $this->assertSame(0, $reservation->tickets()->where('status', TicketStatus::Valid->value)->count());
        $this->assertSame(2, $reservation->tickets()->where('status', TicketStatus::Cancelled->value)->count());
        $this->assertSame(5, $ticketType->fresh()->available_quantity);
        $this->assertSame(0, Payment::query()->where('reservation_id', $reservation->id)->where('status', PaymentAttemptStatus::Succeeded->value)->count());
    }

    public function test_cancellation_racing_successful_payment_leaves_one_consistent_lifecycle_outcome(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makePendingReservation($client, $event, $ticketType, 2);
        $payment = $this->createPaymentAttempt($client, $reservation);

        $results = $this->runConcurrently([
            $this->request('cancel-race', 'PATCH', "/api/reservations/{$reservation->id}/cancel", [], $clientToken),
            $this->request('pay-race', 'POST', "/api/payments/{$payment->id}/simulate-success", [], $clientToken),
        ]);

        $this->assertSame([200, 409], $this->sortedStatuses($results));
        if ($reservation->fresh()->status === ReservationStatus::Confirmed) {
            $this->assertSame(PaymentStatus::Paid, $reservation->fresh()->payment_status);
            $this->assertSame(PaymentAttemptStatus::Succeeded, $payment->fresh()->status);
            $this->assertSame(2, $reservation->tickets()->where('status', TicketStatus::Valid->value)->count());
            $this->assertSame(3, $ticketType->fresh()->available_quantity);
        } else {
            $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
            $this->assertSame(PaymentStatus::Expired, $reservation->fresh()->payment_status);
            $this->assertSame(PaymentAttemptStatus::Expired, $payment->fresh()->status);
            $this->assertSame(0, $reservation->tickets()->where('status', TicketStatus::Valid->value)->count());
            $this->assertSame(2, $reservation->tickets()->where('status', TicketStatus::Cancelled->value)->count());
            $this->assertSame(5, $ticketType->fresh()->available_quantity);
        }
        $this->assertLessThanOrEqual(1, Payment::query()->where('reservation_id', $reservation->id)->where('status', PaymentAttemptStatus::Succeeded->value)->count());
    }

    public function test_two_payment_attempts_cannot_succeed_for_one_reservation(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makePendingReservation($client, $event, $ticketType, 2);
        $first = $this->createPaymentAttempt($client, $reservation);
        $second = $reservation->payments()->create([
            'payment_reference' => 'DEMO-'.Str::upper((string) Str::ulid()),
            'amount' => $reservation->total,
            'currency' => 'MAD',
            'status' => PaymentAttemptStatus::Pending,
            'payment_method' => 'demo',
        ]);

        $results = $this->runConcurrently([
            $this->request('succeed-first', 'POST', "/api/payments/{$first->id}/simulate-success", [], $clientToken),
            $this->request('succeed-second', 'POST', "/api/payments/{$second->id}/simulate-success", [], $clientToken),
        ]);

        $this->assertSame([200, 409], $this->sortedStatuses($results));
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $reservation->fresh()->payment_status);
        $this->assertSame(1, Payment::query()->where('reservation_id', $reservation->id)->where('status', PaymentAttemptStatus::Succeeded->value)->count());
        $this->assertSame(1, Payment::query()->where('reservation_id', $reservation->id)->where('status', PaymentAttemptStatus::Expired->value)->count());
        $this->assertSame(2, $reservation->tickets()->where('status', TicketStatus::Valid->value)->count());
        $this->assertSame(3, $ticketType->fresh()->available_quantity);
    }

    public function test_expiration_racing_organizer_ticket_type_update_preserves_inventory(): void
    {
        [$organizer, $organizerToken] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makePendingReservation($client, $event, $ticketType, 2, expired: true);

        $results = $this->runConcurrently([
            $this->expireRequest('expire-update-race', $reservation, $clientToken),
            $this->request('organizer-update', 'PATCH', "/api/organizer/events/{$event->id}/ticket-types/{$ticketType->id}", ['quantity' => 6], $organizerToken),
        ]);

        $this->assertSame([200, 200], $this->sortedStatuses($results));
        $this->assertSame(true, collect($results)->firstWhere('label', 'expire-update-race')['service_result']);
        $this->assertSame(6, $ticketType->fresh()->quantity);
        $this->assertSame(6, $ticketType->fresh()->available_quantity);
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(2, $reservation->tickets()->where('status', TicketStatus::Cancelled->value)->count());
        $this->assertGreaterThanOrEqual(0, $ticketType->fresh()->available_quantity);
        $this->assertLessThanOrEqual($ticketType->fresh()->quantity, $ticketType->fresh()->available_quantity);
    }

    public function test_concurrent_payment_requests_reuse_one_pending_attempt(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makePendingReservation($client, $event, $ticketType, 2);

        $results = $this->runConcurrently([
            $this->request('payment-request-one', 'POST', "/api/reservations/{$reservation->id}/payments", [], $clientToken),
            $this->request('payment-request-two', 'POST', "/api/reservations/{$reservation->id}/payments", [], $clientToken),
        ]);

        $this->assertSame([201, 201], $this->sortedStatuses($results));
        $this->assertSame(1, Payment::query()->where('reservation_id', $reservation->id)->where('status', PaymentAttemptStatus::Pending->value)->count());
        $this->assertSame(1, Payment::query()->where('reservation_id', $reservation->id)->count());
    }

    public function test_concurrent_expiration_restores_inventory_exactly_once(): void
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 5, 3);
        $reservation = $this->makePendingReservation($client, $event, $ticketType, 2);
        $payment = $this->createPaymentAttempt($client, $reservation);
        $reservation->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        $results = $this->runConcurrently([
            $this->expireRequest('expire-one', $reservation, $clientToken),
            $this->expireRequest('expire-two', $reservation, $clientToken),
        ]);

        $this->assertSame([200, 204], $this->sortedStatuses($results));
        $this->assertSame([false, true], collect($results)->pluck('service_result')->sort()->values()->all());
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(5, $ticketType->fresh()->available_quantity);
        $this->assertSame(PaymentAttemptStatus::Expired, $payment->fresh()->status);
        $this->assertSame(2, $reservation->tickets()->where('status', TicketStatus::Cancelled->value)->count());
    }

    public function test_two_concurrent_entry_scans_create_one_log_and_one_state_transition(): void
    {
        $fixture = $this->makeScannableTicket();

        $results = $this->runConcurrently([
            $this->scanRequest('entry-one', $fixture),
            $this->scanRequest('entry-two', $fixture),
        ]);

        $this->assertSame([200, 409], $this->sortedStatuses($results));
        $this->assertSame(TicketAccessState::Inside, $fixture['ticket']->fresh()->access_state);
        $this->assertSame(1, AccessLog::query()->where('ticket_id', $fixture['ticket']->id)->count());
    }

    public function test_conflicting_entry_and_exit_scans_serialize_from_each_access_state(): void
    {
        // NOT_ENTERED permits ENTRY then optional EXIT; INSIDE permits EXIT then optional re-entry;
        // OUTSIDE permits ENTRY then optional EXIT. The other request order rejects its first invalid transition.
        foreach ([
            TicketAccessState::NotEntered,
            TicketAccessState::Inside,
            TicketAccessState::Outside,
        ] as $initialState) {
            $fixture = $this->makeScannableTicket($initialState);
            $startingLogs = $this->seedHistoryForState($fixture, $initialState);

            $results = $this->runConcurrently([
                $this->scanRequest('entry', $fixture, 'ENTRY'),
                $this->scanRequest('exit', $fixture, 'EXIT'),
            ]);

            $byLabel = collect($results)->keyBy('label');
            $this->assertContains($byLabel['entry']['status'], [200, 409]);
            $this->assertContains($byLabel['exit']['status'], [200, 409]);
            $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn ($result) => $result['status'] === 200)));

            $ticket = $fixture['ticket']->fresh();
            $actions = AccessLog::query()->where('ticket_id', $ticket->id)->orderBy('id')->pluck('action')->map(fn ($action) => $action->value)->all();
            $newActions = array_slice($actions, $startingLogs);
            $successCount = count(array_filter($results, fn ($result) => $result['status'] === 200));
            $this->assertCount($successCount, $newActions);
            $this->assertSame($ticket->access_state, $this->stateAfterActions($actions));

            if ($initialState === TicketAccessState::NotEntered) {
                $this->assertSame(200, $byLabel['entry']['status']);
                $this->assertContains($newActions, [['ENTRY'], ['ENTRY', 'EXIT']]);
                $this->assertSame(
                    count($newActions) === 1 ? TicketAccessState::Inside : TicketAccessState::Outside,
                    $ticket->access_state,
                );
                $this->assertSame(count($newActions) === 1 ? 409 : 200, $byLabel['exit']['status']);
            } elseif ($initialState === TicketAccessState::Inside) {
                $this->assertSame(200, $byLabel['exit']['status']);
                $this->assertContains($newActions, [['EXIT'], ['EXIT', 'ENTRY']]);
                $this->assertSame(
                    count($newActions) === 1 ? TicketAccessState::Outside : TicketAccessState::Inside,
                    $ticket->access_state,
                );
                $this->assertSame(count($newActions) === 1 ? 409 : 200, $byLabel['entry']['status']);
            } else {
                $this->assertSame(200, $byLabel['entry']['status']);
                $this->assertContains($newActions, [['ENTRY'], ['ENTRY', 'EXIT']]);
                $this->assertSame(
                    count($newActions) === 1 ? TicketAccessState::Inside : TicketAccessState::Outside,
                    $ticket->access_state,
                );
                $this->assertSame(count($newActions) === 1 ? 409 : 200, $byLabel['exit']['status']);
            }
        }
    }

    public function test_scan_and_event_suspension_serialize_and_post_suspension_scans_fail(): void
    {
        $fixture = $this->makeScannableTicket();
        [$admin, $adminToken] = $this->makeUser(UserRole::SuperAdmin);

        $results = $this->runConcurrently([
            $this->scanRequest('scan', $fixture),
            $this->request('suspend', 'PATCH', "/api/super-admin/events/{$fixture['event']->id}/suspend", [], $adminToken),
        ]);

        $byLabel = collect($results)->keyBy('label');
        $this->assertSame(200, $byLabel['suspend']['status']);
        $this->assertContains($byLabel['scan']['status'], [200, 409]);
        $this->assertSame(EventStatus::Suspended, $fixture['event']->fresh()->status);
        $logsAfterRace = AccessLog::query()->where('ticket_id', $fixture['ticket']->id)->count();
        $this->assertSame($byLabel['scan']['status'] === 200 ? 1 : 0, $logsAfterRace);

        $afterSuspension = $this->runConcurrently([$this->scanRequest('after-suspension', $fixture)]);
        $this->assertSame(409, $afterSuspension[0]['status']);
        $this->assertSame($logsAfterRace, AccessLog::query()->where('ticket_id', $fixture['ticket']->id)->count());
    }

    public function test_qr_rotation_and_scan_of_the_old_token_serialize_consistently(): void
    {
        $fixture = $this->makeScannableTicket();
        $oldToken = app(TicketCredentialService::class)->issueForOwner($fixture['client'], $fixture['ticket'])['qr_token'];

        $results = $this->runConcurrently([
            $this->request('rotate', 'POST', "/api/tickets/{$fixture['ticket']->id}/qr-credential", [], $fixture['clientToken'], [
                'method' => 'POST',
                'uri' => '/api/staff/scans',
                'payload' => ['action' => 'ENTRY', 'gate' => 'Integration Gate'],
                'token' => $fixture['staffToken'],
            ]),
            $this->scanRequest('scan-old', $fixture, 'ENTRY', $oldToken),
        ]);

        $byLabel = collect($results)->keyBy('label');
        $this->assertSame(201, $byLabel['rotate']['status']);
        $this->assertContains($byLabel['scan-old']['status'], [200, 404]);
        $this->assertNotSame(hash('sha256', $oldToken), $fixture['ticket']->fresh()->qr_token_hash);
        $this->assertContains($byLabel['rotate']['followup_statuses'], [[200], [409, 200]]);
        $this->assertSame(
            $byLabel['scan-old']['status'] === 200 ? [409, 200] : [200],
            $byLabel['rotate']['followup_statuses'],
        );
        $expectedState = $byLabel['scan-old']['status'] === 200 ? TicketAccessState::Outside : TicketAccessState::Inside;
        $expectedLogCount = $byLabel['scan-old']['status'] === 200 ? 2 : 1;
        $this->assertSame($expectedState, $fixture['ticket']->fresh()->access_state);
        $this->assertSame($expectedLogCount, AccessLog::query()->where('ticket_id', $fixture['ticket']->id)->count());

        $oldTokenAfterRotation = $this->runConcurrently([$this->scanRequest('old-after-rotation', $fixture, 'ENTRY', $oldToken)]);
        $this->assertSame(404, $oldTokenAfterRotation[0]['status']);
    }

    public function test_two_super_admins_cannot_review_one_application_concurrently(): void
    {
        [$client] = $this->makeUser(UserRole::Client);
        [$adminOne, $tokenOne] = $this->makeUser(UserRole::SuperAdmin);
        [$adminTwo, $tokenTwo] = $this->makeUser(UserRole::SuperAdmin);
        $application = $client->organizerRequests()->create([
            'organization_name' => 'Concurrency Org '.Str::ulid(),
            'description' => 'Organizer application used in a MySQL concurrency test.',
            'contact_email' => $client->email,
            'contact_phone' => '+212600000000',
            'status' => OrganizerRequestStatus::Pending,
        ]);

        $results = $this->runConcurrently([
            $this->request('approve', 'PATCH', "/api/super-admin/organizer-requests/{$application->id}/approve", [], $tokenOne),
            $this->request('reject', 'PATCH', "/api/super-admin/organizer-requests/{$application->id}/reject", ['rejection_reason' => 'Concurrent review test.'], $tokenTwo),
        ]);

        $byLabel = collect($results)->keyBy('label');
        $this->assertSame([200, 409], $this->sortedStatuses($results));
        $application->refresh();
        $client->refresh();

        if ($byLabel['approve']['status'] === 200) {
            $this->assertSame(OrganizerRequestStatus::Approved, $application->status);
            $this->assertSame($adminOne->id, $application->reviewed_by);
            $this->assertSame(UserRole::Organizer, $client->role);
        } else {
            $this->assertSame(OrganizerRequestStatus::Rejected, $application->status);
            $this->assertSame($adminTwo->id, $application->reviewed_by);
            $this->assertSame(UserRole::Client, $client->role);
        }
    }

    public function test_concurrent_super_admin_suspensions_cannot_remove_the_last_active_admin(): void
    {
        [$adminOne, $tokenOne] = $this->makeUser(UserRole::SuperAdmin);
        [$adminTwo, $tokenTwo] = $this->makeUser(UserRole::SuperAdmin);

        $results = $this->runConcurrently([
            $this->request(
                'suspend-admin-two',
                'PATCH',
                "/api/super-admin/users/{$adminTwo->id}/suspend",
                ['suspension_reason' => 'Concurrent administrator suspension test.'],
                $tokenOne,
            ),
            $this->request(
                'suspend-admin-one',
                'PATCH',
                "/api/super-admin/users/{$adminOne->id}/suspend",
                ['suspension_reason' => 'Concurrent administrator suspension test.'],
                $tokenTwo,
            ),
        ]);

        $this->assertSame([200, 401], $this->sortedStatuses($results));
        $this->assertSame(1, User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('account_status', AccountStatus::Active->value)
            ->whereIn('id', [$adminOne->id, $adminTwo->id])
            ->count());
        $this->assertSame(1, User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('account_status', AccountStatus::Suspended->value)
            ->whereIn('id', [$adminOne->id, $adminTwo->id])
            ->count());
    }

    public function test_concurrent_login_and_suspension_cannot_leave_a_usable_token_for_suspended_user(): void
    {
        [$admin, $adminToken] = $this->makeUser(UserRole::SuperAdmin);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);

        $results = $this->runConcurrently([
            $this->request('client-login', 'POST', '/api/login', [
                'email' => $client->email,
                'password' => 'integration-password',
            ], $adminToken),
            $this->request('client-suspension', 'PATCH', "/api/super-admin/users/{$client->id}/suspend", [
                'suspension_reason' => 'Concurrent login and suspension integration test.',
            ], $adminToken),
        ]);

        $byLabel = collect($results)->keyBy('label');
        $this->assertContains($byLabel['client-login']['status'], [200, 422]);
        $this->assertSame(200, $byLabel['client-suspension']['status']);
        $this->assertSame(AccountStatus::Suspended, $client->fresh()->account_status);
        $this->assertSame(0, $client->fresh()->tokens()->count());

        $this->withToken($clientToken)->getJson('/api/client/user')->assertUnauthorized();
    }

    private function makeUser(UserRole $role): array
    {
        $user = User::create([
            'name' => ucfirst(str_replace('_', ' ', $role->value)),
            'email' => 'mysql-concurrency-'.Str::uuid().'@example.test',
            'password' => 'integration-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return [$user->fresh(), $user->createToken('mysql-concurrency')->plainTextToken];
    }

    private function makeEvent(User $organizer): Event
    {
        $event = new Event([
            'title' => 'Concurrency event '.Str::ulid(),
            'description' => 'Event used only by the dedicated MySQL concurrency suite.',
            'category' => 'Music',
            'venue_name' => 'Integration Hall',
            'address' => '1 Integration Road',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(4),
            'capacity' => 100,
        ]);
        $event->slug = 'mysql-concurrency-'.Str::ulid();
        $event->status = EventStatus::Published;
        $organizer->events()->save($event);

        return $event->fresh();
    }

    private function makeTicketType(Event $event, int $quantity, ?int $available = null): TicketType
    {
        $ticketType = new TicketType([
            'name' => 'General '.Str::ulid(),
            'description' => 'MySQL concurrency test ticket type.',
            'price' => '10.00',
            'quantity' => $quantity,
            'sales_start_at' => null,
            'sales_end_at' => null,
            'is_active' => true,
        ]);
        $ticketType->forceFill(['available_quantity' => $available ?? $quantity]);
        $event->ticketTypes()->save($ticketType);

        return $ticketType->fresh();
    }

    private function makeReservation(User $client, Event $event, TicketType $ticketType, int $quantity): Reservation
    {
        $reservation = new Reservation([
            'reference' => 'EP-'.Str::upper((string) Str::ulid()),
            'total' => (string) (10 * $quantity).'.00',
            'status' => ReservationStatus::Confirmed,
            'payment_status' => 'unpaid',
        ]);
        $reservation->user()->associate($client);
        $reservation->event()->associate($event);
        $reservation->save();

        $item = new ReservationItem([
            'quantity' => $quantity,
            'unit_price' => '10.00',
            'subtotal' => (string) (10 * $quantity).'.00',
        ]);
        $item->ticketType()->associate($ticketType);
        $reservation->items()->save($item);

        for ($index = 0; $index < $quantity; $index++) {
            Ticket::forceCreate([
                'reservation_id' => $reservation->id,
                'reservation_item_id' => $item->id,
                'ticket_type_id' => $ticketType->id,
                'reference' => 'TKT-'.Str::upper((string) Str::ulid()),
                'status' => TicketStatus::Valid->value,
                'qr_token_hash' => hash('sha256', Str::random(64)),
                'access_state' => TicketAccessState::NotEntered->value,
            ]);
        }

        return $reservation->fresh();
    }

    private function makePendingReservation(User $client, Event $event, TicketType $ticketType, int $quantity, bool $expired = false): Reservation
    {
        $reservation = $this->makeReservation($client, $event, $ticketType, $quantity);
        $reservation->forceFill([
            'status' => ReservationStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'payment_expires_at' => $expired ? now()->subMinute() : now()->addMinutes(15),
        ])->save();
        $reservation->tickets()->update(['status' => TicketStatus::Pending->value, 'qr_token_hash' => null]);

        return $reservation->fresh();
    }

    private function createPaymentAttempt(User $client, Reservation $reservation): Payment
    {
        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/reservations/{$reservation->id}/payments")
            ->assertCreated();
        $paymentId = $response->json('data.id');
        $this->assertIsInt($paymentId);

        return Payment::query()->findOrFail($paymentId);
    }

    private function makeScannableTicket(TicketAccessState $state = TicketAccessState::NotEntered): array
    {
        [$organizer] = $this->makeUser(UserRole::Organizer);
        [$client, $clientToken] = $this->makeUser(UserRole::Client);
        [$staff, $staffToken] = $this->makeUser(UserRole::Staff);
        $event = $this->makeEvent($organizer);
        $ticketType = $this->makeTicketType($event, 10, 9);
        $reservation = $this->makeReservation($client, $event, $ticketType, 1);
        $reservation->forceFill(['payment_status' => 'paid'])->save();
        $ticket = $reservation->tickets()->firstOrFail();
        $plainToken = Str::random(43);
        $ticket->forceFill([
            'qr_token_hash' => hash('sha256', $plainToken),
            'access_state' => $state,
        ])->save();
        EventStaffAssignment::create([
            'event_id' => $event->id,
            'staff_user_id' => $staff->id,
            'assigned_by' => $organizer->id,
        ]);

        return compact('organizer', 'client', 'clientToken', 'staff', 'staffToken', 'event', 'ticketType', 'reservation', 'ticket', 'plainToken');
    }

    private function seedHistoryForState(array $fixture, TicketAccessState $state): int
    {
        if ($state === TicketAccessState::NotEntered) {
            return 0;
        }

        $this->appendLog($fixture, AccessAction::Entry);
        if ($state === TicketAccessState::Outside) {
            $this->appendLog($fixture, AccessAction::Exit);
        }

        return AccessLog::query()->where('ticket_id', $fixture['ticket']->id)->count();
    }

    private function appendLog(array $fixture, AccessAction $action): void
    {
        $log = new AccessLog(['action' => $action, 'gate' => 'Integration Gate', 'scanned_at' => now()]);
        $log->ticket()->associate($fixture['ticket']);
        $log->event()->associate($fixture['event']);
        $log->staff()->associate($fixture['staff']);
        $log->save();
    }

    private function stateAfterActions(array $actions): TicketAccessState
    {
        $state = TicketAccessState::NotEntered;
        foreach ($actions as $action) {
            if ($action === AccessAction::Entry->value) {
                $this->assertNotSame(TicketAccessState::Inside, $state, 'An ENTRY log cannot occur while the ticket is already inside.');
                $state = TicketAccessState::Inside;
            } elseif ($action === AccessAction::Exit->value) {
                $this->assertSame(TicketAccessState::Inside, $state, 'An EXIT log requires a preceding valid ENTRY.');
                $state = TicketAccessState::Outside;
            } else {
                $this->fail('Unknown access action in the persisted history.');
            }
        }

        return $state;
    }

    private function request(string $label, string $method, string $uri, array $payload, string $token, ?array $followup = null): array
    {
        if ($token === '') {
            throw new RuntimeException("Concurrent request '{$label}' is missing its bearer token.");
        }

        $request = compact('label', 'method', 'uri', 'payload', 'token');
        if ($followup !== null) {
            $request['followup'] = $followup;
        }

        return $request;
    }

    private function expireRequest(string $label, Reservation $reservation, string $token): array
    {
        return $this->request(
            $label,
            'POST',
            "/api/__integration/reservations/{$reservation->id}/expire",
            [],
            $token,
        );
    }

    private function scanRequest(string $label, array $fixture, string $action = 'ENTRY', ?string $token = null): array
    {
        return $this->request($label, 'POST', '/api/staff/scans', [
            'qr_token' => $token ?? $fixture['plainToken'],
            'action' => $action,
            'gate' => 'Integration Gate',
        ], $fixture['staffToken']);
    }

    /**
     * Start distinct Laravel worker processes, wait until both are ready, then release the barrier.
     * Each worker has an independent MySQL connection and sends one real HTTP-kernel request.
     *
     * @param  list<array{label:string,method:string,uri:string,payload:array,token:string}>  $requests
     * @return list<array{label:string,status:int,followup_statuses:?array,pid:int,connection_id:int}>
     */
    private function runConcurrently(array $requests): array
    {
        if ($requests === []) {
            throw new RuntimeException('At least one concurrent request is required.');
        }

        $labels = [];
        $requestByLabel = [];
        foreach ($requests as $request) {
            foreach (['label', 'method', 'uri', 'payload', 'token'] as $field) {
                if (! array_key_exists($field, $request)) {
                    throw new RuntimeException("Concurrent request is missing required field '{$field}'.");
                }
            }
            if (! is_string($request['token']) || $request['token'] === '') {
                throw new RuntimeException("Concurrent request '{$request['label']}' has no bearer token.");
            }
            if (
                ! is_string($request['method'])
                || ! in_array(strtoupper($request['method']), ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)
                || ! is_array($request['payload'])
                || ! is_string($request['uri'])
                || ! str_starts_with($request['uri'], '/api/')
            ) {
                throw new RuntimeException("Concurrent request '{$request['label']}' has invalid route or payload data.");
            }
            if (! is_string($request['label']) || ! preg_match('/^[a-z0-9-]+$/i', $request['label']) || isset($labels[$request['label']])) {
                throw new RuntimeException('Concurrent request labels must be unique simple identifiers.');
            }
            $labels[$request['label']] = true;
            $requestByLabel[$request['label']] = $request;

            if (isset($request['followup'])) {
                $followup = $request['followup'];
                foreach (['method', 'uri', 'payload', 'token'] as $field) {
                    if (! array_key_exists($field, $followup)) {
                        throw new RuntimeException("Concurrent request '{$request['label']}' has an incomplete follow-up request.");
                    }
                }
                if (
                    ! is_string($followup['method'])
                    || ! in_array(strtoupper($followup['method']), ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)
                    || ! is_string($followup['token'])
                    || $followup['token'] === ''
                    || ! is_array($followup['payload'])
                    || ! is_string($followup['uri'])
                    || ! str_starts_with($followup['uri'], '/api/')
                ) {
                    throw new RuntimeException("Concurrent request '{$request['label']}' has invalid follow-up request data.");
                }
            }
        }

        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eventpass-concurrency-'.bin2hex(random_bytes(12));
        if (! mkdir($barrier, 0700)) {
            throw new RuntimeException('Could not create the temporary concurrency barrier.');
        }

        $processes = [];
        try {
            foreach ($requests as $request) {
                $environment = [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'mysql',
                    'DB_DATABASE' => 'eventpass_test',
                    'DB_URL' => '',
                    'DB_HOST' => (string) config('database.connections.mysql.host'),
                    'DB_PORT' => (string) config('database.connections.mysql.port'),
                    'DB_USERNAME' => (string) config('database.connections.mysql.username'),
                    'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
                    'DB_SOCKET' => (string) config('database.connections.mysql.unix_socket'),
                    'EVENTPASS_BARRIER_DIRECTORY' => $barrier,
                    'EVENTPASS_WORKER_LABEL' => $request['label'],
                ];
                $process = new Process([PHP_BINARY, base_path('tests/Support/mysql-concurrent-request.php')], base_path(), $environment, null, 60);
                $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
                $process->start();
                $processes[$request['label']] = $process;
            }

            $deadline = microtime(true) + 35;
            while (count(glob($barrier.DIRECTORY_SEPARATOR.'*.ready') ?: []) < count($requests)) {
                foreach ($processes as $label => $process) {
                    if (! $process->isRunning() && ! is_file($barrier.DIRECTORY_SEPARATOR.$label.'.ready')) {
                        throw new RuntimeException('Concurrent worker failed before reaching the request barrier.');
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Concurrent workers did not reach the barrier in time.');
                }
                usleep(10000);
            }

            file_put_contents($barrier.DIRECTORY_SEPARATOR.'go', 'go', LOCK_EX);
            $results = [];
            foreach ($processes as $label => $process) {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException('Concurrent worker failed; inspect the worker exit status without printing credentials.');
                }

                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                if (($result['label'] ?? null) !== $label || ! isset($result['status'], $result['pid'], $result['connection_id'])) {
                    throw new RuntimeException('Concurrent worker returned an invalid result envelope.');
                }
                if (isset($requestByLabel[$label]['followup']) && ! is_array($result['followup_statuses'] ?? null)) {
                    throw new RuntimeException("Concurrent worker '{$label}' did not complete its required follow-up.");
                }
                $results[] = $result;
            }

            if (count($results) > 1) {
                $this->assertCount(count($results), array_unique(array_column($results, 'pid')));
                $this->assertCount(count($results), array_unique(array_column($results, 'connection_id')));
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($barrier.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($barrier);
        }
    }

    private function sortedStatuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }
}
