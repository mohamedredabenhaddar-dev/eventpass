<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerEventStaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_only_active_staff_accounts_with_assignment_state(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer, 'Owner Organizer');
        $event = $this->makeEvent($organizer);

        $available = $this->makeUser(UserRole::Staff, 'Amine Available');
        $assigned = $this->makeUser(UserRole::Staff, 'Zineb Assigned');
        $suspended = $this->makeUser(UserRole::Staff, 'Suspended Staff', AccountStatus::Suspended);
        $this->makeUser(UserRole::Client, 'Client Account');
        $this->makeUser(UserRole::SuperAdmin, 'Super Admin');

        $event->staffAssignments()->create([
            'staff_user_id' => $assigned->id,
            'assigned_by' => $organizer->id,
        ]);

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $available->id)
            ->assertJsonPath('data.0.name', 'Amine Available')
            ->assertJsonPath('data.0.email', $available->email)
            ->assertJsonPath('data.0.already_assigned', false)
            ->assertJsonPath('data.1.id', $assigned->id)
            ->assertJsonPath('data.1.already_assigned', true)
            ->assertJsonMissing(['id' => $suspended->id]);
    }

    public function test_owner_can_search_the_directory_by_name_or_email(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer, 'Search Organizer');
        $event = $this->makeEvent($organizer);

        $this->makeUser(UserRole::Staff, 'Amine Available');
        $this->makeUser(UserRole::Staff, 'Zineb Searchable');
        $youssef = $this->makeUser(UserRole::Staff, 'Youssef Other');

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available?search=zineb")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zineb Searchable');

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available?search=".urlencode($youssef->email))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Youssef Other');

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available?search=nobody-matches")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_organizer_cannot_list_the_staff_directory(): void
    {
        $owner = $this->makeUser(UserRole::Organizer, 'Owner Organizer');
        $other = $this->makeUser(UserRole::Organizer, 'Other Organizer');
        $event = $this->makeEvent($owner);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available")
            ->assertNotFound();
    }

    public function test_non_organizer_roles_are_forbidden_from_the_staff_directory(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer, 'Owner Organizer');
        $client = $this->makeUser(UserRole::Client, 'Client Account');
        $staff = $this->makeUser(UserRole::Staff, 'Staff Account');
        $event = $this->makeEvent($organizer);

        $this->actingAs($client, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available")
            ->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/staff/available")
            ->assertForbidden();
    }

    public function test_guest_cannot_list_the_staff_directory(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer, 'Owner Organizer');
        $event = $this->makeEvent($organizer);

        $this->getJson("/api/organizer/events/{$event->id}/staff/available")->assertUnauthorized();
    }

    private function makeEvent(User $organizer, string $title = 'Staff Directory Event'): Event
    {
        static $sequence = 0;
        $sequence++;

        $event = new Event([
            'title' => $title, 'description' => 'Event for staff directory tests.', 'category' => 'Music',
            'venue_name' => 'Test Hall', 'address' => '1 Test Street', 'city' => 'Casablanca', 'country' => 'Morocco',
            'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHours(4), 'capacity' => 100,
        ]);
        $event->slug = 'staff-directory-'.$sequence;
        $event->status = EventStatus::Published;
        $organizer->events()->save($event);

        return $event->fresh();
    }

    private function makeUser(UserRole $role, string $name, AccountStatus $status = AccountStatus::Active): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create([
            'name' => $name,
            'email' => "staff-directory-{$sequence}@example.test",
            'password' => 'secret-password',
        ]);
        $user->forceFill(['role' => $role, 'account_status' => $status])->save();

        return $user->fresh();
    }
}
