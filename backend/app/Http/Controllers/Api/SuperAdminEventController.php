<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
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

    public function suspend(Request $request, Event $event): EventResource
    {
        $event = DB::transaction(function () use ($request, $event) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless(in_array($event->status, [EventStatus::Draft, EventStatus::Published], true), 409, 'Only draft or published events can be suspended.');

            $event->status = EventStatus::Suspended;
            $event->suspended_at = now();
            $event->suspended_by = $request->user()->getKey();
            $event->save();

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    public function reactivate(Event $event): EventResource
    {
        $event = DB::transaction(function () use ($event) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->status === EventStatus::Suspended, 409, 'Only suspended events can be reactivated.');

            $event->status = EventStatus::Draft;
            $event->save();

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    public function cancel(CancelEventRequest $request, Event $event): EventResource
    {
        $event = DB::transaction(function () use ($request, $event) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->status !== EventStatus::Cancelled, 409, 'This event is already cancelled.');

            $event->status = EventStatus::Cancelled;
            $event->cancellation_reason = $request->validated('cancellation_reason');
            $event->save();

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }
}
