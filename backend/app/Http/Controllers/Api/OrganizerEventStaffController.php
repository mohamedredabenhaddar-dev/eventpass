<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignEventStaffRequest;
use App\Http\Resources\EventStaffAssignmentResource;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizerEventStaffController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->assertOwner($request, $event);

        return EventStaffAssignmentResource::collection($event->staffAssignments()->with('staff:id,name,email')->orderBy('id')->paginate(20));
    }

    public function available(Request $request, Event $event)
    {
        $this->assertOwner($request, $event);

        $search = trim((string) $request->query('search', ''));
        $assignedIds = $event->staffAssignments()->pluck('staff_user_id')->all();

        $staff = User::query()
            ->where('role', 'staff')
            ->where('account_status', 'active')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'already_assigned' => in_array($user->id, $assignedIds, true),
            ]);

        return response()->json(['data' => $staff]);
    }

    public function store(AssignEventStaffRequest $request, Event $event, AuditRecorder $audit)
    {
        $assignment = DB::transaction(function () use ($request, $event, $audit) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($lockedEvent->organizer_id === $request->user()->getKey(), 404);
            $staffId = (int) $request->validated('staff_user_id');
            abort_unless(User::query()->whereKey($staffId)->where('role', 'staff')->exists(), 422, 'The selected user is not a staff member.');
            abort_if($lockedEvent->staffAssignments()->where('staff_user_id', $staffId)->exists(), 409, 'This staff member is already assigned to the event.');

            $assignment = $lockedEvent->staffAssignments()->create([
                'staff_user_id' => $staffId,
                'assigned_by' => $request->user()->getKey(),
            ]);

            $audit->recordUserAction($request->user(), 'staff_assignment.created', $assignment->getKey(), metadata: [
                'event_id' => $lockedEvent->getKey(),
                'staff_user_id' => $staffId,
            ]);

            return $assignment->load('staff:id,name,email');
        });

        return (new EventStaffAssignmentResource($assignment))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Event $event, EventStaffAssignment $assignment, AuditRecorder $audit)
    {
        DB::transaction(function () use ($request, $event, $assignment, $audit) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($lockedEvent->organizer_id === $request->user()->getKey(), 404);
            $lockedAssignment = EventStaffAssignment::query()->where('event_id', $event->id)->lockForUpdate()->findOrFail($assignment->id);
            $staffUserId = $lockedAssignment->staff_user_id;
            $lockedAssignment->delete();

            $audit->recordUserAction($request->user(), 'staff_assignment.removed', $lockedAssignment->getKey(), metadata: [
                'event_id' => $lockedEvent->getKey(),
                'staff_user_id' => $staffUserId,
            ]);
        });

        return response()->json(['message' => 'Staff assignment removed.']);
    }

    private function assertOwner(Request $request, Event $event): void
    {
        abort_unless($event->organizer_id === $request->user()->getKey(), 404);
    }
}
