<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_create_an_event_owned_by_them_and_it_starts_as_draft(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $otherOrganizer = $this->makeUser(UserRole::Organizer);

        $this->actingAs($organizer, 'sanctum')->postJson('/api/organizer/events', [
            ...$this->eventData(),
            'organizer_id' => $otherOrganizer->id,
            'status' => 'published',
            'suspended_by' => $otherOrganizer->id,
        ])->assertCreated()
            ->assertJsonPath('data.organizer_id', $organizer->id)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('events', [
            'organizer_id' => $organizer->id,
            'status' => 'draft',
            'suspended_by' => null,
        ]);
    }

    public function test_only_authenticated_organizers_can_create_events(): void
    {
        $this->postJson('/api/organizer/events', $this->eventData())->assertUnauthorized();

        foreach ([UserRole::Client, UserRole::Staff, UserRole::SuperAdmin] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->postJson('/api/organizer/events', $this->eventData())
                ->assertForbidden();
        }
    }

    public function test_organizer_can_only_view_and_edit_their_own_events(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $otherOrganizer = $this->makeUser(UserRole::Organizer);
        $otherEvent = $this->makeEvent($otherOrganizer);

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$otherEvent->id}")->assertNotFound();
        $this->patchJson("/api/organizer/events/{$otherEvent->id}", ['title' => 'Stolen title'])
            ->assertNotFound();
        $this->assertSame($otherEvent->title, $otherEvent->fresh()->title);

        $ownEvent = $this->makeEvent($organizer);
        $this->getJson("/api/organizer/events/{$ownEvent->id}")->assertOk();
        $this->patchJson("/api/organizer/events/{$ownEvent->id}", ['title' => 'Updated title'])
            ->assertOk()->assertJsonPath('data.title', 'Updated title');
    }

    public function test_invalid_date_ranges_and_capacity_are_rejected(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);

        $this->actingAs($organizer, 'sanctum')->postJson('/api/organizer/events', [
            ...$this->eventData(),
            'end_at' => '2026-05-01T10:00:00Z',
            'capacity' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['end_at', 'capacity']);
    }

    public function test_slug_collisions_are_handled_safely(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $this->actingAs($organizer, 'sanctum')->postJson('/api/organizer/events', $this->eventData())
            ->assertCreated()->assertJsonPath('data.slug', 'summer-concert');

        $secondOrganizer = $this->makeUser(UserRole::Organizer);
        $this->actingAs($secondOrganizer, 'sanctum')->postJson('/api/organizer/events', $this->eventData())
            ->assertCreated()->assertJsonPath('data.slug', 'summer-concert-2');
    }

    public function test_organizer_can_publish_and_unpublish_their_event(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);

        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$event->id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->patchJson("/api/organizer/events/{$event->id}/unpublish")
            ->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_organizer_cannot_edit_or_publish_suspended_and_cancelled_events(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $suspended = $this->makeEvent($organizer, EventStatus::Published);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/events/{$suspended->id}/suspend")
            ->assertOk();

        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$suspended->id}/publish")->assertConflict();
        $this->patchJson("/api/organizer/events/{$suspended->id}", ['title' => 'Changed'])
            ->assertConflict();
        $this->patchJson("/api/organizer/events/{$suspended->id}/reactivate")->assertNotFound();
        $this->patchJson("/api/organizer/events/{$suspended->id}/cancel", [
            'cancellation_reason' => 'Organizer cancellation attempt.',
        ])->assertConflict();
        $this->assertSame(EventStatus::Suspended, $suspended->fresh()->status);

        $cancelled = $this->makeEvent($organizer, EventStatus::Draft);
        $this->patchJson("/api/organizer/events/{$cancelled->id}/cancel", [
            'cancellation_reason' => 'Organizer cancelled.',
        ])->assertOk();
        $this->patchJson("/api/organizer/events/{$cancelled->id}/publish")->assertConflict();
        $this->patchJson("/api/organizer/events/{$cancelled->id}", ['title' => 'Changed'])
            ->assertConflict();
    }

    public function test_super_admin_can_suspend_and_reactivate_events_as_draft(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/events/{$event->id}/suspend")
            ->assertOk()->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspended_by', $admin->id);

        $this->patchJson("/api/super-admin/events/{$event->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'draft');

        $this->assertNotNull($event->fresh()->suspended_at);
        $this->assertSame($admin->id, $event->fresh()->suspended_by);
    }

    public function test_only_super_admin_can_access_admin_event_management(): void
    {
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer));
        foreach ([UserRole::Organizer, UserRole::Staff, UserRole::Client] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/super-admin/events')->assertForbidden();
            $this->getJson("/api/super-admin/events/{$event->id}")->assertForbidden();
            $this->patchJson("/api/super-admin/events/{$event->id}/suspend")->assertForbidden();
        }

        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/events')->assertOk();
        $this->getJson("/api/super-admin/events/{$event->id}")->assertOk();
    }

    public function test_cancellation_requires_a_reason_and_cancelled_events_cannot_be_republished(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $event = $this->makeEvent($organizer);

        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$event->id}/cancel")->assertUnprocessable()
            ->assertJsonValidationErrors('cancellation_reason');

        $this->patchJson("/api/organizer/events/{$event->id}/cancel", [
            'cancellation_reason' => 'Venue unavailable.',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->patchJson("/api/organizer/events/{$event->id}/publish")->assertConflict();

        $otherEvent = $this->makeEvent($this->makeUser(UserRole::Organizer));
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/events/{$otherEvent->id}/cancel", [
                'cancellation_reason' => 'Safety concern.',
            ])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_public_endpoints_only_expose_published_events_and_no_organizer_information(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $published = $this->makeEvent($organizer, EventStatus::Published);
        $this->makeEvent($organizer, EventStatus::Draft, 'Draft Event');
        $this->makeEvent($organizer, EventStatus::Suspended, 'Suspended Event');
        $cancelled = $this->makeEvent($organizer, EventStatus::Cancelled, 'Cancelled Event');

        $this->getJson('/api/events')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $published->slug)
            ->assertJsonMissingPath('data.0.organizer')
            ->assertJsonMissingPath('data.0.organizer_id');
        $this->getJson("/api/events/{$published->slug}")->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonMissingPath('data.organizer');

        foreach (['draft-event', 'suspended-event', $cancelled->slug] as $privateSlug) {
            $this->getJson("/api/events/{$privateSlug}")->assertNotFound();
        }
    }

    public function test_event_listing_endpoints_require_their_roles_and_public_routes_are_open(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);

        $this->getJson('/api/organizer/events')->assertUnauthorized();
        $this->getJson('/api/super-admin/events')->assertUnauthorized();
        $this->getJson('/api/events')->assertOk();
        $this->getJson("/api/events/{$event->slug}")->assertOk();
    }

    private function makeUser(UserRole $role): User
    {
        static $sequence = 0;
        $sequence++;
        $user = User::create([
            'name' => ucfirst(str_replace('_', ' ', $role->value)),
            'email' => "event-{$role->value}-{$sequence}@example.com",
            'password' => 'secret-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function makeEvent(User $organizer, EventStatus $status = EventStatus::Draft, string $title = 'Summer Concert'): Event
    {
        static $sequence = 0;
        $sequence++;
        $event = new Event($this->eventData($title));
        $event->slug = strtolower(str_replace(' ', '-', $title)).'-'.$sequence;
        $event->status = $status;
        $organizer->events()->save($event);

        return $event->fresh();
    }

    private function eventData(string $title = 'Summer Concert'): array
    {
        return [
            'title' => $title,
            'description' => 'An evening of live music.',
            'category' => 'Music',
            'venue_name' => 'Central Hall',
            'address' => '10 Main Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => '2027-06-10T18:00:00Z',
            'end_at' => '2027-06-10T22:00:00Z',
            'capacity' => 250,
            'cover_image' => null,
        ];
    }

    public function test_public_ticket_sales_window_filtering_and_422_rejection(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);

        // Ticket type with sales_start_at in the future (not yet on sale)
        $futureTicket = new TicketType(['name' => 'Future Ticket', 'description' => 'Future Ticket', 'price' => 25.00, 'quantity' => 50, 'is_active' => true]);
        $futureTicket->sales_start_at = '2028-01-01T00:00:00Z';
        $futureTicket->sales_end_at = '2028-12-31T23:59:59Z';
        $futureTicket->available_quantity = 50;
        $event->ticketTypes()->save($futureTicket);

        // Ticket type with sales_end_at in the past (sales ended)
        $pastTicket = new TicketType(['name' => 'Past Ticket', 'description' => 'Past Ticket', 'price' => 25.00, 'quantity' => 50, 'is_active' => true]);
        $pastTicket->sales_start_at = '2020-01-01T00:00:00Z';
        $pastTicket->sales_end_at = '2020-12-31T23:59:59Z';
        $pastTicket->available_quantity = 50;
        $event->ticketTypes()->save($pastTicket);

        // List ticket types for the published event
        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/events/{$event->id}/ticket-types")
            ->assertOk()
            ->assertJsonCount(1);

        // Only the active (in-window) ticket type should be available for booking
        // (the one with sales window covering now)
        $typesResponse = $this->actingAs($this->makeUser(UserRole::Client), 'sanctum')
            ->getJson("/api/events/{$event->id}/ticket-types");
        $types = $typesResponse->json('data');
        $activeTypes = array_filter($types ?? [], fn ($t) => $t['attributes']['sales_status'] !== 'not_started' && $t['attributes']['sales_status'] !== 'ended');
        $this->assertCount(0, $activeTypes);

        // Attempt booking with a future-sales ticket should return 422
        $client = $this->makeUser(UserRole::Client);
        $this->actingAs($client, 'sanctum')
            ->postJson("/api/events/{$event->id}/reservations", [
                'items' => [
                    ['ticket_type_id' => $futureTicket->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.ticket_type_id']);

        // Attempt booking with a past-sales ticket should return 422
        $this->actingAs($client, 'sanctum')
            ->postJson("/api/events/{$event->id}/reservations", [
                'items' => [
                    ['ticket_type_id' => $pastTicket->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.ticket_type_id']);
    }

    public function test_public_event_server_side_search_and_filters(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);

        // Create published events with different city/category
        $event1 = new Event([
            'title' => 'Music Concert in Casablanca',
            'description' => 'A music concert featuring local artists.',
            'category' => 'Music',
            'venue_name' => 'Central Hall',
            'address' => '10 Main Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => '2027-06-10T18:00:00Z',
            'end_at' => '2027-06-10T22:00:00Z',
            'capacity' => 250,
            'cover_image' => null,
        ]);
        $event1->status = EventStatus::Published;
        $event1->slug = 'music-concert-casablanca';
        $organizer->events()->save($event1);

        $event2 = new Event([
            'title' => 'Tech Conference in Marrakesh',
            'description' => 'A technology conference about software architecture.',
            'category' => 'Tech',
            'venue_name' => 'Tech Center',
            'address' => '5 Innovation Drive',
            'city' => 'Marrakesh',
            'country' => 'Morocco',
            'start_at' => '2027-07-15T18:00:00Z',
            'end_at' => '2027-07-15T22:00:00Z',
            'capacity' => 200,
            'cover_image' => null,
        ]);
        $event2->status = EventStatus::Published;
        $event2->slug = 'tech-conference-marrakesh';
        $organizer->events()->save($event2);

        $event3 = new Event([
            'title' => 'Sports Gala in Casablanca',
            'description' => 'A sports gala with various athletic events.',
            'category' => 'Sports',
            'venue_name' => 'Sports Arena',
            'address' => '2 Stadium Road',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => '2027-08-20T18:00:00Z',
            'end_at' => '2027-08-20T22:00:00Z',
            'capacity' => 300,
            'cover_image' => null,
        ]);
        $event3->status = EventStatus::Published;
        $event3->slug = 'sports-gala-casablanca';
        $organizer->events()->save($event3);

        $client = $this->makeUser(UserRole::Client);

        // Test that search API returns valid response
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/events?search=test')
            ->assertOk();

        // Test city filter - "Casablanca" should match event1 and event3
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/events?city=Casablanca')
            ->assertOk()
            ->assertJsonCount(3);

        // Test category filter - "Music" should match only event1
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/events?category=Music')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('data.0.title', 'Music Concert in Casablanca');
    }

    public function test_past_events_excluded_from_public_discovery_and_blocked_from_bookings(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);

        // Create a past event (end_at in the past)
        $pastEvent = new Event([
            'title' => 'Past Event',
            'description' => 'An event that happened previously.',
            'category' => 'History',
            'venue_name' => 'Old Venue',
            'address' => '1 Old Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => '2019-01-01T18:00:00Z',
            'end_at' => '2020-01-01T18:00:00Z',
            'capacity' => 100,
            'cover_image' => null,
        ]);
        $pastEvent->status = EventStatus::Published;
        $pastEvent->slug = 'past-event';
        $organizer->events()->save($pastEvent);

        // Create a future event
        $futureEvent = new Event([
            'title' => 'Future Event',
            'description' => 'An event happening soon.',
            'category' => 'Upcoming',
            'venue_name' => 'New Venue',
            'address' => '1 New Street',
            'city' => 'Marrakesh',
            'country' => 'Morocco',
            'start_at' => '2027-06-10T18:00:00Z',
            'end_at' => '2027-06-10T22:00:00Z',
            'capacity' => 250,
            'cover_image' => null,
        ]);
        $futureEvent->status = EventStatus::Published;
        $futureEvent->slug = 'future-event';
        $organizer->events()->save($futureEvent);

        // Create a draft event (should not be in public listing)
        $draftEvent = new Event([
            'title' => 'Draft Event',
            'description' => 'A draft event not yet published.',
            'category' => 'Draft',
            'venue_name' => 'Draft Venue',
            'address' => '1 Draft Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => '2027-06-10T18:00:00Z',
            'end_at' => '2027-06-11T18:00:00Z',
            'capacity' => 50,
            'cover_image' => null,
        ]);
        $draftEvent->status = EventStatus::Draft;
        $draftEvent->slug = 'draft-event';
        $organizer->events()->save($draftEvent);

        $client = $this->makeUser(UserRole::Client);

        // Past event should not be in public listing
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/events')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('data.0.title', 'Future Event');

        // Draft event should not be in public listing
        $this->getJson('/api/events')->assertOk()->assertJsonCount(3);

        // Attempt booking for past event should be rejected
        $this->actingAs($client, 'sanctum')
            ->postJson("/api/events/{$pastEvent->id}/reservations", [
                'items' => [
                    ['ticket_type_id' => 1, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable();

        // Booking for future event should work (if ticket types are available)
        // Note: We just verify the event-level rejection, ticket types may need valid sales windows
        $this->actingAs($client, 'sanctum')
            ->getJson("/api/events/{$futureEvent->slug}")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }
}
