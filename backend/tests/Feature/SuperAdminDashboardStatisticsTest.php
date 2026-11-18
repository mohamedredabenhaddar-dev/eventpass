<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminDashboardStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_super_admins_can_view_dashboard_statistics(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/dashboard/statistics')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'users' => ['total', 'by_role', 'by_account_status'],
                    'events' => ['total', 'by_status', 'published_upcoming'],
                    'reservations' => ['total', 'by_status', 'by_payment_status', 'confirmed_paid'],
                    'tickets' => ['total', 'by_status', 'valid_for_confirmed_paid_reservations'],
                    'demo_payments' => ['demo_only', 'attempts_by_status', 'successful_simulations'],
                ],
            ])
            ->assertJsonPath('data.users.by_role.super_admin', 1);

        foreach ([UserRole::Client, UserRole::Organizer, UserRole::Staff] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/super-admin/dashboard/statistics')
                ->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/super-admin/dashboard/statistics')->assertUnauthorized();
    }

    public function test_suspended_super_admin_cannot_view_dashboard_statistics(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $admin->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->actingAs($admin->fresh(), 'sanctum')
            ->getJson('/api/super-admin/dashboard/statistics')
            ->assertUnauthorized();
    }

    public function test_empty_datasets_return_zeroes_for_every_supported_enum_value(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/dashboard/statistics')
            ->assertOk()
            ->assertJsonPath('data.users.total', 1)
            ->assertJsonPath('data.users.by_role.organizer', 0)
            ->assertJsonPath('data.users.by_role.staff', 0)
            ->assertJsonPath('data.users.by_role.client', 0)
            ->assertJsonPath('data.users.by_account_status.active', 1)
            ->assertJsonPath('data.users.by_account_status.suspended', 0)
            ->assertJsonPath('data.events.total', 0)
            ->assertJsonPath('data.reservations.total', 0)
            ->assertJsonPath('data.tickets.total', 0)
            ->assertJsonPath('data.demo_payments.demo_only', true)
            ->assertJsonPath('data.demo_payments.successful_simulations', 0);

        foreach (UserRole::cases() as $role) {
            $response->assertJsonPath("data.users.by_role.{$role->value}", $role === UserRole::SuperAdmin ? 1 : 0);
        }
        foreach (AccountStatus::cases() as $status) {
            $response->assertJsonPath(
                "data.users.by_account_status.{$status->value}",
                $status === AccountStatus::Active ? 1 : 0,
            );
        }
        foreach (EventStatus::cases() as $status) {
            $response->assertJsonPath("data.events.by_status.{$status->value}", 0);
        }
        foreach (ReservationStatus::cases() as $status) {
            $response->assertJsonPath("data.reservations.by_status.{$status->value}", 0);
        }
        foreach (PaymentStatus::cases() as $status) {
            $response->assertJsonPath("data.reservations.by_payment_status.{$status->value}", 0);
        }
        foreach (TicketStatus::cases() as $status) {
            $response->assertJsonPath("data.tickets.by_status.{$status->value}", 0);
        }
        foreach (PaymentAttemptStatus::cases() as $status) {
            $response->assertJsonPath("data.demo_payments.attempts_by_status.{$status->value}", 0);
        }
    }

    public function test_dashboard_aggregates_distinct_business_records_without_exposing_private_data(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $organizer = $this->makeUser(UserRole::Organizer, 'organizer@example.test');
        $client = $this->makeUser(UserRole::Client, 'private-client@example.test');
        $staff = $this->makeUser(UserRole::Staff, 'staff@example.test');
        $staff->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->makeEvent($organizer, 'future-published', EventStatus::Published, now()->addDay());
        $this->makeEvent($organizer, 'past-published', EventStatus::Published, now()->subDay());
        $this->makeEvent($organizer, 'draft', EventStatus::Draft, now()->addDays(2));
        $this->makeEvent($organizer, 'suspended', EventStatus::Suspended, now()->addDays(3));
        $this->makeEvent($organizer, 'cancelled', EventStatus::Cancelled, now()->addDays(4));

        $event = Event::query()->where('slug', 'future-published')->firstOrFail();
        $ticketType = TicketType::forceCreate([
            'event_id' => $event->id,
            'name' => 'General',
            'description' => null,
            'price' => 25,
            'quantity' => 20,
            'available_quantity' => 15,
            'is_active' => true,
        ]);

        $paid = $this->makeReservation($client, $event, ReservationStatus::Confirmed, PaymentStatus::Paid, 'paid');
        $unpaid = $this->makeReservation($client, $event, ReservationStatus::Confirmed, PaymentStatus::Unpaid, 'unpaid');
        $pending = $this->makeReservation($client, $event, ReservationStatus::PendingPayment, PaymentStatus::Pending, 'pending');
        $cancelled = $this->makeReservation($client, $event, ReservationStatus::Cancelled, PaymentStatus::Expired, 'cancelled');

        $this->makeTicket($paid, $ticketType, TicketStatus::Valid, 'TICKET-PAID-1');
        $this->makeTicket($paid, $ticketType, TicketStatus::Valid, 'TICKET-PAID-2');
        $this->makeTicket($unpaid, $ticketType, TicketStatus::Valid, 'TICKET-UNPAID');
        $this->makeTicket($pending, $ticketType, TicketStatus::Valid, 'TICKET-PENDING');
        $this->makeTicket($cancelled, $ticketType, TicketStatus::Cancelled, 'TICKET-CANCELLED');

        $this->makePayment($paid, PaymentAttemptStatus::Failed, 'demo');
        $this->makePayment($paid, PaymentAttemptStatus::Succeeded, 'demo');
        $this->makePayment($unpaid, PaymentAttemptStatus::Pending, 'demo');
        $this->makePayment($pending, PaymentAttemptStatus::Expired, 'demo');
        $this->makePayment($paid, PaymentAttemptStatus::Succeeded, 'external');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/dashboard/statistics')
            ->assertOk()
            ->assertJsonPath('data.users.total', 4)
            ->assertJsonPath('data.users.by_role.super_admin', 1)
            ->assertJsonPath('data.users.by_role.organizer', 1)
            ->assertJsonPath('data.users.by_role.client', 1)
            ->assertJsonPath('data.users.by_role.staff', 1)
            ->assertJsonPath('data.users.by_account_status.active', 3)
            ->assertJsonPath('data.users.by_account_status.suspended', 1)
            ->assertJsonPath('data.events.total', 5)
            ->assertJsonPath('data.events.by_status.published', 2)
            ->assertJsonPath('data.events.by_status.draft', 1)
            ->assertJsonPath('data.events.by_status.suspended', 1)
            ->assertJsonPath('data.events.by_status.cancelled', 1)
            ->assertJsonPath('data.events.published_upcoming', 1)
            ->assertJsonPath('data.reservations.total', 4)
            ->assertJsonPath('data.reservations.by_status.confirmed', 2)
            ->assertJsonPath('data.reservations.by_status.pending_payment', 1)
            ->assertJsonPath('data.reservations.by_status.cancelled', 1)
            ->assertJsonPath('data.reservations.confirmed_paid', 1)
            ->assertJsonPath('data.tickets.total', 5)
            ->assertJsonPath('data.tickets.by_status.valid', 4)
            ->assertJsonPath('data.tickets.by_status.cancelled', 1)
            ->assertJsonPath('data.tickets.valid_for_confirmed_paid_reservations', 2)
            ->assertJsonPath('data.demo_payments.demo_only', true)
            ->assertJsonPath('data.demo_payments.attempts_by_status.failed', 1)
            ->assertJsonPath('data.demo_payments.attempts_by_status.succeeded', 1)
            ->assertJsonPath('data.demo_payments.attempts_by_status.pending', 1)
            ->assertJsonPath('data.demo_payments.attempts_by_status.expired', 1)
            ->assertJsonPath('data.demo_payments.successful_simulations', 1)
            ->assertJsonMissingPath('data.demo_payments.revenue')
            ->assertJsonMissingPath('data.users.password')
            ->assertJsonMissingPath('data.users.email')
            ->assertJsonMissingPath('data.tickets.references');

        $this->assertStringNotContainsString('private-client@example.test', $response->getContent());
        $this->assertStringNotContainsString('TICKET-PAID-1', $response->getContent());
    }

    private function makeUser(UserRole $role, ?string $email = null): User
    {
        $user = User::create([
            'name' => ucfirst(str_replace('_', ' ', $role->value)),
            'email' => $email ?? uniqid($role->value.'-', true).'@example.test',
            'password' => 'secret-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function makeEvent(User $organizer, string $slug, EventStatus $status, $startAt): Event
    {
        return Event::forceCreate([
            'organizer_id' => $organizer->id,
            'title' => ucfirst($slug),
            'slug' => $slug,
            'description' => 'Statistics fixture.',
            'category' => 'Music',
            'venue_name' => 'Sample Hall',
            'address' => '1 Main Road',
            'city' => 'Rabat',
            'country' => 'Morocco',
            'start_at' => $startAt,
            'end_at' => (clone $startAt)->addHours(2),
            'capacity' => 20,
            'status' => $status,
        ]);
    }

    private function makeReservation(
        User $client,
        Event $event,
        ReservationStatus $status,
        PaymentStatus $paymentStatus,
        string $reference,
    ): Reservation {
        return Reservation::forceCreate([
            'user_id' => $client->id,
            'event_id' => $event->id,
            'reference' => 'RES-'.$reference,
            'total' => 25,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'payment_expires_at' => null,
        ]);
    }

    private function makeTicket(Reservation $reservation, TicketType $ticketType, TicketStatus $status, string $reference): void
    {
        $item = ReservationItem::query()
            ->where('reservation_id', $reservation->id)
            ->where('ticket_type_id', $ticketType->id)
            ->first();

        if ($item === null) {
            $item = ReservationItem::forceCreate([
                'reservation_id' => $reservation->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
                'unit_price' => 25,
                'subtotal' => 25,
            ]);
        } else {
            $item->forceFill([
                'quantity' => $item->quantity + 1,
                'subtotal' => ($item->quantity + 1) * 25,
            ])->save();
        }

        Ticket::forceCreate([
            'reservation_id' => $reservation->id,
            'reservation_item_id' => $item->id,
            'ticket_type_id' => $ticketType->id,
            'reference' => $reference,
            'status' => $status,
            'qr_token_hash' => null,
            'access_state' => 'NOT_ENTERED',
        ]);
    }

    private function makePayment(Reservation $reservation, PaymentAttemptStatus $status, string $method): void
    {
        Payment::forceCreate([
            'reservation_id' => $reservation->id,
            'payment_reference' => 'PAY-'.uniqid('', true),
            'amount' => 25,
            'currency' => 'MAD',
            'status' => $status,
            'payment_method' => $method,
            'paid_at' => null,
            'failure_reason' => null,
        ]);
    }
}
