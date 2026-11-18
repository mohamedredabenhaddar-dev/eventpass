<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-01T10:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_organizer_can_create_view_update_and_delete_unused_ticket_types(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);

        $created = $this->actingAs($organizer, 'sanctum')
            ->postJson("/api/organizer/events/{$event->id}/ticket-types", $this->ticketTypeData())
            ->assertCreated()
            ->assertJsonPath('data.name', 'General Admission')
            ->assertJsonPath('data.price', '25.00')
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.available_quantity', 10)
            ->assertJsonPath('data.is_active', true);

        $ticketTypeId = $created->json('data.id');
        $this->getJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketTypeId}")
            ->assertOk()->assertJsonPath('data.event_id', $event->id);
        $this->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketTypeId}", [
            'name' => 'Early Entry', 'price' => '30.50', 'quantity' => 12,
        ])->assertOk()->assertJsonPath('data.price', '30.50')->assertJsonPath('data.available_quantity', 12);
        $this->deleteJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketTypeId}")
            ->assertOk()->assertJsonPath('message', 'Ticket type deleted.');
        $this->assertDatabaseMissing('ticket_types', ['id' => $ticketTypeId]);
    }

    public function test_ticket_type_crud_requires_the_event_owner_and_organizer_role(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $otherOrganizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);
        $otherEvent = $this->makeEvent($otherOrganizer, EventStatus::Draft, 'Other Event');

        $this->postJson("/api/organizer/events/{$event->id}/ticket-types", $this->ticketTypeData())->assertUnauthorized();
        foreach ([UserRole::Client, UserRole::Staff, UserRole::SuperAdmin] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->postJson("/api/organizer/events/{$event->id}/ticket-types", $this->ticketTypeData())
                ->assertForbidden();
        }

        $ticketType = $this->makeTicketType($event);
        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$otherEvent->id}/ticket-types/{$ticketType->id}")->assertNotFound();
        $this->postJson("/api/organizer/events/{$otherEvent->id}/ticket-types", $this->ticketTypeData())->assertNotFound();
    }

    public function test_ticket_type_fields_are_validated_and_reserved_inventory_cannot_be_removed(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event, 3, '12.50');

        $this->actingAs($organizer, 'sanctum')
            ->postJson("/api/organizer/events/{$event->id}/ticket-types", [...$this->ticketTypeData(), 'price' => -1, 'quantity' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['price', 'quantity']);

        $this->reserve($client, $event, [[$ticketType, 2]])->assertCreated();
        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketType->id}", ['quantity' => 1])
            ->assertConflict();
        $this->deleteJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketType->id}")->assertConflict();
        $this->assertSame(3, $ticketType->fresh()->quantity);
        $this->assertSame(1, $ticketType->fresh()->available_quantity);
    }

    public function test_combined_ticket_type_inventory_may_equal_but_not_exceed_event_capacity(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer);
        $event->forceFill(['capacity' => 4])->save();

        $this->actingAs($organizer, 'sanctum')
            ->postJson("/api/organizer/events/{$event->id}/ticket-types", [...$this->ticketTypeData(), 'quantity' => 2])
            ->assertCreated();
        $this->postJson("/api/organizer/events/{$event->id}/ticket-types", [...$this->ticketTypeData(), 'name' => 'VIP', 'quantity' => 2])
            ->assertCreated();
        $this->postJson("/api/organizer/events/{$event->id}/ticket-types", [...$this->ticketTypeData(), 'name' => 'Extra', 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(4, (int) $event->ticketTypes()->sum('quantity'));
        $this->assertSame(2, $event->ticketTypes()->count());
    }

    public function test_ticket_type_quantity_updates_observe_combined_capacity_and_preserve_reserved_inventory(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $event->forceFill(['capacity' => 4])->save();
        $first = $this->makeTicketType($event, 2, '10.00', 'First');
        $second = $this->makeTicketType($event, 2, '10.00', 'Second');

        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$first->id}", ['quantity' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(2, $first->fresh()->quantity);
        $this->assertSame(2, $second->fresh()->quantity);

        $this->reserve($client, $event, [[$first, 2]])->assertCreated();
        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$first->id}", ['quantity' => 1])
            ->assertConflict();
        $this->assertSame(2, $first->fresh()->quantity);
        $this->assertSame(0, $first->fresh()->available_quantity);

        $this->patchJson("/api/organizer/events/{$event->id}", ['capacity' => 5])->assertOk();
        $this->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$first->id}", ['quantity' => 3])
            ->assertOk()->assertJsonPath('data.quantity', 3)->assertJsonPath('data.available_quantity', 1);
    }

    public function test_event_capacity_cannot_be_reduced_below_active_or_configured_inventory(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $event->forceFill(['capacity' => 5])->save();
        $type = $this->makeTicketType($event, 4, '10.00');
        $this->reserve($client, $event, [[$type, 2]])->assertCreated();

        $this->actingAs($organizer, 'sanctum')->patchJson("/api/organizer/events/{$event->id}", ['capacity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->patchJson("/api/organizer/events/{$event->id}", ['capacity' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');

        $this->assertSame(5, $event->fresh()->capacity);
        $this->assertSame(4, $type->fresh()->quantity);
        $this->assertSame(1, Reservation::where('event_id', $event->id)->count());
    }

    public function test_cancelled_reservation_history_does_not_consume_event_capacity_again(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $event->forceFill(['capacity' => 2])->save();
        $type = $this->makeTicketType($event, 2, '10.00');

        $reservationId = $this->reserve($client, $event, [[$type, 2]])->assertCreated()->json('data.id');
        $this->actingAs($client, 'sanctum')->patchJson("/api/reservations/{$reservationId}/cancel")->assertOk();
        $this->assertSame(2, $type->fresh()->available_quantity);

        $this->reserve($client, $event, [[$type, 2]])->assertCreated();
        $this->assertSame(2, Reservation::where('event_id', $event->id)->count());
        $this->assertSame(1, Reservation::where('event_id', $event->id)->where('status', ReservationStatus::Cancelled->value)->count());
        $this->assertSame(0, $type->fresh()->available_quantity);
    }

    public function test_reservation_enforces_event_capacity_across_ticket_types_and_rolls_back(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $event->forceFill(['capacity' => 1])->save();
        // Simulate legacy inventory that predates event-wide capacity enforcement.
        $first = $this->makeTicketType($event, 1, '10.00', 'First');
        $second = $this->makeTicketType($event, 1, '10.00', 'Second');

        $this->reserve($client, $event, [[$first, 1]])->assertCreated();
        $this->reserve($client, $event, [[$second, 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(1, Reservation::where('event_id', $event->id)->count());
        $this->assertSame(1, Ticket::whereHas('reservation', fn ($query) => $query->where('event_id', $event->id))->count());
        $this->assertSame(1, $second->fresh()->available_quantity);
    }

    public function test_ticket_types_cannot_be_managed_for_suspended_or_cancelled_events(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/events/{$event->id}/suspend")->assertOk();
        $this->actingAs($organizer, 'sanctum')
            ->postJson("/api/organizer/events/{$event->id}/ticket-types", $this->ticketTypeData())->assertConflict();
        $this->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketType->id}", ['price' => '5.00'])->assertConflict();
        $this->deleteJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketType->id}")->assertConflict();

        $cancelledEvent = $this->makeEvent($organizer, EventStatus::Cancelled, 'Cancelled Event');
        $this->postJson("/api/organizer/events/{$cancelledEvent->id}/ticket-types", $this->ticketTypeData())->assertConflict();
    }

    public function test_client_can_reserve_multiple_types_with_server_calculated_prices_and_individual_tickets(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $anotherClient = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $general = $this->makeTicketType($event, 5, '12.50', 'General');
        $vip = $this->makeTicketType($event, 4, '20.00', 'VIP');

        $response = $this->reserve($client, $event, [[$general, 2], [$vip, 1]], [
            'user_id' => $anotherClient->id,
            'total' => '0.01',
            'status' => 'cancelled',
            'payment_status' => 'paid',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $client->id)
            ->assertJsonPath('data.total', '45.00')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.items.0.unit_price', '12.50')
            ->assertJsonPath('data.items.0.subtotal', '25.00')
            ->assertJsonPath('data.items.1.subtotal', '20.00');

        $reservationId = $response->json('data.id');
        $reservation = Reservation::with('items')->findOrFail($reservationId);
        $this->assertSame($client->id, $reservation->user_id);
        $this->assertSame(3, Ticket::where('reservation_id', $reservationId)->count());
        $this->assertSame(3, Ticket::where('reservation_id', $reservationId)->distinct('reference')->count('reference'));
        $this->assertSame(3, Ticket::where('reservation_id', $reservationId)->where('status', TicketStatus::Valid)->count());
        $this->assertSame(3, $general->fresh()->available_quantity);
        $this->assertSame(3, $vip->fresh()->available_quantity);
    }

    public function test_reservation_requires_a_client_and_a_published_future_event(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $ticketClient = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);

        $this->postJson("/api/events/{$event->id}/reservations", ['items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]]])->assertUnauthorized();
        foreach ([UserRole::Organizer, UserRole::Staff, UserRole::SuperAdmin] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->postJson("/api/events/{$event->id}/reservations", ['items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]]])
                ->assertForbidden();
        }

        $draft = $this->makeEvent($organizer, EventStatus::Draft, 'Draft');
        $draftType = $this->makeTicketType($draft);
        $this->actingAs($ticketClient, 'sanctum')
            ->postJson("/api/events/{$draft->id}/reservations", ['items' => [['ticket_type_id' => $draftType->id, 'quantity' => 1]]])
            ->assertConflict();

        $admin = $this->makeUser(UserRole::SuperAdmin);
        $suspended = $this->makeEvent($organizer, EventStatus::Published, 'Suspended');
        $suspendedType = $this->makeTicketType($suspended);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/events/{$suspended->id}/suspend")->assertOk();
        $this->actingAs($ticketClient, 'sanctum')
            ->postJson("/api/events/{$suspended->id}/reservations", ['items' => [['ticket_type_id' => $suspendedType->id, 'quantity' => 1]]])
            ->assertConflict();

        $cancelled = $this->makeEvent($organizer, EventStatus::Cancelled, 'Cancelled');
        $cancelledType = $this->makeTicketType($cancelled);
        $this->postJson("/api/events/{$cancelled->id}/reservations", [
            'items' => [['ticket_type_id' => $cancelledType->id, 'quantity' => 1]],
        ])
            ->assertConflict();
    }

    public function test_invalid_ticket_type_and_insufficient_inventory_roll_back_every_change(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $first = $this->makeTicketType($event, 5, '10.00', 'First');
        $limited = $this->makeTicketType($event, 1, '15.00', 'Limited');
        $otherEvent = $this->makeEvent($organizer, EventStatus::Published, 'Other Event');
        $foreignType = $this->makeTicketType($otherEvent, 3, '9.00', 'Foreign');

        $this->reserve($client, $event, [[$first, 1], [$limited, 2]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity');
        $this->assertSame(5, $first->fresh()->available_quantity);
        $this->assertSame(1, $limited->fresh()->available_quantity);
        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Ticket::count());

        $this->reserve($client, $event, [[$first, 1], [$foreignType, 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(5, $first->fresh()->available_quantity);
        $this->assertSame(0, Reservation::count());
    }

    public function test_only_active_ticket_types_inside_the_sales_window_can_be_reserved(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $inactive = $this->makeTicketType($event, 5, '10.00', 'Inactive', ['is_active' => false]);
        $notStarted = $this->makeTicketType($event, 5, '10.00', 'Not started', ['sales_start_at' => now()->addDay()]);
        $ended = $this->makeTicketType($event, 5, '10.00', 'Ended', ['sales_end_at' => now()->subSecond()]);

        foreach ([$inactive, $notStarted, $ended] as $ticketType) {
            $this->reserve($client, $event, [[$ticketType, 1]])->assertUnprocessable();
        }
        $this->assertSame(0, Reservation::count());
    }

    public function test_public_ticket_type_listing_only_includes_available_active_types_for_published_events(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $published = $this->makeEvent($organizer, EventStatus::Published);
        $visible = $this->makeTicketType($published, 3, '10.00', 'Visible');
        $this->makeTicketType($published, 3, '10.00', 'Inactive', ['is_active' => false]);
        $soldOut = $this->makeTicketType($published, 1, '10.00', 'Sold out');
        $soldOut->available_quantity = 0;
        $soldOut->save();
        $draft = $this->makeEvent($organizer, EventStatus::Draft, 'Draft');

        $this->getJson("/api/events/{$published->id}/ticket-types")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->getJson("/api/events/{$draft->id}/ticket-types")->assertNotFound();
    }

    public function test_public_ticket_types_respect_sales_windows_and_exact_boundaries(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $published = $this->makeEvent($organizer, EventStatus::Published);
        $now = now();
        $alwaysOpen = $this->makeTicketType($published, 5, '10.00', 'Always open');
        $notStarted = $this->makeTicketType($published, 5, '10.00', 'Not started', ['sales_start_at' => $now->copy()->addSecond()]);
        $open = $this->makeTicketType($published, 5, '10.00', 'Open', [
            'sales_start_at' => $now->copy()->subSecond(), 'sales_end_at' => $now->copy()->addSecond(),
        ]);
        $ended = $this->makeTicketType($published, 5, '10.00', 'Ended', ['sales_end_at' => $now->copy()->subSecond()]);
        $startBoundary = $this->makeTicketType($published, 5, '10.00', 'Starts now', ['sales_start_at' => $now]);
        $endBoundary = $this->makeTicketType($published, 5, '10.00', 'Ends now', ['sales_end_at' => $now]);
        $inactive = $this->makeTicketType($published, 5, '10.00', 'Inactive', ['is_active' => false]);

        $this->getJson("/api/events/{$published->id}/ticket-types")
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonFragment(['id' => $alwaysOpen->id])
            ->assertJsonFragment(['id' => $open->id])
            ->assertJsonFragment(['id' => $startBoundary->id]);

        $this->assertDatabaseHas('ticket_types', ['id' => $notStarted->id]);
        $this->assertDatabaseHas('ticket_types', ['id' => $ended->id]);
        $this->assertDatabaseHas('ticket_types', ['id' => $endBoundary->id]);
        $this->assertDatabaseHas('ticket_types', ['id' => $inactive->id]);
    }

    public function test_direct_reservations_keep_sales_window_validation_and_boundary_rules(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $now = now();
        $startsNow = $this->makeTicketType($event, 2, '10.00', 'Starts now', ['sales_start_at' => $now]);
        $endsNow = $this->makeTicketType($event, 2, '10.00', 'Ends now', ['sales_end_at' => $now]);
        $future = $this->makeTicketType($event, 2, '10.00', 'Future', ['sales_start_at' => $now->copy()->addSecond()]);

        $this->reserve($client, $event, [[$startsNow, 1]])->assertCreated();
        $this->reserve($client, $event, [[$endsNow, 1]])->assertUnprocessable();
        $this->reserve($client, $event, [[$future, 1]])->assertUnprocessable();
        $this->assertSame(1, Reservation::count());
    }

    public function test_client_can_list_and_view_only_their_own_reservations(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $otherClient = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);
        $reservationId = $this->reserve($client, $event, [[$ticketType, 1]])->assertCreated()->json('data.id');
        $this->reserve($otherClient, $event, [[$ticketType, 1]])->assertCreated();
        $event->status = EventStatus::Suspended;
        $event->save();

        $this->actingAs($client, 'sanctum')->getJson('/api/reservations?user_id='.$otherClient->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservationId)
            ->assertJsonPath('data.0.event.category', 'Music')
            ->assertJsonPath('data.0.event.venue_name', 'Central Hall')
            ->assertJsonPath('data.0.event.address', '10 Main Street')
            ->assertJsonPath('data.0.event.city', 'Casablanca')
            ->assertJsonPath('data.0.event.status', 'suspended')
            ->assertJsonPath('data.0.items.0.unit_price', '25.00')
            ->assertJsonPath('data.0.items.0.ticket_type.name', 'General Admission')
            ->assertJsonPath('data.0.items.0.tickets.0.reference', Ticket::where('reservation_id', $reservationId)->value('reference'))
            ->assertJsonPath('data.0.items.0.tickets.0.status', 'valid')
            ->assertJsonPath('data.0.items.0.tickets.0.access_state', 'NOT_ENTERED')
            ->assertJsonMissingPath('data.0.event.organizer_id')
            ->assertJsonMissingPath('data.0.event.description')
            ->assertJsonMissingPath('data.0.items.0.tickets.0.qr_token')
            ->assertJsonMissingPath('data.0.items.0.tickets.0.qr_token_hash');
        $this->getJson("/api/reservations/{$reservationId}")
            ->assertOk()
            ->assertJsonPath('data.event.venue_name', 'Central Hall')
            ->assertJsonPath('data.event.city', 'Casablanca')
            ->assertJsonPath('data.items.0.tickets.0.status', 'valid');
        $this->actingAs($this->makeUser(UserRole::Staff), 'sanctum')->patchJson("/api/reservations/{$reservationId}/cancel")->assertForbidden();
        $this->actingAs($otherClient, 'sanctum')->getJson("/api/reservations/{$reservationId}")->assertNotFound();
        $this->patchJson("/api/reservations/{$reservationId}/cancel")->assertNotFound();
        $this->assertSame(ReservationStatus::Confirmed, Reservation::findOrFail($reservationId)->status);
    }

    public function test_client_reservation_list_keeps_pagination_and_uses_a_fixed_query_count(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $ticketType = $this->makeTicketType($event, 20);
        $this->reserve($client, $event, [[$ticketType, 1]])->assertCreated();
        $this->actingAs($client, 'sanctum');

        $queries = [];
        DB::listen(static function () use (&$queries): void {
            $queries[] = true;
        });

        $firstList = $this->getJson('/api/reservations?page=1')->assertOk();
        $singleReservationQueryCount = count($queries);
        $firstList->assertJsonCount(1, 'data');

        for ($index = 0; $index < 15; $index++) {
            $this->reserve($client, $event, [[$ticketType, 1]])->assertCreated();
        }

        $queries = [];
        $pageOne = $this->getJson('/api/reservations?page=1')->assertOk();
        $multipleReservationQueryCount = count($queries);
        $pageOne->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $pageTwo = $this->getJson('/api/reservations?page=2')->assertOk();
        $pageTwo->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);

        $this->assertSame($singleReservationQueryCount, $multipleReservationQueryCount);
        $this->assertSame(6, $multipleReservationQueryCount);
    }

    public function test_cancelling_before_event_restores_inventory_once_and_cancels_tickets(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $ticketType = $this->makeTicketType($event, 5, '20.00');
        $reservationId = $this->reserve($client, $event, [[$ticketType, 2]])->assertCreated()->json('data.id');

        $this->actingAs($client, 'sanctum')->patchJson("/api/reservations/{$reservationId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertSame(5, $ticketType->fresh()->available_quantity);
        $this->assertSame(2, Ticket::where('reservation_id', $reservationId)->where('status', TicketStatus::Cancelled)->count());

        $this->patchJson("/api/reservations/{$reservationId}/cancel")->assertConflict();
        $this->getJson('/api/reservations')->assertOk()
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.0.items.0.unit_price', '20.00')
            ->assertJsonPath('data.0.items.0.tickets.0.status', 'cancelled');
        $this->assertSame(5, $ticketType->fresh()->available_quantity);
    }

    public function test_reservations_cannot_be_cancelled_after_event_start(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($this->makeUser(UserRole::Organizer), EventStatus::Published);
        $ticketType = $this->makeTicketType($event, 5, '20.00');
        $reservationId = $this->reserve($client, $event, [[$ticketType, 2]])->assertCreated()->json('data.id');
        $event->start_at = now()->subSecond();
        $event->save();

        $this->actingAs($client, 'sanctum')->patchJson("/api/reservations/{$reservationId}/cancel")->assertConflict();
        $this->assertSame(ReservationStatus::Confirmed, Reservation::findOrFail($reservationId)->status);
        $this->assertSame(3, $ticketType->fresh()->available_quantity);
    }

    public function test_reservation_items_preserve_historical_unit_prices(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event, 5, '11.25');
        $reservationId = $this->reserve($client, $event, [[$ticketType, 2]])->assertCreated()->json('data.id');

        $ticketType->price = '30.00';
        $ticketType->save();

        $this->actingAs($client, 'sanctum')->getJson('/api/reservations')->assertOk()
            ->assertJsonPath('data.0.items.0.unit_price', '11.25')
            ->assertJsonPath('data.0.items.0.subtotal', '22.50');

        $this->assertDatabaseHas('reservation_items', [
            'reservation_id' => $reservationId,
            'ticket_type_id' => $ticketType->id,
            'unit_price' => '11.25',
            'subtotal' => '22.50',
        ]);
    }

    public function test_organizers_can_view_only_reservations_for_their_own_events(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $otherOrganizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $otherEvent = $this->makeEvent($otherOrganizer, EventStatus::Published, 'Other Event');
        $ticketType = $this->makeTicketType($event);
        $reservationId = $this->reserve($client, $event, [[$ticketType, 1]])->assertCreated()->json('data.id');

        $this->actingAs($organizer, 'sanctum')->getJson("/api/organizer/events/{$event->id}/reservations")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/organizer/events/{$event->id}/reservations/{$reservationId}")->assertOk();
        $this->actingAs($otherOrganizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/reservations")->assertNotFound();
        $this->getJson("/api/organizer/events/{$otherEvent->id}/reservations/{$reservationId}")->assertNotFound();
    }

    public function test_only_super_admin_can_inspect_all_ticket_types_and_reservations(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);
        $reservationId = $this->reserve($client, $event, [[$ticketType, 1]])->assertCreated()->json('data.id');

        foreach ([UserRole::Organizer, UserRole::Staff, UserRole::Client] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')->getJson('/api/super-admin/ticket-types')->assertForbidden();
            $this->getJson('/api/super-admin/reservations')->assertForbidden();
        }

        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/ticket-types')
            ->assertOk()->assertJsonPath('data.0.id', $ticketType->id);
        $this->getJson("/api/super-admin/ticket-types/{$ticketType->id}")->assertOk();
        $this->getJson('/api/super-admin/reservations')->assertOk()->assertJsonPath('data.0.id', $reservationId);
        $this->getJson("/api/super-admin/reservations/{$reservationId}")->assertOk();
    }

    private function reserve(User $client, Event $event, array $items, array $extra = [])
    {
        $payload = [
            'items' => array_map(fn (array $item) => [
                'ticket_type_id' => $item[0]->id,
                'quantity' => $item[1],
            ], $items),
            ...$extra,
        ];

        return $this->actingAs($client, 'sanctum')->postJson("/api/events/{$event->id}/reservations", $payload);
    }

    private function makeTicketType(Event $event, int $quantity = 10, string $price = '25.00', string $name = 'General Admission', array $overrides = []): TicketType
    {
        $ticketType = new TicketType([
            'name' => $name,
            'description' => 'Standard event access.',
            'price' => $price,
            'quantity' => $quantity,
            'sales_start_at' => null,
            'sales_end_at' => null,
            'is_active' => true,
            ...$overrides,
        ]);
        $ticketType->available_quantity = $quantity;
        $event->ticketTypes()->save($ticketType);

        return $ticketType->fresh();
    }

    private function ticketTypeData(): array
    {
        return [
            'name' => 'General Admission',
            'description' => 'Standard event access.',
            'price' => '25.00',
            'quantity' => 10,
            'sales_start_at' => null,
            'sales_end_at' => null,
            'is_active' => true,
        ];
    }

    private function makeUser(UserRole $role): User
    {
        static $sequence = 0;
        $sequence++;
        $user = User::create([
            'name' => ucfirst(str_replace('_', ' ', $role->value)),
            'email' => "phase4-{$role->value}-{$sequence}@example.com",
            'password' => 'secret-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function makeEvent(User $organizer, EventStatus $status = EventStatus::Draft, string $title = 'Phase Four Event'): Event
    {
        static $sequence = 0;
        $sequence++;
        $event = new Event([
            'title' => $title,
            'description' => 'An event used by ticket and reservation tests.',
            'category' => 'Music',
            'venue_name' => 'Central Hall',
            'address' => '10 Main Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => '2027-06-10T18:00:00Z',
            'end_at' => '2027-06-10T22:00:00Z',
            'capacity' => 100,
        ]);
        $event->slug = 'phase-four-event-'.$sequence;
        $event->status = $status;
        $organizer->events()->save($event);

        return $event->fresh();
    }
}
