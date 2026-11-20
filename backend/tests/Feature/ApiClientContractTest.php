<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the Phase 17 acceptance failure where the Vite
 * frontend called the bare origin (http://127.0.0.1:8000/login) instead of the
 * /api route group. The browser then saw a failed CORS preflight and the UI
 * showed "We could not complete that request. Please try again."
 *
 * These tests pin the contract the frontend depends on: the acceptance origin
 * may preflight and call the /api endpoints, and the unprefixed SPA paths are
 * not API routes.
 */
class ApiClientContractTest extends TestCase
{
    use RefreshDatabase;

    private const ACCEPTANCE_ORIGIN = 'http://127.0.0.1:5173';

    public function test_acceptance_origin_can_preflight_the_login_endpoint(): void
    {
        $response = $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => self::ACCEPTANCE_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent();
        $this->assertSame(self::ACCEPTANCE_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsStringIgnoringCase(
            'authorization',
            (string) $response->headers->get('Access-Control-Allow-Headers')
        );
        $this->assertStringContainsStringIgnoringCase(
            'content-type',
            (string) $response->headers->get('Access-Control-Allow-Headers')
        );
    }

    public function test_acceptance_origin_can_log_in_through_the_api_prefix(): void
    {
        $user = User::factory()->create([
            'email' => 'contract-client@eventpass.local',
            'password' => 'Accept17!Pass',
        ]);
        $user->forceFill(['role' => UserRole::Client])->save();

        $this->withHeader('Origin', self::ACCEPTANCE_ORIGIN)
            ->postJson('/api/login', [
                'email' => 'contract-client@eventpass.local',
                'password' => 'Accept17!Pass',
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'contract-client@eventpass.local')
            ->assertJsonPath('user.role', UserRole::Client->value)
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role'], 'token']);
    }

    public function test_unprefixed_spa_paths_are_not_api_routes(): void
    {
        $this->postJson('/login', [])->assertNotFound();
        $this->getJson('/events')->assertNotFound();
    }

    public function test_events_endpoint_is_served_under_the_api_prefix(): void
    {
        $this->withHeader('Origin', self::ACCEPTANCE_ORIGIN)
            ->getJson('/api/events')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }
}
