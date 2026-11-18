<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_always_creates_a_client_and_returns_a_token(): void
    {
        foreach ([...UserRole::values(), 'admin', 'user'] as $index => $submittedRole) {
            $email = "pat{$index}@example.com";
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
                ->postJson('/api/register', [
                    'name' => 'Pat Example',
                    'email' => $email,
                    'password' => 'Strong-pass1!',
                    'password_confirmation' => 'Strong-pass1!',
                    'role' => $submittedRole,
                ]);

            $response->assertCreated()
                ->assertJsonPath('user.role', UserRole::Client->value)
                ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role'], 'token']);

            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => UserRole::Client->value,
            ]);
        }
    }

    public function test_newly_issued_registration_token_can_access_the_authenticated_user_endpoint(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Token Client',
            'email' => 'token-client@example.com',
            'password' => 'Strong-pass1!',
            'password_confirmation' => 'Strong-pass1!',
        ])->assertCreated();

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'token-client@example.com');
    }

    public function test_personal_access_token_remains_valid_within_seven_days(): void
    {
        $this->assertSame(10080, config('sanctum.expiration'));
        $this->travelTo(now()->startOfSecond());
        $user = User::create([
            'name' => 'Token User',
            'email' => 'token-valid@example.com',
            'password' => 'secret-password',
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        $validAt = now()->addDays(6)->addHours(23);

        $this->travelTo($validAt);
        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_personal_access_token_expires_after_seven_days(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::create([
            'name' => 'Expired Token User',
            'email' => 'token-expired@example.com',
            'password' => 'secret-password',
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        $expiresAt = now()->addDays(7);

        $this->travelTo($expiresAt);
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/client/user')->assertUnauthorized();
    }

    public function test_only_the_four_eventpass_roles_are_defined_and_new_users_default_to_client(): void
    {
        $this->assertSame(['super_admin', 'organizer', 'staff', 'client'], UserRole::values());

        $user = User::create([
            'name' => 'Default Client',
            'email' => 'client@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertSame(UserRole::Client, $user->fresh()->role);
    }

    public function test_user_can_login_and_invalid_credentials_are_rejected(): void
    {
        User::create([
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'password' => 'secret-password',
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'pat@example.com',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $this->withToken($login->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'pat@example.com');

        $this->postJson('/api/login', [
            'email' => 'pat@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_passwords_that_do_not_meet_the_strength_policy(): void
    {
        $invalidPasswords = [
            'short1!',
            '12345678!',
            'Password!',
            'Password1',
        ];

        foreach ($invalidPasswords as $index => $password) {
            $this->postJson('/api/register', [
                'name' => 'Password Test',
                'email' => "password-test-{$index}@example.com",
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_registration_accepts_a_password_meeting_all_strength_requirements(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Strong Password',
            'email' => 'strong-password@example.com',
            'password' => 'Strong-pass1!',
            'password_confirmation' => 'Strong-pass1!',
        ])->assertCreated()->assertJsonPath('user.email', 'strong-password@example.com');
    }

    public function test_registration_rejects_a_password_confirmation_mismatch(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Mismatch Password',
            'email' => 'mismatch-password@example.com',
            'password' => 'Strong-pass1!',
            'password_confirmation' => 'Strong-pass2!',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::create([
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'password' => 'secret-password',
        ]);
        $token = $user->createToken('test-token');

        $this->withToken($token->plainTextToken)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_each_eventpass_role_can_access_its_protected_route_only(): void
    {
        $routeByRole = [
            UserRole::SuperAdmin->value => '/api/super-admin/user',
            UserRole::Organizer->value => '/api/organizer/user',
            UserRole::Staff->value => '/api/staff/user',
            UserRole::Client->value => '/api/client/user',
        ];

        foreach ($routeByRole as $role => $allowedRoute) {
            $user = User::create([
                'name' => ucfirst($role),
                'email' => "{$role}@example.com",
                'password' => 'secret-password',
            ]);
            $user->forceFill(['role' => UserRole::from($role)])->save();

            $this->actingAs($user, 'sanctum')->getJson($allowedRoute)
                ->assertOk()
                ->assertJsonPath('role', $role);

            foreach ($routeByRole as $otherRole => $otherRoute) {
                if ($otherRole !== $role) {
                    $this->getJson($otherRoute)->assertForbidden();
                }
            }
        }
    }
}
