<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketAccessState;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessLog;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private array $qrTokens = [];

    public function test_ticket_supports_entry_exit_and_reentry_with_accurate_statistics(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket] = $this->makeTicketFixture();
        $staff = $this->makeUser(UserRole::Staff);
        $this->assign($event, $staff, $organizer);
        $otherNeverUsed = $this->makeTicket($reservation, $ticketType, null, 'TKT-NEVER-USED');

        $this->scan($staff, $ticket, 'ENTRY')->assertOk()->assertJsonPath('data.access_state', 'INSIDE');
        $this->scan($staff, $ticket, 'EXIT')->assertOk()->assertJsonPath('data.access_state', 'OUTSIDE');
        $this->scan($staff, $ticket, 'ENTRY')->assertOk()->assertJsonPath('data.access_state', 'INSIDE');

        $this->assertSame(TicketAccessState::Inside, $ticket->fresh()->access_state);
        $this->assertSame(3, AccessLog::where('ticket_id', $ticket->id)->count());
        $report = $this->actingAs($organizer, 'sanctum')->getJson("/api/organizer/events/{$event->id}/access")
            ->assertOk()->assertJsonPath('data.statistics.attendees_inside', 1)
            ->assertJsonPath('data.statistics.unique_tickets_entered', 1)
            ->assertJsonPath('data.statistics.successful_entries', 2)
            ->assertJsonPath('data.statistics.successful_exits', 1)
            ->assertJsonPath('data.history.meta.total', 3);
        $this->assertDatabaseHas('tickets', ['id' => $otherNeverUsed->id, 'access_state' => 'NOT_ENTERED']);
    }

    public function test_duplicate_entry_exit_before_entry_and_exit_twice_are_rejected_without_logs(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket] = $this->makeTicketFixture();
        $staff = $this->makeUser(UserRole::Staff);
        $this->assign($event, $staff, $organizer);

        $this->scan($staff, $ticket, 'EXIT')->assertConflict()->assertJsonPath('message', 'This ticket has not entered yet.');
        $this->scan($staff, $ticket, 'ENTRY')->assertOk();
        $this->scan($staff, $ticket, 'ENTRY')->assertConflict()->assertJsonPath('message', 'This ticket is already inside.');
        $this->scan($staff, $ticket, 'EXIT')->assertOk();
        $this->scan($staff, $ticket, 'EXIT')->assertConflict()->assertJsonPath('message', 'This ticket is already outside.');
        $this->assertSame(2, AccessLog::where('ticket_id', $ticket->id)->count());
    }

    public function test_invalid_qr_cancelled_ticket_and_unassigned_staff_are_rejected(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket, $client] = $this->makeTicketFixture();
        $staff = $this->makeUser(UserRole::Staff);
        $this->scan($staff, $ticket, 'ENTRY')->assertForbidden();
        $this->assign($event, $staff, $organizer);
        $this->actingAs($staff, 'sanctum')->postJson('/api/staff/scans', [
            'qr_token' => 'A'.str_repeat('a', 42), 'action' => 'ENTRY',
        ])->assertNotFound()->assertJsonPath('message', 'Invalid QR credential.');

        $ticket->status = TicketStatus::Cancelled;
        $ticket->save();
        $this->scan($staff, $ticket, 'ENTRY')->assertConflict()->assertJsonPath('message', 'This ticket has been cancelled or is invalid.');
        $this->actingAs($client, 'sanctum')->postJson("/api/tickets/{$ticket->id}/qr-credential")->assertConflict();
        $this->assertSame(0, AccessLog::count());
    }

    public function test_staff_cannot_scan_or_view_an_event_they_are_not_assigned_to(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket] = $this->makeTicketFixture();
        $otherEvent = $this->makeEvent($organizer, 'Second Event');
        $staff = $this->makeUser(UserRole::Staff);
        $this->assign($otherEvent, $staff, $organizer);

        $this->scan($staff, $ticket, 'ENTRY')->assertForbidden()->assertJsonPath('message', 'You are not assigned to this event.');
        $this->actingAs($staff, 'sanctum')->getJson("/api/staff/events/{$event->id}/access")->assertForbidden();
        $this->assertSame(0, AccessLog::count());
    }

    public function test_only_event_owner_can_assign_staff_and_duplicates_are_blocked(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $otherOrganizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);
        $otherEvent = $this->makeEvent($otherOrganizer, 'Other event');
        $staff = $this->makeUser(UserRole::Staff);
        $client = $this->makeUser(UserRole::Client);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/organizer/events/{$event->id}/staff", ['staff_user_id' => $client->id])->assertUnprocessable();
        $this->postJson("/api/organizer/events/{$event->id}/staff", ['staff_user_id' => $staff->id])->assertCreated()->assertJsonPath('data.staff.id', $staff->id);
        $this->postJson("/api/organizer/events/{$event->id}/staff", ['staff_user_id' => $staff->id])->assertConflict();
        $this->actingAs($otherOrganizer, 'sanctum')->getJson("/api/organizer/events/{$event->id}/staff")->assertNotFound();
        $this->postJson("/api/organizer/events/{$event->id}/staff", ['staff_user_id' => $staff->id])->assertNotFound();

        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->actingAs($admin, 'sanctum')->getJson("/api/super-admin/events/{$event->id}/staff")
            ->assertOk()->assertJsonPath('data.0.staff.id', $staff->id);
        $this->assertSame(0, $otherEvent->staffAssignments()->count());
    }

    public function test_qr_credentials_are_owner_only_rotated_and_hash_only_in_database(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket, $client] = $this->makeTicketFixture();
        $otherClient = $this->makeUser(UserRole::Client);

        $this->actingAs($otherClient, 'sanctum')->postJson("/api/tickets/{$ticket->id}/qr-credential")->assertNotFound();
        $first = $this->actingAs($client, 'sanctum')->postJson("/api/tickets/{$ticket->id}/qr-credential")
            ->assertCreated()->assertJsonStructure(['data' => ['ticket_reference', 'qr_token']]);
        $firstToken = $first->json('data.qr_token');
        $storedHash = $ticket->fresh()->qr_token_hash;
        $this->assertSame(hash('sha256', $firstToken), $storedHash);
        $this->assertNotSame($firstToken, $storedHash);
        $this->assertArrayNotHasKey('qr_token_hash', $this->actingAs($client, 'sanctum')->getJson("/api/reservations/{$reservation->id}")->assertOk()->json('data.items.0.tickets.0'));

        $second = $this->postJson("/api/tickets/{$ticket->id}/qr-credential")->assertCreated()->json('data.qr_token');
        $this->assertNotSame($firstToken, $second);
        $staff = $this->makeUser(UserRole::Staff);
        $this->assign($event, $staff, $organizer);
        $this->actingAs($staff, 'sanctum')->postJson('/api/staff/scans', ['qr_token' => $firstToken, 'action' => 'ENTRY'])->assertNotFound();
        $this->postJson('/api/staff/scans', ['qr_token' => $second, 'action' => 'ENTRY'])->assertOk();
    }

    public function test_published_events_allow_qr_credential_issuance(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket, $client] = $this->makeTicketFixture();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/tickets/{$ticket->id}/qr-credential")
            ->assertCreated()
            ->assertJsonStructure(['data' => ['ticket_reference', 'qr_token']]);
    }

    public function test_unpublished_events_reject_qr_issuance_without_rotating_existing_credentials(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket, $client] = $this->makeTicketFixture();
        $existingHash = hash('sha256', 'existing-credential');
        $ticket->forceFill(['qr_token_hash' => $existingHash])->save();

        foreach ([EventStatus::Draft, EventStatus::Suspended, EventStatus::Cancelled] as $status) {
            $event->status = $status;
            $event->save();

            $this->actingAs($client, 'sanctum')
                ->postJson("/api/tickets/{$ticket->id}/qr-credential")
                ->assertConflict()
                ->assertJsonPath('message', 'QR credentials are only available for published events.')
                ->assertJsonMissingPath('data.qr_token');

            $this->assertSame($existingHash, $ticket->fresh()->qr_token_hash);
        }
    }

    public function test_only_published_events_accept_scans_and_rejections_preserve_state_and_history(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket] = $this->makeTicketFixture();
        $staff = $this->makeUser(UserRole::Staff);
        $this->assign($event, $staff, $organizer);

        $this->scan($staff, $ticket, 'ENTRY')->assertOk();
        $this->assertSame(TicketAccessState::Inside, $ticket->fresh()->access_state);
        $this->assertSame(1, AccessLog::where('ticket_id', $ticket->id)->count());

        foreach ([EventStatus::Draft, EventStatus::Suspended, EventStatus::Cancelled] as $status) {
            $event->status = $status;
            $event->save();

            foreach (['ENTRY', 'EXIT'] as $action) {
                $this->scan($staff, $ticket, $action)
                    ->assertConflict()
                    ->assertJsonPath('message', 'This event is not currently accepting scans.');

                $this->assertSame(TicketAccessState::Inside, $ticket->fresh()->access_state);
                $this->assertSame(1, AccessLog::where('ticket_id', $ticket->id)->count());
            }
        }
    }

    public function test_new_reservation_has_no_qr_until_paid_and_credentials_persist_only_hashes(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $type = new TicketType(['name' => 'Pair', 'price' => '12.00', 'quantity' => 5, 'is_active' => true]);
        $type->forceFill(['available_quantity' => 5]);
        $event->ticketTypes()->save($type);

        $response = $this->actingAs($client, 'sanctum')->postJson("/api/events/{$event->id}/reservations", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonMissingPath('qr_credentials')->assertJsonPath('data.status', 'pending_payment');

        $reservationId = $response->json('data.id');
        $this->assertSame(2, Ticket::where('reservation_id', $reservationId)->where('status', TicketStatus::Pending->value)->count());
        $this->postJson('/api/tickets/'.Ticket::where('reservation_id', $reservationId)->value('id').'/qr-credential')->assertConflict();
        $attempt = $this->postJson("/api/reservations/{$reservationId}/payments")->assertCreated()->json('data');
        $this->postJson("/api/payments/{$attempt['id']}/simulate-success")->assertOk();

        foreach (Ticket::where('reservation_id', $reservationId)->get() as $ticket) {
            $credential = $this->postJson("/api/tickets/{$ticket->id}/qr-credential")->assertCreated()->json('data');
            $ticket->refresh();
            $this->assertSame(hash('sha256', $credential['qr_token']), $ticket->qr_token_hash);
            $this->assertDatabaseMissing('tickets', ['id' => $ticket->id, 'qr_token_hash' => $credential['qr_token']]);
        }
    }

    public function test_access_report_and_scan_are_role_protected_and_access_logs_are_append_only(): void
    {
        [$organizer, $event, $ticketType, $reservation, $ticket] = $this->makeTicketFixture();
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $client = $this->makeUser(UserRole::Client);
        $staff = $this->makeUser(UserRole::Staff);
        $this->assign($event, $staff, $organizer);
        $this->scan($staff, $ticket, 'ENTRY')->assertOk();

        $this->actingAs($client, 'sanctum')->postJson('/api/staff/scans', [])->assertForbidden();
        $this->getJson("/api/super-admin/events/{$event->id}/access")->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson("/api/super-admin/events/{$event->id}/access")
            ->assertOk()->assertJsonPath('data.statistics.unique_tickets_entered', 1);
        $log = AccessLog::firstOrFail();
        try {
            $log->gate = 'changed';
            $log->save();
            $this->fail('Expected access log updates to be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Access logs are append-only.', $exception->getMessage());
        }
        try {
            $log->delete();
            $this->fail('Expected access log deletion to be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Access logs are append-only.', $exception->getMessage());
        }
        $this->assertSame(1, AccessLog::count());
    }

    private function scan(User $staff, Ticket $ticket, string $action, ?string $token = null)
    {
        return $this->actingAs($staff, 'sanctum')->postJson('/api/staff/scans', [
            'qr_token' => $token ?? $this->qrTokens[$ticket->id],
            'action' => $action,
            'gate' => 'North Gate',
        ]);
    }

    private function makeTicketFixture(): array
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer);
        $type = new TicketType(['name' => 'General', 'price' => '10.00', 'quantity' => 10, 'is_active' => true]);
        $type->forceFill(['available_quantity' => 9]);
        $event->ticketTypes()->save($type);
        $reservation = new Reservation(['reference' => 'EP-'.strtoupper(bin2hex(random_bytes(8))), 'total' => '10.00', 'status' => ReservationStatus::Confirmed, 'payment_status' => 'paid']);
        $reservation->user()->associate($client);
        $reservation->event()->associate($event);
        $reservation->save();
        $item = new ReservationItem(['quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00']);
        $item->ticketType()->associate($type);
        $reservation->items()->save($item);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ticket = Ticket::forceCreate([
            'reservation_id' => $reservation->id,
            'reservation_item_id' => $item->id,
            'ticket_type_id' => $type->id,
            'reference' => 'TKT-'.strtoupper(bin2hex(random_bytes(8))),
            'status' => TicketStatus::Valid,
            'qr_token_hash' => hash('sha256', $token),
            'access_state' => TicketAccessState::NotEntered,
        ]);
        $ticket = $ticket->fresh();
        $this->qrTokens[$ticket->id] = $token;

        return [$organizer, $event->fresh(), $type->fresh(), $reservation->fresh(), $ticket, $client->fresh()];
    }

    private function makeTicket(Reservation $reservation, TicketType $type, ?string $token, string $reference): Ticket
    {
        $ticket = Ticket::forceCreate([
            'reservation_id' => $reservation->id,
            'reservation_item_id' => $reservation->items()->firstOrFail()->id,
            'ticket_type_id' => $type->id,
            'reference' => $reference,
            'status' => TicketStatus::Valid,
            'qr_token_hash' => $token ? hash('sha256', $token) : null,
            'access_state' => TicketAccessState::NotEntered,
        ]);

        return $ticket->fresh();
    }

    private function assign(Event $event, User $staff, User $organizer): void
    {
        EventStaffAssignment::create(['staff_user_id' => $staff->id, 'assigned_by' => $organizer->id, 'event_id' => $event->id]);
    }

    private function makeEvent(User $organizer, string $title = 'Access Test Event'): Event
    {
        static $sequence = 0;
        $sequence++;
        $event = new Event([
            'title' => $title, 'description' => 'Event for access tests.', 'category' => 'Music',
            'venue_name' => 'Test Hall', 'address' => '1 Test Street', 'city' => 'Casablanca', 'country' => 'Morocco',
            'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHours(4), 'capacity' => 100,
        ]);
        $event->slug = 'access-test-'.$sequence;
        $event->status = EventStatus::Published;
        $organizer->events()->save($event);

        return $event->fresh();
    }

    private function makeUser(UserRole $role): User
    {
        static $sequence = 0;
        $sequence++;
        $user = User::create(['name' => ucfirst($role->value), 'email' => "access-{$role->value}-{$sequence}@example.test", 'password' => 'secret-password']);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }
}
