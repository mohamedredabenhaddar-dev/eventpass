<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelEventRequest;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\EventCapacityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizerEventController extends Controller
{
    public function index(Request $request)
    {
        $events = $request->user()->events()->with('organizer:id,name')->latest()->paginate(15);

        return EventResource::collection($events);
    }

    public function store(StoreEventRequest $request): EventResource
    {
        $validated = $request->validated();
        $base = $this->slugBase($validated['title']);

        for ($attempt = 1; $attempt <= 100; $attempt++) {
            $slug = $attempt === 1 ? $base : "{$base}-{$attempt}";

            if (Event::where('slug', $slug)->exists()) {
                continue;
            }

            try {
                $event = new Event($validated);
                $event->slug = $slug;
                $event->organizer()->associate($request->user());
                $event->save();

                return new EventResource($event->refresh()->load('organizer:id,name'));
            } catch (UniqueConstraintViolationException $exception) {
                // A concurrent insert may win the same slug after the exists check.
                if (Event::where('slug', $slug)->exists()) {
                    continue;
                }

                throw $exception;
            }
        }

        abort(409, 'A unique event slug could not be generated.');
    }

    public function show(Request $request, Event $event): EventResource
    {
        $event = $this->ownedEvent($request, $event);

        return new EventResource($event->load('organizer:id,name'));
    }

    public function update(UpdateEventRequest $request, Event $event, EventCapacityService $capacity): EventResource
    {
        $validated = $request->validated();
        $current = $this->ownedEvent($request, $event);
        $titleChanged = isset($validated['title']) && $validated['title'] !== $current->title;
        $base = $this->slugBase($validated['title'] ?? $current->title);

        for ($attempt = 1; $attempt <= ($titleChanged ? 100 : 1); $attempt++) {
            $slug = $titleChanged ? ($attempt === 1 ? $base : "{$base}-{$attempt}") : $current->slug;

            if ($titleChanged && Event::where('slug', $slug)->whereKeyNot($current->id)->exists()) {
                continue;
            }

            try {
                $event = DB::transaction(function () use ($request, $current, $validated, $slug, $capacity) {
                    $locked = Event::query()->lockForUpdate()->findOrFail($current->id);
                    abort_unless($locked->organizer_id === $request->user()->getKey(), 404);
                    abort_unless(! in_array($locked->status, [EventStatus::Suspended, EventStatus::Cancelled], true), 409, 'This event cannot be edited in its current status.');

                    if (array_key_exists('capacity', $validated)) {
                        $requestedCapacity = (int) $validated['capacity'];
                        $committed = $capacity->committedInventory($locked);
                        $configured = $capacity->configuredInventory($locked);

                        if ($requestedCapacity < $committed) {
                            throw ValidationException::withMessages([
                                'capacity' => 'Capacity cannot be lower than tickets in confirmed reservations.',
                            ]);
                        }

                        if ($requestedCapacity < $configured) {
                            throw ValidationException::withMessages([
                                'capacity' => 'Capacity cannot be lower than configured ticket inventory.',
                            ]);
                        }
                    }

                    $locked->fill($validated);
                    $locked->slug = $slug;
                    $locked->save();

                    return $locked;
                });

                return new EventResource($event->refresh()->load('organizer:id,name'));
            } catch (UniqueConstraintViolationException $exception) {
                if ($titleChanged && Event::where('slug', $slug)->whereKeyNot($current->id)->exists()) {
                    continue;
                }

                throw $exception;
            }
        }

        abort(409, 'A unique event slug could not be generated.');
    }

    public function publish(Request $request, Event $event): EventResource
    {
        $event = DB::transaction(function () use ($request, $event) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->organizer_id === $request->user()->getKey(), 404);
            abort_unless($event->status === EventStatus::Draft, 409, 'Only draft events can be published.');

            $event->status = EventStatus::Published;
            $event->save();

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    public function unpublish(Request $request, Event $event): EventResource
    {
        $event = DB::transaction(function () use ($request, $event) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->organizer_id === $request->user()->getKey(), 404);
            abort_unless($event->status === EventStatus::Published, 409, 'Only published events can be unpublished.');

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
            abort_unless($event->organizer_id === $request->user()->getKey(), 404);
            abort_unless(! in_array($event->status, [EventStatus::Suspended, EventStatus::Cancelled], true), 409, 'Suspended or cancelled events cannot be changed by organizers.');

            $event->status = EventStatus::Cancelled;
            $event->cancellation_reason = $request->validated('cancellation_reason');
            $event->save();

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    private function ownedEvent(Request $request, Event $event): Event
    {
        return $request->user()->events()->whereKey($event->id)->firstOrFail();
    }

    private function slugBase(string $title): string
    {
        $slug = Str::slug($title);
        $slug = rtrim(substr($slug, 0, 220), '-');

        return $slug !== '' ? $slug : 'event';
    }
}
