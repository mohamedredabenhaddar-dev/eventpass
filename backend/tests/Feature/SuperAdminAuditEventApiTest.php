<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminAuditEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_super_admin_can_read_audit_events_and_response_contains_only_approved_fields(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $event = $this->recordUser($admin, 'event.created', 41, ['status' => 'draft']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/audit-events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.action', 'event.created')
            ->assertJsonPath('data.0.metadata.status', 'draft')
            ->assertJsonStructure(['data' => [[
                'id', 'occurred_at', 'actor_type', 'actor_id', 'actor_role',
                'action', 'entity_type', 'entity_id', 'outcome', 'metadata',
            ]], 'links', 'meta']);

        $row = $this->getJson('/api/super-admin/audit-events')->json('data.0');
        $this->assertSame([
            'id', 'occurred_at', 'actor_type', 'actor_id', 'actor_role',
            'action', 'entity_type', 'entity_id', 'outcome', 'metadata',
        ], array_keys($row));
        $this->assertArrayNotHasKey('name', $row);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('password', $row);
    }

    public function test_unauthenticated_users_are_rejected(): void
    {
        $this->getJson('/api/super-admin/audit-events')->assertUnauthorized();
    }

    public function test_non_admin_roles_are_rejected(): void
    {
        foreach ([UserRole::Client, UserRole::Organizer, UserRole::Staff] as $role) {
            $this->actingAs($this->makeUser($role), 'sanctum')
                ->getJson('/api/super-admin/audit-events')
                ->assertForbidden();
        }
    }

    public function test_suspended_super_admin_is_rejected(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $admin->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/audit-events')->assertUnauthorized();
    }

    public function test_results_are_newest_first_with_stable_id_tie_breaking_and_pagination(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        Carbon::setTestNow('2026-03-01 12:00:00 UTC');
        $first = $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);
        $second = $this->recordUser($admin, 'event.created', 2, ['status' => 'draft']);
        Carbon::setTestNow('2026-03-02 12:00:00 UTC');
        $newer = $this->recordUser($admin, 'event.created', 3, ['status' => 'draft']);
        Carbon::setTestNow();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/audit-events?per_page=2')->assertOk();
        $this->assertSame([$newer->id, $second->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame($first->id, $this->getJson('/api/super-admin/audit-events?page=2&per_page=2')->json('data.0.id'));
    }

    public function test_each_supported_filter_is_applied_server_side(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        Carbon::setTestNow('2026-04-10 12:00:00 UTC');
        $created = $this->recordUser($admin, 'event.created', 101, ['status' => 'draft']);
        Carbon::setTestNow('2026-04-11 12:00:00 UTC');
        $expired = app(AuditRecorder::class)->recordSystemAction('reservation.expired', 202, metadata: [
            'event_id' => 5, 'item_count' => 1, 'total_quantity' => 2, 'source' => 'reservations:expire-pending',
        ]);
        Carbon::setTestNow('2026-04-12 12:00:00 UTC');
        $failed = $this->recordUser($admin, 'demo_payment.simulation_failed', 303, [
            'reservation_id' => 8, 'payment_mode' => 'demo', 'previous_status' => 'pending', 'new_status' => 'failed',
        ], 'failed');
        Carbon::setTestNow();

        $cases = [
            ['action' => 'event.created', 'ids' => [$created->id]],
            ['actor_id' => $admin->id, 'ids' => [$failed->id, $created->id]],
            ['actor_type' => 'system', 'ids' => [$expired->id]],
            ['entity_type' => 'payment', 'ids' => [$failed->id]],
            ['entity_type' => 'reservation', 'entity_id' => 202, 'ids' => [$expired->id]],
            ['outcome' => 'failed', 'ids' => [$failed->id]],
            ['date_from' => '2026-04-10', 'date_to' => '2026-04-10', 'ids' => [$created->id]],
            ['date_from' => '2026-04-12', 'ids' => [$failed->id]],
            ['date_to' => '2026-04-11', 'ids' => [$expired->id, $created->id]],
        ];

        foreach ($cases as $case) {
            $expectedIds = $case['ids'];
            unset($case['ids']);
            $query = http_build_query($case);
            $response = $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/audit-events?'.$query)
                ->assertOk()->assertJsonCount(count($expectedIds), 'data');
            $this->assertSame($expectedIds, collect($response->json('data'))->pluck('id')->all());
        }
    }

    public function test_combined_filters_return_only_matching_rows(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $match = $this->recordUser($admin, 'event.created', 701, ['status' => 'draft']);
        $this->recordUser($admin, 'event.created', 702, ['status' => 'draft']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?'.http_build_query([
                'action' => 'event.created', 'actor_type' => 'user', 'actor_id' => $admin->id,
                'entity_type' => 'event', 'entity_id' => 701, 'outcome' => 'success',
            ]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    public function test_invalid_filters_and_date_ranges_are_rejected(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->actingAs($admin, 'sanctum');

        foreach ([
            ['action' => 'unsupported.action'], ['actor_type' => 'robot'], ['entity_type' => 'secret'],
            ['outcome' => 'maybe'], ['actor_id' => 0], ['entity_id' => -1],
            ['date_from' => '2026-13-40'], ['date_to' => 'not-a-date'],
            ['date_from' => '2026-05-02', 'date_to' => '2026-05-01'],
            ['per_page' => 0], ['per_page' => 101], ['page' => 0],
        ] as $filters) {
            $this->getJson('/api/super-admin/audit-events?'.http_build_query($filters))->assertUnprocessable();
        }
    }

    public function test_empty_history_returns_empty_paginated_data(): void
    {
        $this->actingAs($this->makeUser(UserRole::SuperAdmin), 'sanctum')
            ->getJson('/api/super-admin/audit-events')
            ->assertOk()->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_endpoint_does_not_allow_audit_record_modification_or_deletion(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $audit = $this->recordUser($admin, 'event.created', 88, ['status' => 'draft']);
        $before = $audit->fresh()->getAttributes();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $this->actingAs($admin, 'sanctum')
                ->json($method, "/api/super-admin/audit-events/{$audit->id}", ['outcome' => 'failed']);
            $this->assertContains($response->status(), [404, 405], "{$method} must not mutate audit events.");
        }

        $collectionWrite = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/super-admin/audit-events', ['outcome' => 'failed']);
        $this->assertContains($collectionWrite->status(), [404, 405]);

        $this->assertSame(1, AuditEvent::query()->count());
        $this->assertSame($before, $audit->fresh()->getAttributes());
    }

    public function test_response_metadata_strips_unapproved_keys(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $audit = $this->recordUser($admin, 'event.created', 99, ['status' => 'draft']);
        $metadata = json_decode($audit->getRawOriginal('metadata'), true);
        $metadata['password'] = 'must-not-leak';
        $metadata['email'] = 'private@example.test';
        DB::table('audit_events')->where('id', $audit->id)->update(['metadata' => json_encode($metadata)]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/super-admin/audit-events')
            ->assertOk()->assertJsonPath('data.0.metadata', ['status' => 'draft']);
    }

    public function test_pagination_links_preserve_filters_and_page_size(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $first = null;

        try {
            Carbon::setTestNow('2026-07-01 12:00:00 UTC');
            $first = $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);
            $this->recordUser($admin, 'event.created', 2, ['status' => 'draft']);
            $this->recordUser($admin, 'event.created', 3, ['status' => 'draft']);
            $this->recordUser($admin, 'user.suspended', 4, [
                'previous_status' => 'active',
                'new_status' => 'suspended',
                'target_role' => 'client',
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?'.http_build_query([
                'action' => 'event.created',
                'per_page' => 2,
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $next = $response->json('links.next');
        $this->assertIsString($next);
        $this->assertStringContainsString('action=event.created', $next);
        $this->assertStringContainsString('per_page=2', $next);

        $nextResponse = $this->getJson($this->relativeUrl($next))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->assertSame([$first->id], collect($nextResponse->json('data'))->pluck('id')->all());
    }

    public function test_pagination_links_preserve_multiple_combined_filters(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->recordUser($admin, 'event.created', 10, ['status' => 'draft']);
        $this->recordUser($admin, 'event.created', 11, ['status' => 'draft']);
        $this->recordUser($admin, 'user.suspended', 12, [
            'previous_status' => 'active',
            'new_status' => 'suspended',
            'target_role' => 'client',
        ]);

        $filters = [
            'action' => 'event.created',
            'actor_type' => 'user',
            'actor_id' => $admin->id,
            'entity_type' => 'event',
            'outcome' => 'success',
            'per_page' => 1,
        ];

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?'.http_build_query($filters))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $next = $response->json('links.next');
        $this->assertIsString($next);

        foreach ($filters as $key => $value) {
            $this->assertStringContainsString($key.'='.$value, $next, "The {$key} filter is missing from the next page URL.");
        }
    }

    public function test_per_page_accepts_the_maximum_allowed_value(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?per_page=100')
            ->assertOk();

        $this->assertSame(100, $response->json('meta.per_page'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_page_number_is_capped(): void
    {
        $this->actingAs($this->makeUser(UserRole::SuperAdmin), 'sanctum')
            ->getJson('/api/super-admin/audit-events?page=100001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');
    }

    public function test_unknown_query_parameters_are_rejected(): void
    {
        $this->actingAs($this->makeUser(UserRole::SuperAdmin), 'sanctum');

        foreach (['unsupported' => 'value', 'acton' => 'event.created'] as $parameter => $value) {
            $this->getJson('/api/super-admin/audit-events?'.http_build_query([$parameter => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($parameter);
        }
    }

    public function test_actor_id_requires_user_actor_type(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?'.http_build_query([
                'actor_id' => $admin->id,
                'actor_type' => 'system',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actor_type');
    }

    public function test_entity_id_requires_entity_type(): void
    {
        $this->actingAs($this->makeUser(UserRole::SuperAdmin), 'sanctum')
            ->getJson('/api/super-admin/audit-events?entity_id=5')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entity_type');
    }

    public function test_pagination_beyond_final_page_returns_empty_data(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?page=999&per_page=15')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_date_filters_use_utc_calendar_day_boundaries(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $start = null;
        $end = null;
        $next = null;

        try {
            Carbon::setTestNow('2026-08-10 00:00:00 UTC');
            $start = $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);

            Carbon::setTestNow('2026-08-10 23:59:59 UTC');
            $end = $this->recordUser($admin, 'event.created', 2, ['status' => 'draft']);

            Carbon::setTestNow('2026-08-11 00:00:00 UTC');
            $next = $this->recordUser($admin, 'event.created', 3, ['status' => 'draft']);
        } finally {
            Carbon::setTestNow();
        }

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?date_from=2026-08-10&date_to=2026-08-10')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame([$end->id, $start->id], collect($response->json('data'))->pluck('id')->all());

        $this->getJson('/api/super-admin/audit-events?date_from=2026-08-10')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $dateToResponse = $this->getJson('/api/super-admin/audit-events?date_to=2026-08-10')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotContains($next->id, collect($dateToResponse->json('data'))->pluck('id')->all());
    }

    public function test_date_filters_combine_with_action_filter(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $created = null;

        try {
            Carbon::setTestNow('2026-09-01 10:00:00 UTC');
            $created = $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);
            $this->recordUser($admin, 'user.suspended', 2, [
                'previous_status' => 'active',
                'new_status' => 'suspended',
                'target_role' => 'client',
            ]);

            Carbon::setTestNow('2026-09-02 10:00:00 UTC');
            $this->recordUser($admin, 'event.created', 3, ['status' => 'draft']);
        } finally {
            Carbon::setTestNow();
        }

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?'.http_build_query([
                'action' => 'event.created',
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-01',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($created->id, $response->json('data.0.id'));
    }

    public function test_filtered_empty_result_returns_empty_paginated_data(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $this->recordUser($admin, 'event.created', 1, ['status' => 'draft']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?action=user.suspended')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_calendar_dates_are_rejected(): void
    {
        $this->actingAs($this->makeUser(UserRole::SuperAdmin), 'sanctum')
            ->getJson('/api/super-admin/audit-events?date_from=2026-02-30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    public function test_null_actor_ids_are_returned_safely(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $expired = app(AuditRecorder::class)->recordSystemAction('reservation.expired', 77, metadata: [
            'event_id' => 5,
            'item_count' => 1,
            'total_quantity' => 1,
            'source' => 'reservations:expire-pending',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events?'.http_build_query(['action' => 'reservation.expired']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $expired->id);

        $this->assertNull($response->json('data.0.actor_id'));
        $this->assertNull($response->json('data.0.actor_role'));
    }

    public function test_null_entity_ids_are_returned_safely(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);

        DB::table('audit_events')->insert([
            'occurred_at' => '2026-10-01 12:00:00',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_role' => null,
            'action' => 'reservation.expired',
            'entity_type' => 'reservation',
            'entity_id' => null,
            'outcome' => 'success',
            'metadata' => json_encode([
                'event_id' => 5,
                'item_count' => 1,
                'total_quantity' => 1,
                'source' => 'reservations:expire-pending',
            ]),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'reservation.expired');

        $this->assertNull($response->json('data.0.entity_id'));
        $this->assertNull($response->json('data.0.actor_id'));
    }

    public function test_invalid_stored_metadata_does_not_expose_sensitive_values(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $audit = $this->recordUser($admin, 'event.created', 99, ['status' => 'draft']);
        $metadata = json_decode($audit->getRawOriginal('metadata'), true);
        $metadata['status'] = 'not-a-valid-status';
        $metadata['password'] = 'must-not-leak';
        $metadata['email'] = 'private@example.test';
        DB::table('audit_events')->where('id', $audit->id)->update(['metadata' => json_encode($metadata)]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events')
            ->assertOk();

        $row = $response->json('data.0');
        $encoded = json_encode($row);

        $this->assertNull($row['metadata']);
        $this->assertStringNotContainsString('must-not-leak', $encoded);
        $this->assertStringNotContainsString('private@example.test', $encoded);
    }

    public function test_response_sanitization_does_not_modify_stored_metadata(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $audit = $this->recordUser($admin, 'event.created', 99, ['status' => 'draft']);
        $metadata = json_decode($audit->getRawOriginal('metadata'), true);
        $metadata['password'] = 'must-not-leak';
        $metadata['email'] = 'private@example.test';
        DB::table('audit_events')->where('id', $audit->id)->update(['metadata' => json_encode($metadata)]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/super-admin/audit-events')
            ->assertOk()
            ->assertJsonPath('data.0.metadata', ['status' => 'draft']);

        $stored = json_decode(DB::table('audit_events')->where('id', $audit->id)->value('metadata'), true);

        $this->assertSame('must-not-leak', $stored['password']);
        $this->assertSame('private@example.test', $stored['email']);
    }

    private function relativeUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return parse_url($url, PHP_URL_PATH).($query ? '?'.$query : '');
    }

    private function recordUser(User $actor, string $action, int $entityId, array $metadata, string $outcome = 'success'): AuditEvent
    {
        return app(AuditRecorder::class)->recordUserAction($actor, $action, $entityId, $outcome, $metadata);
    }

    private function makeUser(UserRole $role): User
    {
        $user = User::query()->create([
            'name' => 'Test '.Str::ulid(),
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }
}
