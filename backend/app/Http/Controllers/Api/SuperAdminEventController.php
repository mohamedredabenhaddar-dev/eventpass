<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminEventController extends Controller
{
    public function index()
    {
        return EventResource::collection(Event::with('organizer:id,name')->latest()->paginate(15));
    }

    public function show(Event $event): EventResource
    {
        return new EventResource($event->load('organizer:id,name'));
    }

    public function suspend(Request $request, Event $event, AuditRecorder $audit): EventResource
    {
        $event = DB::transaction(function () use ($request, $event, $audit) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless(in_array($event->status, [EventStatus::Draft, EventStatus::Published], true), 409, 'Only draft or published events can be suspended.');

            $previousStatus = $event->status->value;

            $event->status = EventStatus::Suspended;
            $event->suspended_at = now();
            $event->suspended_by = $request->user()->getKey();
            $event->save();

            $audit->recordUserAction($request->user(), 'event.suspended', $event->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'new_status' => EventStatus::Suspended->value,
            ]);

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    public function reactivate(Request $request, Event $event, AuditRecorder $audit): EventResource
    {
        $event = DB::transaction(function () use ($request, $event, $audit) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->status === EventStatus::Suspended, 409, 'Only suspended events can be reactivated.');

            $previousStatus = $event->status->value;

            $event->status = EventStatus::Draft;
            $event->save();

            $audit->recordUserAction($request->user(), 'event.reactivated', $event->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'new_status' => EventStatus::Draft->value,
            ]);

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    public function cancel(CancelEventRequest $request, Event $event, AuditRecorder $audit): EventResource
    {
        $event = DB::transaction(function () use ($request, $event, $audit) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->status !== EventStatus::Cancelled, 409, 'This event is already cancelled.');

            $previousStatus = $event->status->value;

            $event->status = EventStatus::Cancelled;
            $event->cancellation_reason = $request->validated('cancellation_reason');
            $event->save();

            $audit->recordUserAction($request->user(), 'event.cancelled', $event->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'new_status' => EventStatus::Cancelled->value,
            ]);

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }
}
