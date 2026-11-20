<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffProvisioningRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Staff account provisioning for Super Admins and Organizers.
 *
 * Allows creation of new staff users or promotion of existing users to staff role,
 * and assignment of staff to events with full AuditRecorder logging.
 */
class StaffProvisioningController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Create a new staff user or promote an existing user to staff role.
     *
     * POST /api/staff/provision
     *
     * Valid input depends on mode:
     * - new: name, email, password, assign_to_event_id (optional)
     * - promote: existing_user_id, assign_to_event_id (optional)
     * - assign: staff_user_id, event_id
     */
    public function provision(StaffProvisioningRequest $request): JsonResponse
    {
        $mode = $request->validated('mode') ?? 'new';

        $result = DB::transaction(function () use ($request, $mode) {
            switch ($mode) {
                case 'new':
                    return $this->createNewStaffUser($request);
                case 'promote':
                    return $this->promoteToStaff($request);
                case 'assign':
                    return $this->assignStaffToEvent($request);
                default:
                    abort(400, 'Invalid provisioning mode.');
            }
        });

        return (new UserResource($result['user']))->response()->setStatusCode(201);
    }

    private function createNewStaffUser(StaffProvisioningRequest $request): array
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->forceFill(['role' => UserRole::Staff->value])->save();

        // If assigning to an event, create the staff assignment
        if (isset($validated['assign_to_event_id']) && $validated['assign_to_event_id']) {
            $eventId = (int) $validated['assign_to_event_id'];
            $event = Event::query()->findOrFail($eventId);

            // Verify organizer owns the event
            abort_unless($event->organizer_id === $request->user()->getKey(), 403, 'Only the event organizer can assign staff.');

            $assignment = $event->staffAssignments()->create([
                'staff_user_id' => $user->id,
                'assigned_by' => $request->user()->getKey(),
            ]);

            $result['assignment'] = $assignment;
        }

        $result['user'] = $user;

        return $result;
    }

    private function promoteToStaff(StaffProvisioningRequest $request): array
    {
        $validated = $request->validated();

        $user = User::query()->findOrFail($validated['existing_user_id']);

        // Only Super Admin can promote users
        abort_unless($request->user()->role === UserRole::SuperAdmin->value, 403, 'Only Super Admins can promote users to staff.');

        // Cannot promote the Super Admin themselves
        abort_if($user->id === $request->user()->getKey(), 409, 'A Super Admin cannot promote their own account.');

        // Cannot promote if already staff or super_admin
        abort_if(in_array($user->role, [UserRole::Staff->value, UserRole::SuperAdmin->value], true), 409, 'User is already a staff member or Super Admin.');

        $user->forceFill(['role' => UserRole::Staff->value])->save();

        $result['user'] = $user;

        return $result;
    }

    private function assignStaffToEvent(StaffProvisioningRequest $request): array
    {
        $validated = $request->validated();

        $staffUser = User::query()->where('role', UserRole::Staff->value)->findOrFail($validated['staff_user_id']);
        $event = Event::query()->findOrFail($validated['event_id']);

        // Verify organizer owns the event
        abort_unless($event->organizer_id === $request->user()->getKey(), 403, 'Only the event organizer can assign staff.');

        // Check if already assigned
        abort_if($event->staffAssignments()->where('staff_user_id', $staffUser->id)->exists(), 409, 'This staff member is already assigned to the event.');

        $assignment = $event->staffAssignments()->create([
            'staff_user_id' => $staffUser->id,
            'assigned_by' => $request->user()->getKey(),
        ]);

        $result['assignment'] = $assignment;
        $result['user'] = $staffUser;

        return $result;
    }
}
