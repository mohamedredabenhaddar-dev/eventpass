<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DemoPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_demo_payment_confirms_reservation_and_activates_tickets(): void
    {
        [$client, $event, $type, $reservation] = $this->reserveFixture();
        $reservation->load('tickets');
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $this->assertSame(TicketStatus::Pending, $reservation->tickets->first()->status);
        $this->actingAs($client, 'sanctum')->postJson('/api/tickets/'.$reservation->tickets->first()->id.'/qr-credential')->assertConflict();

        $attempt = $this->actingAs($client, 'sanctum')->postJson("/api/reservations/{$reservation->id}/payments", ['amount' => '0.01'])
            ->assertCreated()->assertJsonPath('data.amount', '20.00')->assertJsonPath('data.mode', 'DEMO')->json('data');
        $this->postJson("/api/reservations/{$reservation->id}/payments")->assertCreated()->assertJsonPath('data.id', $attempt['id']);
        $this->postJson("/api/payments/{$attempt['id']}/simulate-success")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.reservation_reference', $reservation->reference)
            ->assertJsonPath('data.reservation_status', 'confirmed')->assertJsonPath('data.reservation_payment_status', 'paid')
            ->assertJsonPath('data.retry_allowed', false);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame('paid', $reservation->fresh()->payment_status->value);
        $this->assertSame(TicketStatus::Valid, $reservation->tickets()->first()->fresh()->status);
        $this->postJson("/api/payments/{$attempt['id']}/simulate-success")->assertOk();
        $this->assertSame(1, Payment::where('reservation_id', $reservation->id)->where('status', 'succeeded')->count());
        $this->getJson('/api/payments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/payments/{$attempt['id']}")->assertOk()->assertJsonPath('data.mode', 'DEMO');
        $this->postJson('/api/tickets/'.$reservation->tickets->first()->id.'/qr-credential')->assertCreated()->assertJsonStructure(['data' => ['ticket_reference', 'qr_token']]);
    }

    public function test_failed_attempt_does_not_activate_tickets_and_can_be_retried(): void
    {
        [$client, , , $reservation] = $this->reserveFixture();
        $attempt = $this->actingAs($client, 'sanctum')->postJson("/api/reservations/{$reservation->id}/payments")->assertCreated()->json('data');
        $this->postJson("/api/payments/{$attempt['id']}/simulate-failure")->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.reservation_status', 'pending_payment')
            ->assertJsonPath('data.reservation_payment_status', 'failed')->assertJsonPath('data.retry_allowed', true);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
        $this->assertSame(TicketStatus::Pending, $reservation->tickets()->first()->fresh()->status);
        $this->postJson("/api/payments/{$attempt['id']}/simulate-success")->assertConflict();
        $this->assertSame(3, $reservation->items()->first()->ticketType->fresh()->available_quantity);

        $retry = $this->postJson("/api/reservations/{$reservation->id}/payments")->assertCreated()->json('data');
        $this->assertNotSame($attempt['id'], $retry['id']);
        $this->postJson("/api/payments/{$retry['id']}/simulate-success")->assertOk();
        $this->assertSame(1, Payment::where('reservation_id', $reservation->id)->where('status', 'succeeded')->count());
    }

    public function test_payment_records_are_owner_scoped_and_cancelled_reservations_cannot_be_paid(): void
    {
        [$client, , , $reservation] = $this->reserveFixture();
        $attempt = $this->actingAs($client, 'sanctum')->postJson("/api/reservations/{$reservation->id}/payments")->assertCreated()->json('data');
        $other = $this->user(UserRole::Client);
        $this->actingAs($other, 'sanctum')->postJson("/api/reservations/{$reservation->id}/payments")->assertNotFound();
        $this->actingAs($other, 'sanctum')->getJson("/api/payments/{$attempt['id']}")->assertNotFound();
        $this->actingAs($client, 'sanctum')->patchJson("/api/reservations/{$reservation->id}/cancel")->assertOk();
        $this->postJson("/api/payments/{$attempt['id']}/simulate-success")->assertConflict();
        $this->getJson("/api/payments/{$attempt['id']}")->assertOk()
            ->assertJsonPath('data.reservation_status', 'cancelled')->assertJsonPath('data.retry_allowed', false);
        $this->assertSame(5, $reservation->items()->first()->ticketType->fresh()->available_quantity);
    }

    public function test_payment_endpoints_require_a_client_session(): void
    {
        $this->getJson('/api/payments')->assertUnauthorized();
        $this->actingAs($this->user(UserRole::Organizer), 'sanctum')->getJson('/api/payments')->assertForbidden();
    }

    public function test_payment_mutation_routes_are_throttled_per_authenticated_client(): void
    {
        config(['rate_limits.payments_per_minute' => 1]);

        [$client, , , $reservation] = $this->reserveFixture();
        $attempt = $this->actingAs($client, 'sanctum')
            ->postJson("/api/reservations/{$reservation->id}/payments")
            ->assertCreated()
            ->json('data');
        $this->postJson("/api/reservations/{$reservation->id}/payments")
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many requests.');

        Cache::flush();
        [$successClient, , , $successReservation] = $this->reserveFixture();
        $successAttempt = $this->actingAs($successClient, 'sanctum')
            ->postJson("/api/reservations/{$successReservation->id}/payments")
            ->assertCreated()
            ->json('data');
        Cache::flush();
        $this->postJson("/api/payments/{$successAttempt['id']}/simulate-success")->assertOk();
        $this->postJson("/api/payments/{$successAttempt['id']}/simulate-success")
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many requests.');

        Cache::flush();
        [$failureClient, , , $failureReservation] = $this->reserveFixture();
        $failureAttempt = $this->actingAs($failureClient, 'sanctum')
            ->postJson("/api/reservations/{$failureReservation->id}/payments")
            ->assertCreated()
            ->json('data');
        Cache::flush();
        $this->postJson("/api/payments/{$failureAttempt['id']}/simulate-failure")->assertOk();
        $this->postJson("/api/payments/{$failureAttempt['id']}/simulate-failure")
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many requests.');

        $this->assertNotEmpty($attempt['id']);
    }

    public function test_expiration_releases_inventory_once_and_prevents_late_payment(): void
    {
        [$client, , , $reservation] = $this->reserveFixture();
        $attempt = $this->actingAs($client, 'sanctum')->postJson("/api/reservations/{$reservation->id}/payments")->assertCreated()->json('data');
        $reservation->forceFill(['payment_expires_at' => now()->subMinute()])->save();
        $this->artisan('reservations:expire-pending')->assertExitCode(0);
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame('expired', $reservation->fresh()->payment_status->value);
        $this->assertSame(5, $reservation->items()->first()->ticketType->fresh()->available_quantity);
        $this->artisan('reservations:expire-pending')->assertExitCode(0);
        $this->assertSame(5, $reservation->items()->first()->ticketType->fresh()->available_quantity);
        $this->getJson("/api/payments/{$attempt['id']}")->assertOk()
            ->assertJsonPath('data.reservation_status', 'expired')->assertJsonPath('data.retry_allowed', false);
        $this->postJson("/api/payments/{$attempt['id']}/simulate-success")->assertConflict();
    }

    public function test_staff_scanning_rejects_pending_ticket_even_if_a_hash_exists(): void
    {
        [$client, $event, , $reservation] = $this->reserveFixture();
        $ticket = $reservation->tickets()->first();
        $token = TicketCredentialService::generateToken();
        $ticket->forceFill(['qr_token_hash' => hash('sha256', $token)])->save();
        $staff = $this->user(UserRole::Staff);
        EventStaffAssignment::create(['event_id' => $event->id, 'staff_user_id' => $staff->id, 'assigned_by' => $event->organizer_id]);
        $this->actingAs($staff, 'sanctum')->postJson('/api/staff/scans', ['qr_token' => $token, 'action' => 'ENTRY'])
            ->assertConflict()->assertJsonPath('message', 'This ticket has been cancelled or is invalid.');
        $this->assertDatabaseCount('access_logs', 0);
    }

    private function reserveFixture(): array
    {
        $client = $this->user(UserRole::Client);
        $organizer = $this->user(UserRole::Organizer);
        $event = new Event(['title' => 'Demo Checkout', 'description' => 'Test event', 'category' => 'Music', 'venue_name' => 'Test Hall', 'address' => '1 Test Street', 'city' => 'Casablanca', 'country' => 'Morocco', 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHours(3), 'capacity' => 5]);
        $event->slug = 'demo-checkout-'.strtolower(bin2hex(random_bytes(4)));
        $event->status = EventStatus::Published;
        $organizer->events()->save($event);
        $type = new TicketType(['name' => 'General', 'price' => '10.00', 'quantity' => 5, 'is_active' => true]);
        $type->forceFill(['available_quantity' => 5]);
        $event->ticketTypes()->save($type);
        $this->actingAs($client, 'sanctum')->postJson("/api/events/{$event->id}/reservations", ['items' => [['ticket_type_id' => $type->id, 'quantity' => 2]]])
            ->assertCreated()->assertJsonMissingPath('qr_credentials');
        $reservation = Reservation::query()->where('user_id', $client->id)->latest('id')->firstOrFail();

        return [$client, $event->fresh(), $type->fresh(), $reservation->fresh()];
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }
}
