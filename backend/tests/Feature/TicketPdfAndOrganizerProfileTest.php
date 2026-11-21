<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketPdfAndOrganizerProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::forceCreate([
            'name' => 'Test User',
            'email' => Str::random(8).'@test.com',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
        ]);
    }

    private function makeSetup()
    {
        $organizer = User::forceCreate(['name' => 'Org', 'email' => Str::random(6).'@o.com', 'password' => Hash::make('x'), 'role' => 'organizer']);
        $event = Event::create([
            'title' => 'Test Event', 'slug' => Str::random(8), 'venue_name' => 'Venue', 'city' => 'City',
            'country' => 'Country', 'category' => 'Music', 'description' => 'Test event description', 'address' => 'Test Street 123',
            'start_at' => now(), 'end_at' => now()->addHours(2), 'capacity' => 5, 'status' => 'published', 'organizer_id' => $organizer->id,
        ]);
        $client = User::forceCreate(['name' => 'Client', 'email' => Str::random(6).'@c.com', 'password' => Hash::make('x'), 'role' => 'client']);
        $client = User::where('email', $client->email)->first();
        $tt = TicketType::forceCreate(['event_id' => $event->id, 'name' => 'GA', 'price' => 10, 'quantity' => 5, 'available_quantity' => 5]);
        $res = Reservation::forceCreate(['user_id' => $client->id, 'event_id' => $event->id, 'status' => 'confirmed', 'payment_status' => 'paid', 'total' => 10, 'reference' => 'RES-TEST-'.Str::random(8)]);
        $item = ReservationItem::forceCreate(['reservation_id' => $res->id, 'ticket_type_id' => $tt->id, 'quantity' => 2, 'unit_price' => 10.00, 'subtotal' => 20.00]);
        $ticket = Ticket::forceCreate(['reservation_id' => $res->id, 'reservation_item_id' => $item->id, 'ticket_type_id' => $tt->id, 'reference' => Str::random(12), 'status' => 'valid']);

        return ['event' => $event, 'client' => $client, 'ticket' => $ticket, 'organizer' => $organizer];
    }

    public function test_ticket_owner_can_download_pdf(): void
    {
        $d = $this->makeSetup();
        $this->actingAs($d['client'], 'sanctum');
        $this->withoutExceptionHandling();
        $response = $this->getJson('/api/tickets/'.$d['ticket']->id.'/pdf');
        $response->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');

        $body = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $body);
        $this->assertStringContainsString('startxref', $body);
        $this->assertStringContainsString('%%EOF', $body);
    }

    public function test_non_owner_cannot_download(): void
    {
        $d = $this->makeSetup();
        $this->actingAs(User::forceCreate(['name' => 'X', 'email' => Str::random(6).'@o2.com', 'password' => Hash::make('x'), 'role' => 'organizer']), 'sanctum');
        $this->getJson('/api/tickets/'.$d['ticket']->id.'/pdf')->assertForbidden();
    }

    public function test_organizer_can_view_profile(): void
    {
        $user = $this->makeUser('organizer');
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/organizer/profile')
            ->assertOk()
            ->assertJsonStructure(['profile', 'user']);
    }

    public function test_organizer_can_update_profile(): void
    {
        $user = $this->makeUser('organizer');
        $this->actingAs($user, 'sanctum');
        $this->withoutExceptionHandling();
        $response = $this->putJson('/api/organizer/profile', ['organization_name' => 'ACME']);
        $response->assertOk();
    }

    public function test_profile_update_rejects_bad_email(): void
    {
        $user = $this->makeUser('organizer');
        $this->actingAs($user, 'sanctum');
        $this->putJson('/api/organizer/profile', ['contact_email' => 'bad-email'])
            ->assertStatus(422);
    }
}
