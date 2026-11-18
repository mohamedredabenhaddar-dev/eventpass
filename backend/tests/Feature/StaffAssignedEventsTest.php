<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffAssignedEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_list_multiple_assigned_events_with_assignment_metadata_in_start_order(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $staff = $this->makeUser(UserRole::Staff);
        $later = $this->makeEvent($organizer, 'Later event', EventStatus::Published, now()->addDays(5));
        $earlier = $this->makeEvent($organizer, 'Earlier event', EventStatus::Draft, now()->addDay());
        $firstAssignment = $this->assign($earlier, $staff, $organizer);
        $secondAssignment = $this->assign($later, $staff, $organizer);

        $this->actingAs($staff, 'sanctum')->getJson('/api/staff/events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event.id', $earlier->id)
            ->assertJsonPath('data.0.event.title', 'Earlier event')
            ->assertJsonPath('data.0.event.slug', $earlier->slug)
            ->assertJsonPath('data.0.event.description', 'Staff dashboard event details.')
            ->assertJsonPath('data.0.event.venue_name', 'North Hall')
            ->assertJsonPath('data.0.event.address', '12 Main Road')
            ->assertJsonPath('data.0.event.city', 'Casablanca')
            ->assertJsonPath('data.0.event.country', 'Morocco')
            ->assertJsonPath('data.0.event.status', 'draft')
            ->assertJsonPath('data.0.assignment.id', $firstAssignment->id)
            ->assertJsonPath('data.0.assignment.assigned_at', $firstAssignment->created_at->toISOString())
            ->assertJsonPath('data.1.event.id', $later->id)
            ->assertJsonPath('data.1.event.status', 'published')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.event.organizer_id')
            ->assertJsonMissingPath('data.0.staff')
            ->assertJsonMissingPath('data.0.assignment.assigned_by');
        $this->assertNotSame($firstAssignment->id, $secondAssignment->id);
    }

    public function test_staff_only_sees_events_assigned_to_the_authenticated_staff_user(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $firstStaff = $this->makeUser(UserRole::Staff);
        $otherStaff = $this->makeUser(UserRole::Staff);
        $firstEvent = $this->makeEvent($organizer, 'First staff event', EventStatus::Published, now()->addDay());
        $otherEvent = $this->makeEvent($organizer, 'Other staff event', EventStatus::Cancelled, now()->subDay());
        $this->assign($firstEvent, $firstStaff, $organizer);
        $this->assign($otherEvent, $otherStaff, $organizer);

        $this->actingAs($firstStaff, 'sanctum')->getJson('/api/staff/events')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event.id', $firstEvent->id)
            ->assertJsonMissing(['id' => $otherEvent->id]);
    }

    public function test_suspended_and_cancelled_assigned_events_are_returned_with_their_actual_statuses(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $staff = $this->makeUser(UserRole::Staff);
        $suspended = $this->makeEvent($organizer, 'Suspended event', EventStatus::Suspended, now()->subDays(2));
        $cancelled = $this->makeEvent($organizer, 'Cancelled event', EventStatus::Cancelled, now()->addDays(2));
        $this->assign($suspended, $staff, $organizer);
        $this->assign($cancelled, $staff, $organizer);

        $this->actingAs($staff, 'sanctum')->getJson('/api/staff/events')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event.id', $suspended->id)
            ->assertJsonPath('data.0.event.status', 'suspended')
            ->assertJsonPath('data.1.event.id', $cancelled->id)
            ->assertJsonPath('data.1.event.status', 'cancelled');
    }

    public function test_only_authenticated_staff_can_list_their_assigned_events(): void
    {
        $this->getJson('/api/staff/events')->assertUnauthorized();

        foreach ([UserRole::Client, UserRole::Organizer, UserRole::SuperAdmin] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user, 'sanctum')->getJson('/api/staff/events')->assertForbidden();
        }
    }

    private function makeUser(UserRole $role): User
    {
        static $sequence = 0;
        $sequence++;
        $user = User::create([
            'name' => ucfirst($role->value),
            'email' => "staff-events-{$role->value}-{$sequence}@example.test",
            'password' => 'secret-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function makeEvent(User $organizer, string $title, EventStatus $status, Carbon $startAt): Event
    {
        static $sequence = 0;
        $sequence++;
        $event = new Event([
            'title' => $title,
            'description' => 'Staff dashboard event details.',
            'category' => 'Community',
            'venue_name' => 'North Hall',
            'address' => '12 Main Road',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHours(3),
            'capacity' => 100,
        ]);
        $event->slug = 'staff-dashboard-event-'.$sequence;
        $event->status = $status;
        $organizer->events()->save($event);

        return $event->fresh();
    }

    private function assign(Event $event, User $staff, User $organizer): EventStaffAssignment
    {
        return EventStaffAssignment::create([
            'event_id' => $event->id,
            'staff_user_id' => $staff->id,
            'assigned_by' => $organizer->id,
        ]);
    }
}
