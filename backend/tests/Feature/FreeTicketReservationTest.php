<?php

namespace Tests\Feature;

use App\Enums\AccessAction;
use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketAccessState;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreeTicketReservationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, ?string $email = null): User
    {
        return User::forceCreate([
            'name' => 'User '.Str::random(5),
            'email' => $email ?? Str::random(8).'@example.test',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
        ]);
    }

    private function makeEvent(User $organizer, int $capacity = 50): Event
    {
        return Event::forceCreate([
            'organizer_id' => $organizer->id,
            'title' => 'Free Open Air Festival',
            'slug' => 'free-open-air-'.Str::random(6),
            'description' => 'A free community event with limited capacity.',
            'category' => 'Culture',
            'venue_name' => 'Central Park',
            'address' => 'Park Avenue 1',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => now()->addDays(5),
            'end_at' => now()->addDays(5)->addHours(4),
            'capacity' => $capacity,
            'status' => EventStatus::Published,
        ]);
    }

    public function test_free_ticket_reservation_is_immediately_confirmed_and_valid(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, 20);

        $freeTicketType = TicketType::forceCreate([
            'event_id' => $event->id,
            'name' => 'Entrée Gratuite',
            'price' => '0.00',
            'quantity' => 10,
            'available_quantity' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson("/api/events/{$event->id}/reservations", [
                'items' => [
                    ['ticket_type_id' => $freeTicketType->id, 'quantity' => 2],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.total', '0.00')
            ->assertJsonPath('data.status', ReservationStatus::Confirmed->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::Paid->value)
            ->assertJsonPath('data.payment_expires_at', null);

        $reservationId = $response->json('data.id');

        // Check tickets are immediately Valid
        $tickets = Ticket::query()->where('reservation_id', $reservationId)->get();
        $this->assertCount(2, $tickets);
        foreach ($tickets as $ticket) {
            $this->assertSame(TicketStatus::Valid, $ticket->status);
            $this->assertSame(TicketAccessState::NotEntered, $ticket->access_state);
        }

        // Check capacity consumed
        $this->assertSame(8, $freeTicketType->fresh()->available_quantity);

        // Client can immediately get QR credentials without going through payment
        $ticketId = $tickets->first()->id;
        $credResponse = $this->actingAs($client, 'sanctum')
            ->postJson("/api/tickets/{$ticketId}/qr-credential");

        $credResponse->assertCreated()
            ->assertJsonStructure(['data' => ['ticket_reference', 'qr_token']]);

        $qrToken = $credResponse->json('data.qr_token');
        $this->assertNotEmpty($qrToken);

        // Staff can scan the free ticket at the gate immediately
        $staff = $this->makeUser(UserRole::Staff);
        EventStaffAssignment::forceCreate([
            'event_id' => $event->id,
            'staff_user_id' => $staff->id,
            'assigned_by' => $organizer->id,
        ]);

        $accessService = app(TicketAccessService::class);
        $scanResult = $accessService->scan($staff, $qrToken, AccessAction::Entry, 'Gate Free');

        $this->assertSame(AccessAction::Entry->value, $scanResult['action']);
        $this->assertSame(TicketAccessState::Inside->value, $scanResult['access_state']);
    }

    public function test_client_can_cancel_free_reservation_and_capacity_is_restored(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, 10);

        $freeTicketType = TicketType::forceCreate([
            'event_id' => $event->id,
            'name' => 'Invitation Gratuite',
            'price' => '0.00',
            'quantity' => 5,
            'available_quantity' => 5,
            'is_active' => true,
        ]);

        $reservationResponse = $this->actingAs($client, 'sanctum')
            ->postJson("/api/events/{$event->id}/reservations", [
                'items' => [
                    ['ticket_type_id' => $freeTicketType->id, 'quantity' => 3],
                ],
            ])->assertCreated();

        $reservationId = $reservationResponse->json('data.id');
        $this->assertSame(2, $freeTicketType->fresh()->available_quantity);

        // Cancel the free reservation
        $cancelResponse = $this->actingAs($client, 'sanctum')
            ->patchJson("/api/reservations/{$reservationId}/cancel");

        $cancelResponse->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::Cancelled->value);

        // Inventory is restored back to the event
        $this->assertSame(5, $freeTicketType->fresh()->available_quantity);

        // Tickets are cancelled
        $tickets = Ticket::query()->where('reservation_id', $reservationId)->get();
        foreach ($tickets as $ticket) {
            $this->assertSame(TicketStatus::Cancelled, $ticket->status);
        }
    }
}
