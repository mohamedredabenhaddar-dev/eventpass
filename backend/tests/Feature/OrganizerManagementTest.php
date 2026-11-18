<?php

namespace Tests\Feature;

use App\Enums\OrganizerRequestStatus;
use App\Enums\UserRole;
use App\Models\OrganizerRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_submit_an_application_and_cannot_choose_its_owner(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $otherClient = $this->makeUser(UserRole::Client);

        $response = $this->actingAs($client, 'sanctum')->postJson('/api/organizer-requests', [
            ...$this->applicationData(),
            'user_id' => $otherClient->id,
            'status' => 'approved',
            'reviewed_by' => $otherClient->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.applicant.id', $client->id)
            ->assertJsonMissingPath('data.applicant.password');

        $this->assertDatabaseHas('organizer_requests', [
            'user_id' => $client->id,
            'status' => 'pending',
            'reviewed_by' => null,
        ]);
    }

    public function test_unauthenticated_users_cannot_submit_an_application(): void
    {
        $this->postJson('/api/organizer-requests', $this->applicationData())->assertUnauthorized();
    }

    public function test_only_clients_can_submit_an_application(): void
    {
        foreach ([UserRole::Organizer, UserRole::Staff, UserRole::SuperAdmin] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->postJson('/api/organizer-requests', $this->applicationData())
                ->assertForbidden();
        }
    }

    public function test_duplicate_pending_applications_are_prevented(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $this->makeApplication($client);

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/organizer-requests', $this->applicationData())
            ->assertConflict();

        $this->assertSame(1, $client->organizerRequests()->count());
    }

    public function test_database_rejects_duplicate_pending_rows_outside_the_controller(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $this->makeApplication($client);

        try {
            $this->makeApplication($client);
            $this->fail('The database allowed a second pending application for the same user.');
        } catch (QueryException) {
            $this->assertSame(1, $client->organizerRequests()
                ->where('status', OrganizerRequestStatus::Pending->value)
                ->count());
        }
    }

    public function test_rejected_applicants_can_submit_again_without_losing_history(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $previous = $this->makeApplication($client);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$previous->id}/reject", [
                'rejection_reason' => 'Please provide more detail.',
            ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/organizer-requests', $this->applicationData())
            ->assertCreated();

        $this->assertSame(2, $client->organizerRequests()->count());
    }

    public function test_only_super_admin_can_view_and_manage_all_applications(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $application = $this->makeApplication($client);
        $organizer = $this->makeUser(UserRole::Organizer);
        $staff = $this->makeUser(UserRole::Staff);

        foreach ([$client, $organizer, $staff] as $user) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/super-admin/organizer-requests')->assertForbidden();
            $this->getJson("/api/super-admin/organizer-requests/{$application->id}")->assertForbidden();
            $this->patchJson("/api/super-admin/organizer-requests/{$application->id}/approve")->assertForbidden();
            $this->patchJson("/api/super-admin/organizer-requests/{$application->id}/reject", [
                'rejection_reason' => 'No.',
            ])->assertForbidden();
        }

        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/organizer-requests')->assertOk()
            ->assertJsonPath('data.0.id', $application->id);
        $this->getJson("/api/super-admin/organizer-requests/{$application->id}")->assertOk();
    }

    public function test_super_admin_can_approve_and_promotes_the_applicant(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $application = $this->makeApplication($client);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewer.id', $admin->id)
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertSame(UserRole::Organizer, $client->fresh()->role);
        $this->assertNotNull($application->fresh()->reviewed_at);
    }

    public function test_rejection_requires_a_reason_and_keeps_the_applicant_a_client(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $application = $this->makeApplication($client);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rejection_reason');

        $this->patchJson("/api/super-admin/organizer-requests/{$application->id}/reject", [
            'rejection_reason' => 'Incomplete information.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(UserRole::Client, $client->fresh()->role);
        $this->assertSame('Incomplete information.', $application->fresh()->rejection_reason);
        $this->assertSame($admin->id, $application->fresh()->reviewed_by);
        $this->assertNotNull($application->fresh()->reviewed_at);
    }

    public function test_reviewed_applications_cannot_be_approved_or_rejected_again(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $application = $this->makeApplication($client);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/reject", [
                'rejection_reason' => 'Not eligible.',
            ])->assertOk();

        $this->patchJson("/api/super-admin/organizer-requests/{$application->id}/approve")->assertConflict();
        $this->patchJson("/api/super-admin/organizer-requests/{$application->id}/reject", [
            'rejection_reason' => 'Changed mind.',
        ])->assertConflict();

        $this->assertSame(OrganizerRequestStatus::Rejected, $application->fresh()->status);
        $this->assertSame(UserRole::Client, $client->fresh()->role);
    }

    public function test_two_super_admins_cannot_both_review_the_same_application(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $firstAdmin = $this->makeUser(UserRole::SuperAdmin);
        $secondAdmin = $this->makeUser(UserRole::SuperAdmin);
        $application = $this->makeApplication($client);

        $this->actingAs($firstAdmin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/approve")
            ->assertOk();

        $this->actingAs($secondAdmin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/reject", [
                'rejection_reason' => 'Competing review.',
            ])->assertConflict();

        $this->assertSame(OrganizerRequestStatus::Approved, $application->fresh()->status);
        $this->assertSame($firstAdmin->id, $application->fresh()->reviewed_by);
        $this->assertSame(UserRole::Organizer, $client->fresh()->role);
    }

    public function test_approval_transaction_rolls_back_request_review_if_role_change_fails(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $application = $this->makeApplication($client);

        DB::statement("CREATE TRIGGER fail_organizer_promotion BEFORE UPDATE OF role ON users
            WHEN NEW.role = 'organizer' BEGIN SELECT RAISE(ABORT, 'forced role update failure'); END");

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/approve")
            ->assertInternalServerError();

        DB::statement('DROP TRIGGER fail_organizer_promotion');

        $this->assertSame(OrganizerRequestStatus::Pending, $application->fresh()->status);
        $this->assertNull($application->fresh()->reviewed_by);
        $this->assertNull($application->fresh()->reviewed_at);
        $this->assertSame(UserRole::Client, $client->fresh()->role);
    }

    public function test_client_can_view_only_their_own_application_list(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $otherClient = $this->makeUser(UserRole::Client);
        $ownApplication = $this->makeApplication($client);
        $this->makeApplication($otherClient);

        $this->actingAs($client, 'sanctum')->getJson('/api/organizer-requests/my')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownApplication->id);
    }

    private function makeUser(UserRole $role): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create([
            'name' => ucfirst(str_replace('_', ' ', $role->value)),
            'email' => "{$role->value}-{$sequence}@example.com",
            'password' => 'secret-password',
        ]);

        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function makeApplication(User $user): OrganizerRequest
    {
        return $user->organizerRequests()->create($this->applicationData());
    }

    private function applicationData(): array
    {
        return [
            'organization_name' => 'Northstar Events',
            'description' => 'We organize community events.',
            'contact_email' => 'events@example.com',
            'contact_phone' => '+1-555-0100',
            'website' => 'https://example.com',
        ];
    }
}
