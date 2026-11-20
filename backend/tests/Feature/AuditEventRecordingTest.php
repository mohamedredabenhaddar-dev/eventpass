<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\OrganizerRequestStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\OrganizerRequest;
use App\Models\Reservation;
use App\Models\TicketType;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\ReservationService;
use App\Support\DatabaseUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AuditEventRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_organizer_request_transitions_record_actor_and_allowlisted_metadata(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $target = $this->makeUser(UserRole::Client);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/users/{$target->id}/suspend", [
            'suspension_reason' => 'This free text must not be copied into audit metadata.',
        ])->assertOk();
        $this->patchJson("/api/super-admin/users/{$target->id}/reactivate")->assertOk();

        $approvedApplicant = $this->makeUser(UserRole::Client);
        $approvedRequest = $this->makeOrganizerRequest($approvedApplicant);
        $this->patchJson("/api/super-admin/organizer-requests/{$approvedRequest->id}/approve")->assertOk();

        $rejectedApplicant = $this->makeUser(UserRole::Client);
        $rejectedRequest = $this->makeOrganizerRequest($rejectedApplicant);
        $this->patchJson("/api/super-admin/organizer-requests/{$rejectedRequest->id}/reject", [
            'rejection_reason' => 'This rejection reason must not be copied into audit metadata.',
        ])->assertOk();

        $suspension = AuditEvent::query()->where('action', 'user.suspended')->sole();
        $this->assertSame($admin->id, $suspension->actor_id);
        $this->assertSame(UserRole::SuperAdmin->value, $suspension->actor_role);
        $this->assertSame($target->id, $suspension->entity_id);
        $this->assertSame(['previous_status' => 'active', 'new_status' => 'suspended', 'target_role' => 'client'], $suspension->metadata);

        $this->assertSame(1, AuditEvent::query()->where('action', 'user.reactivated')->count());
        $approval = AuditEvent::query()->where('action', 'organizer_request.approved')->sole();
        $this->assertSame($approvedRequest->id, $approval->entity_id);
        $this->assertSame('client', $approval->metadata['previous_role']);
        $this->assertSame('organizer', $approval->metadata['new_role']);
        $rejection = AuditEvent::query()->where('action', 'organizer_request.rejected')->sole();
        $this->assertSame('rejected', $rejection->metadata['new_status']);
        $this->assertArrayNotHasKey('rejection_reason', $rejection->metadata);
        $this->assertSame(OrganizerRequestStatus::Approved, $approvedRequest->fresh()->status);
        $this->assertSame(UserRole::Organizer, $approvedApplicant->fresh()->role);
        $this->assertSame(OrganizerRequestStatus::Rejected, $rejectedRequest->fresh()->status);
    }

    public function test_event_lifecycle_uses_a_single_dedicated_capacity_action_and_preserves_failed_rules(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $admin = $this->makeUser(UserRole::SuperAdmin);

        $created = $this->actingAs($organizer, 'sanctum')->postJson('/api/organizer/events', $this->eventData())
            ->assertCreated()->json('data');
        $eventId = $created['id'];
        $this->assertSame(1, AuditEvent::query()->where('action', 'event.created')->where('entity_id', $eventId)->count());

        $this->patchJson("/api/organizer/events/{$eventId}", ['title' => 'Updated Audit Event', 'capacity' => 120])->assertOk();
        $capacityEvent = AuditEvent::query()->where('entity_id', $eventId)->where('action', 'event.capacity_changed')->sole();
        $this->assertSame(100, $capacityEvent->metadata['previous_capacity']);
        $this->assertSame(120, $capacityEvent->metadata['new_capacity']);
        $this->assertContains('title', $capacityEvent->metadata['changed_fields']);
        $this->assertContains('capacity', $capacityEvent->metadata['changed_fields']);
        $this->assertSame(0, AuditEvent::query()->where('entity_id', $eventId)->where('action', 'event.updated')->count());

        $this->patchJson("/api/organizer/events/{$eventId}", ['description' => 'A different description.'])->assertOk();
        $this->assertSame(['changed_fields' => ['description']], AuditEvent::query()
            ->where('entity_id', $eventId)->where('action', 'event.updated')->sole()->metadata);
        $this->patchJson("/api/organizer/events/{$eventId}", ['capacity' => 0])->assertUnprocessable();
        $this->assertSame(1, AuditEvent::query()->where('entity_id', $eventId)->where('action', 'event.capacity_changed')->count());

        $this->patchJson("/api/organizer/events/{$eventId}/publish")->assertOk();
        $this->patchJson("/api/organizer/events/{$eventId}/unpublish")->assertOk();
        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/events/{$eventId}/suspend")->assertOk();
        $this->patchJson("/api/super-admin/events/{$eventId}/reactivate")->assertOk();
        $this->actingAs($organizer, 'sanctum')->patchJson("/api/organizer/events/{$eventId}/cancel", [
            'cancellation_reason' => 'Reason excluded from audit metadata.',
        ])->assertOk();

        foreach ([
            'event.published', 'event.unpublished', 'event.suspended', 'event.reactivated', 'event.cancelled',
        ] as $action) {
            $this->assertSame(1, AuditEvent::query()->where('entity_id', $eventId)->where('action', $action)->count(), $action);
        }
        $cancel = AuditEvent::query()->where('entity_id', $eventId)->where('action', 'event.cancelled')->sole();
        $this->assertArrayNotHasKey('cancellation_reason', $cancel->metadata);
    }

    public function test_super_admin_event_cancellation_records_the_admin_actor_and_entity(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/events/{$event->id}/cancel", [
            'cancellation_reason' => 'Super Admin reason excluded from audit metadata.',
        ])->assertOk();

        $audit = AuditEvent::query()->where('action', 'event.cancelled')->where('entity_id', $event->id)->sole();

        $this->assertSame('user', $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(UserRole::SuperAdmin->value, $audit->actor_role);
        $this->assertSame('event', $audit->entity_type);
        $this->assertSame($event->id, $audit->entity_id);
        $this->assertSame('success', $audit->outcome);
        $this->assertSame([
            'previous_status' => EventStatus::Published->value,
            'new_status' => EventStatus::Cancelled->value,
        ], $audit->metadata);
        $this->assertArrayNotHasKey('cancellation_reason', $audit->metadata);
    }

    public function test_ticket_staff_and_reservation_lifecycle_actions_are_recorded(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $staff = $this->makeUser(UserRole::Staff);
        $event = $this->makeEvent($organizer, EventStatus::Published);

        $ticketType = $this->actingAs($organizer, 'sanctum')->postJson("/api/organizer/events/{$event->id}/ticket-types", $this->ticketTypeData())
            ->assertCreated()->json('data');
        $ticketTypeId = $ticketType['id'];
        $this->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketTypeId}", ['price' => '12.50'])->assertOk();
        $this->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketTypeId}", ['is_active' => false])->assertOk();
        $this->patchJson("/api/organizer/events/{$event->id}/ticket-types/{$ticketTypeId}", ['is_active' => true])->assertOk();

        $unusedType = $this->postJson("/api/organizer/events/{$event->id}/ticket-types", [...$this->ticketTypeData(), 'name' => 'Unused'])
            ->assertCreated()->json('data');
        $this->deleteJson("/api/organizer/events/{$event->id}/ticket-types/{$unusedType['id']}")->assertOk();

        $assignment = $this->postJson("/api/organizer/events/{$event->id}/staff", ['staff_user_id' => $staff->id])
            ->assertCreated()->json('data');
        $this->deleteJson("/api/organizer/events/{$event->id}/staff/{$assignment['id']}")->assertOk();

        $type = TicketType::query()->findOrFail($ticketTypeId);
        $reservation = $this->reserve($client, $event, $type);
        $this->actingAs($client, 'sanctum')->patchJson("/api/reservations/{$reservation->id}/cancel")->assertOk();

        $secondReservation = $this->reserve($client, $event, $type);
        $secondReservation->forceFill(['payment_expires_at' => now()->subMinute()])->save();
        $this->assertTrue(app(ReservationService::class)->expire($secondReservation));
        $this->assertFalse(app(ReservationService::class)->expire($secondReservation));

        $this->assertSame(2, AuditEvent::query()->where('action', 'ticket_type.created')->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'ticket_type.updated')->where('entity_id', $ticketTypeId)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'ticket_type.deactivated')->where('entity_id', $ticketTypeId)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'ticket_type.deleted')->where('entity_id', $unusedType['id'])->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'staff_assignment.created')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'staff_assignment.removed')->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'reservation.created')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'reservation.cancelled')->count());

        $expired = AuditEvent::query()->where('action', 'reservation.expired')->sole();
        $this->assertSame('system', $expired->actor_type);
        $this->assertNull($expired->actor_id);
        $this->assertNull($expired->actor_role);
        $this->assertSame('reservations:expire-pending', $expired->metadata['source']);
        $this->assertSame(ReservationStatus::Expired, $secondReservation->fresh()->status);
    }

    public function test_demo_payment_outcomes_are_distinct_and_success_is_not_duplicated_on_retry(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);

        $successfulReservation = $this->reserve($client, $event, $ticketType);
        $payment = $this->actingAs($client, 'sanctum')->postJson("/api/reservations/{$successfulReservation->id}/payments")
            ->assertCreated()->json('data');
        $this->postJson("/api/payments/{$payment['id']}/simulate-success")->assertOk();
        $this->postJson("/api/payments/{$payment['id']}/simulate-success")->assertOk();

        $failedReservation = $this->reserve($client, $event, $ticketType);
        $failedPayment = $this->postJson("/api/reservations/{$failedReservation->id}/payments")
            ->assertCreated()->json('data');
        $this->postJson("/api/payments/{$failedPayment['id']}/simulate-failure")->assertOk();

        $successAudit = AuditEvent::query()->where('action', 'demo_payment.simulation_succeeded')->sole();
        $this->assertSame('success', $successAudit->outcome);
        $this->assertSame('demo', $successAudit->metadata['payment_mode']);
        $this->assertSame(1, AuditEvent::query()->where('action', 'reservation.confirmed')->count());
        $this->assertSame('success', AuditEvent::query()->where('action', 'reservation.confirmed')->sole()->outcome);

        $failedAudit = AuditEvent::query()->where('action', 'demo_payment.simulation_failed')->sole();
        $this->assertSame('failed', $failedAudit->outcome);
        $this->assertSame('failed', $failedReservation->fresh()->payment_status->value);
        $this->assertSame(ReservationStatus::PendingPayment, $failedReservation->fresh()->status);
        $this->assertSame(TicketStatus::Pending, $failedReservation->tickets()->firstOrFail()->fresh()->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'demo_payment.simulation_succeeded')->count());
    }

    public function test_audit_insert_failure_rolls_back_reservation_and_event_creation(): void
    {
        $organizer = $this->makeUser(UserRole::Organizer);
        $client = $this->makeUser(UserRole::Client);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);

        $recorder = $this->mock(AuditRecorder::class);
        $recorder->shouldReceive('recordUserAction')->twice()->andThrow(new RuntimeException('Audit insert failed.'));

        try {
            app(ReservationService::class)->create($client, $event, [[
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
            ]]);
            $this->fail('The audit exception should abort reservation creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit insert failed.', $exception->getMessage());
        }

        $this->assertSame(0, Reservation::query()->where('event_id', $event->id)->count());
        $this->assertSame(5, $ticketType->fresh()->available_quantity);
        $this->assertSame(0, AuditEvent::query()->count());

        $existingEventCount = Event::query()->where('title', 'Audit transaction event')->count();
        $response = $this->actingAs($organizer, 'sanctum')->postJson('/api/organizer/events', $this->eventData());
        $response->assertServerError();
        $this->assertSame($existingEventCount, Event::query()->where('title', 'Audit transaction event')->count());
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_mysql_connections_and_recorder_use_utc_timestamps(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));

        $actor = $this->makeUser(UserRole::SuperAdmin);
        $previousTimezone = date_default_timezone_get();
        $before = time();

        try {
            date_default_timezone_set('Pacific/Honolulu');
            app(AuditRecorder::class)->recordUserAction($actor, 'event.created', 123, metadata: [
                'status' => EventStatus::Draft->value,
            ]);
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $stored = DB::table('audit_events')->value('occurred_at');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stored, new \DateTimeZone('UTC'));

        $this->assertNotFalse($parsed);
        $this->assertGreaterThanOrEqual($before, $parsed->getTimestamp());
        $this->assertLessThanOrEqual($before + 2, $parsed->getTimestamp());
    }

    public function test_non_utc_db_url_timezone_cannot_override_mysql_or_mariadb_configuration(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            $configuration = config("database.connections.{$driver}");
            $configuration['url'] = DatabaseUrl::withoutTimezoneOverride(
                "{$driver}://test-user@127.0.0.1/eventpass?charset=utf8mb4&timezone=%2B05%3A00&strict=true",
            );

            $resolved = (new ConfigurationUrlParser)->parseConfiguration($configuration);

            $this->assertSame('+00:00', $resolved['timezone']);
            $this->assertSame($driver, $resolved['driver']);
            $this->assertSame('127.0.0.1', $resolved['host']);
            $this->assertSame('eventpass', $resolved['database']);
            $this->assertSame('utf8mb4', $resolved['charset']);
            $this->assertTrue($resolved['strict']);
        }
    }

    public function test_metadata_schemas_require_fields_and_reject_invalid_enum_and_numeric_values(): void
    {
        $actor = $this->makeUser(UserRole::SuperAdmin);
        $recorder = app(AuditRecorder::class);

        $this->assertRecorderRejects(fn () => $recorder->recordSystemAction('reservation.expired', 1, metadata: [
            'event_id' => 1,
            'item_count' => 1,
            'total_quantity' => 1,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'event.created', 1, metadata: [
            'status' => 'personal-data-that-is-not-an-event-status',
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'user.suspended', 1, metadata: [
            'previous_status' => 'active',
            'new_status' => 'suspended',
            'target_role' => 'not-a-project-role',
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'user.suspended', 1, metadata: [
            'previous_status' => 'active',
            'new_status' => 'suspended',
            'target_role' => UserRole::Client->value,
            'password' => 'not-allowed',
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.deleted', 1, metadata: [
            'event_id' => 0,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'staff_assignment.created', 1, metadata: [
            'event_id' => -1,
            'staff_user_id' => 2,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.created', 1, metadata: [
            'event_id' => 1,
            'quantity' => 0,
            'is_active' => true,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.created', 1, metadata: [
            'event_id' => 1,
            'quantity' => -5,
            'is_active' => true,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.created', 1, metadata: [
            'event_id' => '1',
            'quantity' => 5,
            'is_active' => true,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.deleted', 1, metadata: [
            'event_id' => 1.5,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.created', 1, metadata: [
            'event_id' => 1,
            'quantity' => '5',
            'is_active' => true,
        ]));
        $this->assertRecorderRejects(fn () => $recorder->recordUserAction($actor, 'ticket_type.created', 1, metadata: [
            'event_id' => 1,
            'quantity' => 1.5,
            'is_active' => true,
        ]));

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_every_supported_action_accepts_its_valid_minimal_metadata(): void
    {
        $actor = $this->makeUser(UserRole::SuperAdmin);
        $cases = [
            ['user.suspended', 'success', ['previous_status' => 'active', 'new_status' => 'suspended', 'target_role' => 'client']],
            ['user.reactivated', 'success', ['previous_status' => 'suspended', 'new_status' => 'active', 'target_role' => 'staff']],
            ['organizer_request.approved', 'success', ['applicant_user_id' => 1, 'previous_status' => 'pending', 'new_status' => 'approved', 'previous_role' => 'client', 'new_role' => 'organizer']],
            ['organizer_request.rejected', 'success', ['applicant_user_id' => 1, 'previous_status' => 'pending', 'new_status' => 'rejected', 'applicant_role' => 'client']],
            ['event.created', 'success', ['status' => 'draft']],
            ['event.updated', 'success', ['changed_fields' => ['description']]],
            ['event.capacity_changed', 'success', ['previous_capacity' => 10, 'new_capacity' => 12, 'changed_fields' => ['capacity']]],
            ['event.published', 'success', ['previous_status' => 'draft', 'new_status' => 'published']],
            ['event.unpublished', 'success', ['previous_status' => 'published', 'new_status' => 'draft']],
            ['event.cancelled', 'success', ['previous_status' => 'published', 'new_status' => 'cancelled']],
            ['event.suspended', 'success', ['previous_status' => 'draft', 'new_status' => 'suspended']],
            ['event.reactivated', 'success', ['previous_status' => 'suspended', 'new_status' => 'draft']],
            ['ticket_type.created', 'success', ['event_id' => 1, 'quantity' => 5, 'is_active' => true]],
            ['ticket_type.updated', 'success', ['event_id' => 1, 'changed_fields' => ['price']]],
            ['ticket_type.deactivated', 'success', ['event_id' => 1, 'changed_fields' => ['is_active']]],
            ['ticket_type.deleted', 'success', ['event_id' => 1]],
            ['staff_assignment.created', 'success', ['event_id' => 1, 'staff_user_id' => 2]],
            ['staff_assignment.removed', 'success', ['event_id' => 1, 'staff_user_id' => 2]],
            ['reservation.created', 'success', ['event_id' => 1, 'item_count' => 1, 'total_quantity' => 1]],
            ['reservation.cancelled', 'success', ['event_id' => 1, 'item_count' => 1, 'total_quantity' => 1]],
            ['reservation.expired', 'success', ['event_id' => 1, 'item_count' => 1, 'total_quantity' => 1, 'source' => 'reservations:expire-pending'], 'system'],
            ['reservation.confirmed', 'success', ['event_id' => 1, 'payment_mode' => 'demo', 'payment_id' => 2, 'previous_status' => 'pending_payment', 'new_status' => 'confirmed']],
            ['demo_payment.simulation_succeeded', 'success', ['reservation_id' => 1, 'payment_mode' => 'demo', 'previous_status' => 'pending', 'new_status' => 'succeeded']],
            ['demo_payment.simulation_failed', 'failed', ['reservation_id' => 1, 'payment_mode' => 'demo', 'previous_status' => 'pending', 'new_status' => 'failed']],
        ];

        foreach ($cases as $case) {
            [$action, $outcome, $metadata] = $case;
            $actorType = $case[3] ?? 'user';
            $event = $actorType === 'system'
                ? app(AuditRecorder::class)->recordSystemAction($action, 99, $outcome, $metadata)
                : app(AuditRecorder::class)->recordUserAction($actor, $action, 99, $outcome, $metadata);

            $this->assertSame($action, $event->action);
            $this->assertSame($outcome, $event->outcome);
        }

        $this->assertCount(count($cases), AuditEvent::query()->get());
    }

    public function test_failed_authorization_and_conflict_transitions_do_not_add_duplicate_audit_events(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $client = $this->makeUser(UserRole::Client);
        $target = $this->makeUser(UserRole::Client);
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Draft);

        $this->actingAs($client, 'sanctum')->patchJson("/api/super-admin/users/{$target->id}/suspend", [
            'suspension_reason' => 'Unauthorized attempt.',
        ])->assertForbidden();
        $this->assertSame(0, AuditEvent::query()->count());

        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/users/{$target->id}/suspend", [
            'suspension_reason' => 'Authorized transition.',
        ])->assertOk();
        $this->patchJson("/api/super-admin/users/{$target->id}/suspend", [
            'suspension_reason' => 'Repeated transition.',
        ])->assertConflict();
        $this->assertSame(1, AuditEvent::query()->where('action', 'user.suspended')->where('entity_id', $target->id)->count());

        $this->actingAs($organizer, 'sanctum')->patchJson("/api/organizer/events/{$event->id}/publish")->assertOk();
        $this->patchJson("/api/organizer/events/{$event->id}/publish")->assertConflict();
        $this->assertSame(1, AuditEvent::query()->where('action', 'event.published')->where('entity_id', $event->id)->count());
    }

    public function test_audit_failure_rolls_back_account_suspension_and_token_revocation(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin);
        $target = $this->makeUser(UserRole::Client);
        $token = $target->createToken('suspension-rollback')->plainTextToken;

        $this->mock(AuditRecorder::class)
            ->shouldReceive('recordUserAction')
            ->once()
            ->andThrow(new RuntimeException('Audit insert failed.'));

        $this->actingAs($admin, 'sanctum')->patchJson("/api/super-admin/users/{$target->id}/suspend", [
            'suspension_reason' => 'Rollback verification.',
        ])->assertServerError();

        $target->refresh();
        $this->assertSame('active', $target->account_status->value);
        $this->assertNull($target->suspended_at);
        $this->assertSame(1, $target->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $target->id]);
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertNotEmpty($token);
    }

    public function test_audit_failure_rolls_back_demo_payment_activation(): void
    {
        $client = $this->makeUser(UserRole::Client);
        $organizer = $this->makeUser(UserRole::Organizer);
        $event = $this->makeEvent($organizer, EventStatus::Published);
        $ticketType = $this->makeTicketType($event);
        $reservation = $this->reserve($client, $event, $ticketType);
        $payment = $this->actingAs($client, 'sanctum')->postJson("/api/reservations/{$reservation->id}/payments")
            ->assertCreated()->json('data');

        $this->mock(AuditRecorder::class)
            ->shouldReceive('recordUserAction')
            ->once()
            ->andThrow(new RuntimeException('Audit insert failed.'));

        $this->postJson("/api/payments/{$payment['id']}/simulate-success")->assertServerError();

        $this->assertSame(PaymentAttemptStatus::Pending, $reservation->payments()->findOrFail($payment['id'])->fresh()->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $reservation->fresh()->payment_status);
        $this->assertSame(TicketStatus::Pending, $reservation->tickets()->firstOrFail()->fresh()->status);
        $this->assertSame(0, AuditEvent::query()->whereIn('action', [
            'demo_payment.simulation_succeeded', 'reservation.confirmed',
        ])->count());
    }

    public function test_metadata_is_allowlisted_and_audit_models_reject_mutation(): void
    {
        $actor = $this->makeUser(UserRole::SuperAdmin);

        try {
            app(AuditRecorder::class)->recordUserAction($actor, 'user.suspended', 99, metadata: [
                'previous_status' => 'active',
                'new_status' => 'suspended',
                'target_role' => 'client',
                'password' => 'must never be stored',
            ]);
            $this->fail('Unexpected metadata must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, AuditEvent::query()->count());
        }

        $audit = app(AuditRecorder::class)->recordUserAction($actor, 'user.suspended', 99, metadata: [
            'previous_status' => 'active',
            'new_status' => 'suspended',
            'target_role' => 'client',
        ]);
        try {
            $audit->update(['outcome' => 'failed']);
            $this->fail('Audit rows must reject model updates.');
        } catch (LogicException) {
            $this->assertSame('success', $audit->fresh()->outcome);
        }
        try {
            $audit->delete();
            $this->fail('Audit rows must reject model deletion.');
        } catch (LogicException) {
            $this->assertNotNull($audit->fresh());
        }

        try {
            AuditEvent::query()->create([
                'occurred_at' => now('UTC'),
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'actor_role' => UserRole::SuperAdmin->value,
                'action' => 'user.suspended',
                'entity_type' => 'user',
                'entity_id' => 99,
                'outcome' => 'success',
                'metadata' => ['password' => 'unvalidated'],
            ]);
            $this->fail('Audit events must be created through the validated recorder.');
        } catch (LogicException) {
            $this->assertSame(1, AuditEvent::query()->count());
        }
    }

    private function assertRecorderRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid audit metadata should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, AuditEvent::query()->count());
        }
    }

    private function makeUser(UserRole $role): User
    {
        $user = User::query()->create([
            'name' => ucfirst(str_replace('_', ' ', $role->value)),
            'email' => 'audit-'.Str::uuid().'@example.test',
            'password' => 'test-password',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function makeOrganizerRequest(User $client): OrganizerRequest
    {
        return $client->organizerRequests()->create([
            'organization_name' => 'Audit organization '.Str::ulid(),
            'description' => 'Application data used in an isolated feature test.',
            'contact_email' => $client->email,
            'contact_phone' => '+212600000000',
            'status' => OrganizerRequestStatus::Pending,
        ]);
    }

    private function makeEvent(User $organizer, EventStatus $status): Event
    {
        $event = new Event($this->eventData());
        $event->slug = 'audit-event-'.Str::lower((string) Str::ulid());
        $event->status = $status;
        $organizer->events()->save($event);

        return $event->fresh();
    }

    private function eventData(): array
    {
        return [
            'title' => 'Audit transaction event',
            'description' => 'A future event used by audit recording tests.',
            'category' => 'Music',
            'venue_name' => 'Test hall',
            'address' => '1 Test Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
            'start_at' => now()->addDays(3)->toISOString(),
            'end_at' => now()->addDays(3)->addHours(2)->toISOString(),
            'capacity' => 100,
            'cover_image' => null,
        ];
    }

    private function makeTicketType(Event $event): TicketType
    {
        $ticketType = new TicketType($this->ticketTypeData());
        $ticketType->available_quantity = 5;
        $event->ticketTypes()->save($ticketType);

        return $ticketType->fresh();
    }

    private function ticketTypeData(): array
    {
        return [
            'name' => 'General admission',
            'description' => 'Test ticket type.',
            'price' => '10.00',
            'quantity' => 5,
            'sales_start_at' => null,
            'sales_end_at' => null,
            'is_active' => true,
        ];
    }

    private function reserve(User $client, Event $event, TicketType $ticketType): Reservation
    {
        $response = $this->actingAs($client, 'sanctum')->postJson("/api/events/{$event->id}/reservations", [
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
        ])->assertCreated();

        return Reservation::query()->findOrFail($response->json('data.id'));
    }
}
