<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignEventStaffRequest;
use App\Http\Resources\EventStaffAssignmentResource;
use App\Models\Event;
use App\Models\EventStaffAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizerEventStaffController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->assertOwner($request, $event);

        return EventStaffAssignmentResource::collection($event->staffAssignments()->with('staff:id,name,email')->orderBy('id')->paginate(20));
    }

    public function store(AssignEventStaffRequest $request, Event $event)
    {
        $assignment = DB::transaction(function () use ($request, $event) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($lockedEvent->organizer_id === $request->user()->getKey(), 404);
            $staffId = (int) $request->validated('staff_user_id');
            abort_unless(User::query()->whereKey($staffId)->where('role', 'staff')->exists(), 422, 'The selected user is not a staff member.');
            abort_if($lockedEvent->staffAssignments()->where('staff_user_id', $staffId)->exists(), 409, 'This staff member is already assigned to the event.');

            return $lockedEvent->staffAssignments()->create([
                'staff_user_id' => $staffId,
                'assigned_by' => $request->user()->getKey(),
            ])->load('staff:id,name,email');
        });

        return (new EventStaffAssignmentResource($assignment))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Event $event, EventStaffAssignment $assignment)
    {
        DB::transaction(function () use ($request, $event, $assignment) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($lockedEvent->organizer_id === $request->user()->getKey(), 404);
            $lockedAssignment = EventStaffAssignment::query()->where('event_id', $event->id)->lockForUpdate()->findOrFail($assignment->id);
            $lockedAssignment->delete();
        });

        return response()->json(['message' => 'Staff assignment removed.']);
    }

    private function assertOwner(Request $request, Event $event): void
    {
        abort_unless($event->organizer_id === $request->user()->getKey(), 404);
    }
}
