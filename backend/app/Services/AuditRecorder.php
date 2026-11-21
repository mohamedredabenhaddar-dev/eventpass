<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Enums\OrganizerRequestStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AuditRecorder
{
    /**
     * Each action declares its actor, entity, outcomes, required/optional metadata,
     * and a validator for every accepted metadata value.
     */
    private const ACTIONS = [
        'user.suspended' => ['actor' => 'user', 'entity' => 'user', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status', 'target_role'], 'optional' => [], 'fields' => ['previous_status' => 'account_status', 'new_status' => 'account_status', 'target_role' => 'role']],
        'user.reactivated' => ['actor' => 'user', 'entity' => 'user', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status', 'target_role'], 'optional' => [], 'fields' => ['previous_status' => 'account_status', 'new_status' => 'account_status', 'target_role' => 'role']],
        'organizer_request.approved' => ['actor' => 'user', 'entity' => 'organizer_request', 'outcomes' => ['success'], 'required' => ['applicant_user_id', 'previous_status', 'new_status', 'previous_role', 'new_role'], 'optional' => [], 'fields' => ['applicant_user_id' => 'id', 'previous_status' => 'organizer_request_status', 'new_status' => 'organizer_request_status', 'previous_role' => 'role', 'new_role' => 'role']],
        'organizer_request.rejected' => ['actor' => 'user', 'entity' => 'organizer_request', 'outcomes' => ['success'], 'required' => ['applicant_user_id', 'previous_status', 'new_status', 'applicant_role'], 'optional' => [], 'fields' => ['applicant_user_id' => 'id', 'previous_status' => 'organizer_request_status', 'new_status' => 'organizer_request_status', 'applicant_role' => 'role']],
        'event.created' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['status'], 'optional' => [], 'fields' => ['status' => 'event_status']],
        'event.updated' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['changed_fields'], 'optional' => [], 'fields' => ['changed_fields' => 'event_fields']],
        'event.capacity_changed' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['previous_capacity', 'new_capacity', 'changed_fields'], 'optional' => [], 'fields' => ['previous_capacity' => 'positive_integer', 'new_capacity' => 'positive_integer', 'changed_fields' => 'event_fields']],
        'event.published' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status'], 'optional' => [], 'fields' => ['previous_status' => 'event_status', 'new_status' => 'event_status']],
        'event.unpublished' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status'], 'optional' => [], 'fields' => ['previous_status' => 'event_status', 'new_status' => 'event_status']],
        'event.cancelled' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status'], 'optional' => [], 'fields' => ['previous_status' => 'event_status', 'new_status' => 'event_status']],
        'event.suspended' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status'], 'optional' => [], 'fields' => ['previous_status' => 'event_status', 'new_status' => 'event_status']],
        'event.reactivated' => ['actor' => 'user', 'entity' => 'event', 'outcomes' => ['success'], 'required' => ['previous_status', 'new_status'], 'optional' => [], 'fields' => ['previous_status' => 'event_status', 'new_status' => 'event_status']],
        'ticket_type.created' => ['actor' => 'user', 'entity' => 'ticket_type', 'outcomes' => ['success'], 'required' => ['event_id', 'quantity', 'is_active'], 'optional' => [], 'fields' => ['event_id' => 'id', 'quantity' => 'positive_integer', 'is_active' => 'boolean']],
        'ticket_type.updated' => ['actor' => 'user', 'entity' => 'ticket_type', 'outcomes' => ['success'], 'required' => ['event_id', 'changed_fields'], 'optional' => [], 'fields' => ['event_id' => 'id', 'changed_fields' => 'ticket_type_fields']],
        'ticket_type.deactivated' => ['actor' => 'user', 'entity' => 'ticket_type', 'outcomes' => ['success'], 'required' => ['event_id', 'changed_fields'], 'optional' => [], 'fields' => ['event_id' => 'id', 'changed_fields' => 'ticket_type_fields']],
        'ticket_type.deleted' => ['actor' => 'user', 'entity' => 'ticket_type', 'outcomes' => ['success'], 'required' => ['event_id'], 'optional' => [], 'fields' => ['event_id' => 'id']],
        'staff_assignment.created' => ['actor' => 'user', 'entity' => 'event_staff_assignment', 'outcomes' => ['success'], 'required' => ['event_id', 'staff_user_id'], 'optional' => [], 'fields' => ['event_id' => 'id', 'staff_user_id' => 'id']],
        'staff_assignment.removed' => ['actor' => 'user', 'entity' => 'event_staff_assignment', 'outcomes' => ['success'], 'required' => ['event_id', 'staff_user_id'], 'optional' => [], 'fields' => ['event_id' => 'id', 'staff_user_id' => 'id']],
        'reservation.created' => ['actor' => 'user', 'entity' => 'reservation', 'outcomes' => ['success'], 'required' => ['event_id', 'item_count', 'total_quantity'], 'optional' => [], 'fields' => ['event_id' => 'id', 'item_count' => 'positive_integer', 'total_quantity' => 'positive_integer']],
        'reservation.cancelled' => ['actor' => 'user', 'entity' => 'reservation', 'outcomes' => ['success'], 'required' => ['event_id', 'item_count', 'total_quantity'], 'optional' => [], 'fields' => ['event_id' => 'id', 'item_count' => 'positive_integer', 'total_quantity' => 'positive_integer']],
        'reservation.expired' => ['actor' => 'system', 'entity' => 'reservation', 'outcomes' => ['success'], 'required' => ['event_id', 'item_count', 'total_quantity', 'source'], 'optional' => [], 'fields' => ['event_id' => 'id', 'item_count' => 'positive_integer', 'total_quantity' => 'positive_integer', 'source' => 'expiration_source']],
        'reservation.confirmed' => ['actor' => 'user', 'entity' => 'reservation', 'outcomes' => ['success'], 'required' => ['event_id', 'payment_mode', 'payment_id', 'previous_status', 'new_status'], 'optional' => [], 'fields' => ['event_id' => 'id', 'payment_mode' => 'demo', 'payment_id' => 'id', 'previous_status' => 'reservation_status', 'new_status' => 'reservation_status']],
        'demo_payment.simulation_succeeded' => ['actor' => 'user', 'entity' => 'payment', 'outcomes' => ['success'], 'required' => ['reservation_id', 'payment_mode', 'previous_status', 'new_status'], 'optional' => [], 'fields' => ['reservation_id' => 'id', 'payment_mode' => 'demo', 'previous_status' => 'payment_attempt_status', 'new_status' => 'payment_attempt_status']],
        'demo_payment.simulation_failed' => ['actor' => 'user', 'entity' => 'payment', 'outcomes' => ['failed'], 'required' => ['reservation_id', 'payment_mode', 'previous_status', 'new_status'], 'optional' => [], 'fields' => ['reservation_id' => 'id', 'payment_mode' => 'demo', 'previous_status' => 'payment_attempt_status', 'new_status' => 'payment_attempt_status']],
        'organizer_profile.updated' => ['actor' => 'user', 'entity' => 'organizer_profile', 'outcomes' => ['success'], 'required' => [], 'optional' => [], 'fields' => []],
    ];

    /** @return array{actions:list<string>, actor_types:list<string>, entity_types:list<string>, outcomes:list<string>} */
    public static function supportedFilterValues(): array
    {
        return [
            'actions' => array_keys(self::ACTIONS),
            'actor_types' => array_values(array_unique(array_column(self::ACTIONS, 'actor'))),
            'entity_types' => array_values(array_unique(array_column(self::ACTIONS, 'entity'))),
            'outcomes' => array_values(array_unique(array_merge(...array_column(self::ACTIONS, 'outcomes')))),
        ];
    }

    /** Return only fully validated metadata approved for this action. */
    public static function safeMetadataForResponse(string $action, mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $specification = self::ACTIONS[$action] ?? null;
        if (! is_array($metadata) || $specification === null) {
            return null;
        }

        $approvedKeys = array_flip([...$specification['required'], ...$specification['optional']]);
        $metadata = array_intersect_key($metadata, $approvedKeys);

        try {
            self::validateMetadata($action, $metadata, $specification);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $metadata;
    }

    public function recordUserAction(
        User $actor,
        string $action,
        int $entityId,
        string $outcome = 'success',
        array $metadata = [],
    ): AuditEvent {
        return $this->record('user', $actor, $action, $entityId, $outcome, $metadata);
    }

    public function recordSystemAction(
        string $action,
        int $entityId,
        string $outcome = 'success',
        array $metadata = [],
    ): AuditEvent {
        return $this->record('system', null, $action, $entityId, $outcome, $metadata);
    }

    private function record(
        string $actorType,
        ?User $actor,
        string $action,
        int $entityId,
        string $outcome,
        array $metadata,
    ): AuditEvent {
        $specification = self::ACTIONS[$action] ?? throw new InvalidArgumentException('Unsupported audit action.');

        if ($specification['actor'] !== $actorType || ! in_array($outcome, $specification['outcomes'], true)) {
            throw new InvalidArgumentException('Audit actor type or outcome is invalid for this action.');
        }

        if ($entityId <= 0) {
            throw new InvalidArgumentException('Audit entity ID must be positive.');
        }

        $actorRole = $actor?->role;
        if ($actorRole instanceof BackedEnum) {
            $actorRole = $actorRole->value;
        }
        if ($actorType === 'user' && (! $actor?->exists || ! is_string($actorRole) || UserRole::tryFrom($actorRole) === null)) {
            throw new InvalidArgumentException('User audit actions require a persisted actor with a valid role.');
        }

        self::validateMetadata($action, $metadata, $specification);

        $attributes = [
            'occurred_at' => now('UTC'),
            'actor_type' => $actorType,
            'actor_id' => $actor?->getKey(),
            'actor_role' => $actorRole,
            'action' => $action,
            'entity_type' => $specification['entity'],
            'entity_id' => $entityId,
            'outcome' => $outcome,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ];

        // Use the query builder only inside this recorder. AuditEvent blocks direct
        // Eloquent creation as well as model updates and deletes.
        $id = DB::table('audit_events')->insertGetId($attributes);

        return AuditEvent::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $metadata @param array<string, mixed> $specification */
    private static function validateMetadata(string $action, array $metadata, array $specification): void
    {
        $keys = array_keys($metadata);
        $missing = array_diff($specification['required'], $keys);
        $unexpected = array_diff($keys, [...$specification['required'], ...$specification['optional']]);

        if ($missing !== []) {
            throw new InvalidArgumentException('Audit metadata is missing required fields: '.implode(', ', $missing).'.');
        }
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Audit metadata contains fields that are not allowed for this action.');
        }

        $eventFields = ['title', 'description', 'category', 'venue_name', 'address', 'city', 'country', 'start_at', 'end_at', 'capacity', 'cover_image', 'slug'];
        $ticketTypeFields = ['name', 'description', 'price', 'quantity', 'available_quantity', 'sales_start_at', 'sales_end_at', 'is_active'];

        foreach ($metadata as $key => $value) {
            $type = $specification['fields'][$key];
            $valid = match ($type) {
                'id' => is_int($value) && $value > 0,
                'positive_integer' => is_int($value) && $value > 0,
                'boolean' => is_bool($value),
                'account_status' => self::isEnumValue($value, AccountStatus::class),
                'event_status' => self::isEnumValue($value, EventStatus::class),
                'organizer_request_status' => self::isEnumValue($value, OrganizerRequestStatus::class),
                'reservation_status' => self::isEnumValue($value, ReservationStatus::class),
                'payment_attempt_status' => self::isEnumValue($value, PaymentAttemptStatus::class),
                'role' => self::isEnumValue($value, UserRole::class),
                'demo' => $value === 'demo',
                'expiration_source' => $value === 'reservations:expire-pending',
                'event_fields' => self::isAllowedFieldList($value, $eventFields),
                'ticket_type_fields' => self::isAllowedFieldList($value, $ticketTypeFields),
                default => false,
            };

            if (! $valid) {
                throw new InvalidArgumentException("Audit metadata field '{$key}' has an invalid value.");
            }
        }
    }

    /** @param class-string<BackedEnum> $enum */
    private static function isEnumValue(mixed $value, string $enum): bool
    {
        return is_string($value) && $enum::tryFrom($value) !== null;
    }

    private static function isAllowedFieldList(mixed $value, array $allowed): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $field) {
            if (! is_string($field) || ! in_array($field, $allowed, true)) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }
}
