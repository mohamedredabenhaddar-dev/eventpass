<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerClientTicketFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_create_publish_event_and_client_can_buy_tickets(): void
    {
        // === 1. ORGANIZER account ===
        $organizer = User::create([
            'name' => 'QA Organizer',
            'email' => 'organizer-flow@eventpass.local',
            'password' => 'secret-password',
        ]);
        $organizer->forceFill(['role' => UserRole::Organizer, 'account_status' => 'active'])->save();

        // === 2. ORGANIZER creates an event (starts as Draft) ===
        $eventResponse = $this->actingAs($organizer, 'sanctum')
            ->postJson('/api/organizer/events', [
                'title' => 'QA Organizer Test Event',
                'description' => 'Event for ticket flow verification.',
                'category' => 'Music',
                'venue_name' => 'QA Hall',
                'address' => '1 Test St',
                'city' => 'Casablanca',
                'country' => 'Morocco',
                'start_at' => now()->addDay()->toISOString(),
                'end_at' => now()->addDay()->addHours(4)->toISOString(),
                'capacity' => 100,
            ]);
        $eventResponse->assertStatus(201);
        $eventId = $eventResponse->json('data.id');

        // Refresh event from database to get correct status
        $event = Event::find($eventId);
        $event->load('organizer');
        $this->assertEquals('draft', $event->status->value);

        // === 2. OR organizer publishes the event ===
        $publishResponse = $this->patchJson("/api/organizer/events/{$eventId}/publish");
        $publishResponse->assertStatus(200);
        // Refresh event from database
        $event = Event::find($eventId);
        $event->load('organizer');
        $this->assertEquals('published', $event->status->value);

        // === 3. ORGANIZER creates a ticket type (active, immediately purchasable) ===
        $ttResponse = $this->postJson("/api/organizer/events/{$eventId}/ticket-types", [
            'name' => 'General Admission',
            'description' => 'Standard entry ticket',
            'price' => 25.00,
            'quantity' => 50,
            'is_active' => true,
        ]);
        $ttResponse->assertStatus(201);
        $ticketTypeId = $ttResponse->json('data.id');

        // === 5. CLIENT account ===
        $client = User::create([
            'name' => 'QA Client',
            'email' => 'client-flow@eventpass.local',
            'password' => 'secret-password',
        ]);
        $client->forceFill(['role' => UserRole::Client, 'account_status' => 'active'])->save();

        // Login as client
        $loginResponse = $this->actingAs($client, 'sanctum')
            ->postJson('/api/login', [
                'email' => 'client-flow@eventpass.local',
                'password' => 'secret-password',
            ]);
        $loginResponse->assertStatus(200);

        // === 5. CLIENT can list published events ===
        $eventsResponse = $this->getJson('/api/events');
        $this->assertTrue($eventsResponse->status() === 200, 'Expected status 200 but got '.$eventsResponse->status());
        $events = $eventsResponse->json('data');
        $this->assertCount(1, $events);
        $this->assertEquals('QA Organizer Test Event', $events[0]['title']);

        // === 5. CLIENT can see ticket types for the event ===
        $ttResponse = $this->getJson("/api/events/{$eventId}/ticket-types");
        $ttResponse->assertStatus(200);
        $ttData = $ttResponse->json('data');
        $this->assertCount(1, $ttData);
        $this->assertEquals('General Admission', $ttData[0]['name']);
        $this->assertTrue($ttData[0]['is_active']);
        $this->assertEquals(25.00, $ttData[0]['price']);
        $this->assertEquals(50, $ttData[0]['quantity']);

        // === 5. CLIENT creates a reservation ===
        $resResponse = $this->postJson("/api/events/{$eventId}/reservations", [
            'items' => [
                ['ticket_type_id' => $ttData[0]['id'], 'quantity' => 2],
            ],
        ]);
        $resResponse->assertStatus(201);
        $reservationId = $resResponse->json('data.id');

        // === 6. CLIENT completes demo payment ===
        $payResponse = $this->postJson("/api/reservations/{$reservationId}/payments", []);
        $payResponse->assertStatus(201);

        $simResponse = $this->postJson("/api/payments/{$reservationId}/simulate-success", []);
        $simResponse->assertStatus(200);

        // === 7. Verify reservation has tickets ===
        $resDetailResponse = $this->getJson("/api/reservations/{$reservationId}");
        $resDetailResponse->assertStatus(200);
        $resData = $resDetailResponse->json('data');
        $this->assertGreaterThan(0, count($resData['items'] ?? []), 'Reservation should have items');
        $this->assertGreaterThan(0, count($resData['items'][0]['tickets'] ?? []), 'Reservation should have tickets');

        // Verify ticket statuses are valid (after payment simulation)
        $tickets = collect($resData['items'])->flatMap(fn ($item) => collect($item['tickets'] ?? []))->all();
        $validTickets = collect($tickets)->filter(fn ($t) => $t['status'] === 'valid');
        $this->assertGreaterThanOrEqual(2, count($validTickets), 'At least 2 tickets should have valid status after payment simulation');
    }
}
