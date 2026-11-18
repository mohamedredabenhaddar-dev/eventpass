<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_and_view_safe_user_details(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $target = $this->makeUser(UserRole::Organizer, 'organizer@example.test');
        $target->forceFill([
            'account_status' => AccountStatus::Suspended,
            'suspended_at' => now(),
            'suspended_by' => $admin->id,
            'suspension_reason' => 'Verification required.',
        ])->save();
        $target->createToken('private-test-token');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/users')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('data.0.email', 'organizer@example.test')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.tokens');

        $this->getJson("/api/super-admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.account_status', AccountStatus::Suspended->value)
            ->assertJsonPath('data.suspended_by', $admin->id)
            ->assertJsonPath('data.suspension_reason', 'Verification required.')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.tokens');
    }

    public function test_user_search_role_status_filters_and_pagination_work(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $first = $this->makeUser(UserRole::Organizer, 'alex.one@example.test', 'Alex One');
        $second = $this->makeUser(UserRole::Organizer, 'alex.two@example.test', 'Alex Two');
        $second->forceFill(['account_status' => AccountStatus::Suspended])->save();
        $this->makeUser(UserRole::Client, 'alex.client@example.test', 'Alex Client');
        $this->makeUser(UserRole::Staff, 'other@example.test', 'Other Person');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/users?search=alex&role=organizer&account_status=suspended&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);

        $page = $this->getJson('/api/super-admin/users?role=organizer&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->json('data.0.id');

        $this->assertContains($page, [$first->id, $second->id]);
    }

    public function test_user_list_rejects_invalid_filters_and_pagination(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/users?role=owner&account_status=disabled&page=0&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role', 'account_status', 'page', 'per_page']);
    }

    public function test_non_admin_and_unauthenticated_users_cannot_manage_users(): void
    {
        $target = $this->makeUser(UserRole::Client, 'target@example.test');

        foreach ([UserRole::Client, UserRole::Organizer, UserRole::Staff] as $index => $role) {
            $user = $this->makeUser($role, "{$role->value}-{$index}@example.test");
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/super-admin/users')->assertForbidden();
            $this->getJson("/api/super-admin/users/{$target->id}")->assertForbidden();
            $this->patchJson("/api/super-admin/users/{$target->id}/suspend", [
                'suspension_reason' => 'Policy review.',
            ])->assertForbidden();
            $this->patchJson("/api/super-admin/users/{$target->id}/reactivate")->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/super-admin/users')->assertUnauthorized();
    }

    public function test_suspension_records_metadata_preserves_role_and_revokes_existing_tokens(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $organizer = $this->makeUser(UserRole::Organizer, 'organizer@example.test');
        $token = $organizer->createToken('existing-session')->plainTextToken;

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/users/{$organizer->id}/suspend", [
                'suspension_reason' => 'Account verification is pending.',
            ])
            ->assertOk()
            ->assertJsonPath('data.account_status', AccountStatus::Suspended->value)
            ->assertJsonPath('data.suspended_by', $admin->id)
            ->assertJsonPath('data.suspension_reason', 'Account verification is pending.');

        $this->assertSame(UserRole::Organizer, $organizer->fresh()->role);
        $this->assertSame(AccountStatus::Suspended, $organizer->fresh()->account_status);
        $this->assertNotNull($organizer->fresh()->suspended_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $organizer->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/organizer/user')->assertUnauthorized();
    }

    public function test_suspension_requires_a_reason_and_rejects_blank_reason(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $target = $this->makeUser(UserRole::Client, 'target@example.test');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/users/{$target->id}/suspend")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('suspension_reason');

        $this->patchJson("/api/super-admin/users/{$target->id}/suspend", ['suspension_reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('suspension_reason');
    }

    public function test_self_suspension_and_repeated_status_transitions_are_rejected(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $client = $this->makeUser(UserRole::Client, 'client@example.test');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/users/{$admin->id}/suspend", [
                'suspension_reason' => 'Self suspension attempt.',
            ])->assertConflict();

        $this->patchJson("/api/super-admin/users/{$client->id}/reactivate")->assertConflict();
        $this->patchJson("/api/super-admin/users/{$client->id}/suspend", [
            'suspension_reason' => 'Repeated transition check.',
        ])->assertOk();
        $this->patchJson("/api/super-admin/users/{$client->id}/suspend", [
            'suspension_reason' => 'Duplicate suspension.',
        ])->assertConflict();
    }

    public function test_suspending_a_super_admin_keeps_another_active_super_admin(): void
    {
        $firstAdmin = $this->makeUser(UserRole::SuperAdmin, 'first-admin@example.test');
        $secondAdmin = $this->makeUser(UserRole::SuperAdmin, 'second-admin@example.test');
        $thirdAdmin = $this->makeUser(UserRole::SuperAdmin, 'third-admin@example.test');

        $this->actingAs($firstAdmin, 'sanctum')
            ->patchJson("/api/super-admin/users/{$secondAdmin->id}/suspend", [
                'suspension_reason' => 'Administrative review.',
            ])->assertOk();

        $this->assertSame(2, User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('account_status', AccountStatus::Active->value)
            ->count());
        $this->assertSame(AccountStatus::Suspended, $secondAdmin->fresh()->account_status);
        $this->assertSame(AccountStatus::Active, $thirdAdmin->fresh()->account_status);
    }

    public function test_reactivation_clears_suspension_metadata_without_changing_role_or_issuing_tokens(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $staff = $this->makeUser(UserRole::Staff, 'staff@example.test');
        $staff->forceFill([
            'account_status' => AccountStatus::Suspended,
            'suspended_at' => now()->subDay(),
            'suspended_by' => $admin->id,
            'suspension_reason' => 'Previous reason.',
        ])->save();
        $tokensBefore = $staff->tokens()->count();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/users/{$staff->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.account_status', AccountStatus::Active->value)
            ->assertJsonPath('data.suspended_at', null)
            ->assertJsonPath('data.suspended_by', null)
            ->assertJsonPath('data.suspension_reason', null);

        $staff->refresh();
        $this->assertSame(UserRole::Staff, $staff->role);
        $this->assertSame(AccountStatus::Active, $staff->account_status);
        $this->assertSame($tokensBefore, $staff->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/staff/user')->assertUnauthorized();
    }

    public function test_suspended_users_cannot_login_or_use_previously_issued_tokens(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $client = $this->makeUser(UserRole::Client, 'client@example.test');
        $token = $client->createToken('existing-session')->plainTextToken;

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/users/{$client->id}/suspend", [
                'suspension_reason' => 'Security review.',
            ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'client@example.test',
            'password' => 'secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/client/user')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->actingAs($client->fresh(), 'sanctum')->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $client->id]);
    }

    public function test_suspended_accounts_are_blocked_for_every_authenticated_role(): void
    {
        $roleRoutes = [
            UserRole::SuperAdmin->value => '/api/super-admin/user',
            UserRole::Organizer->value => '/api/organizer/user',
            UserRole::Staff->value => '/api/staff/user',
            UserRole::Client->value => '/api/client/user',
        ];

        foreach ($roleRoutes as $role => $route) {
            $user = $this->makeUser(UserRole::from($role), "suspended-{$role}@example.test");
            $user->forceFill(['account_status' => AccountStatus::Suspended])->save();

            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $this->actingAs($user->fresh(), 'sanctum')->getJson($route)->assertUnauthorized();
        }
    }

    public function test_active_clients_and_organizer_approval_continue_to_work(): void
    {
        $client = $this->makeUser(UserRole::Client, 'applicant@example.test');
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin@example.test');
        $application = $client->organizerRequests()->create([
            'organization_name' => 'Active Applicant Group',
            'description' => 'An active applicant.',
            'contact_email' => 'contact@example.test',
            'contact_phone' => '+212600000000',
        ]);

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/client/user')
            ->assertOk()
            ->assertJsonPath('account_status', AccountStatus::Active->value);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/super-admin/organizer-requests/{$application->id}/approve")
            ->assertOk();

        $this->assertSame(UserRole::Organizer, $client->fresh()->role);
        $this->assertSame(AccountStatus::Active, $client->fresh()->account_status);
        $this->actingAs($client->fresh(), 'sanctum')->getJson('/api/organizer/user')->assertOk();
    }

    private function makeUser(UserRole $role, string $email, ?string $name = null): User
    {
        $user = User::create([
            'name' => $name ?? ucfirst(str_replace('_', ' ', $role->value)),
            'email' => $email,
            'password' => 'secret-password',
        ]);

        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }
}
