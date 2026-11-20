<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelEventRequest;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\AuditRecorder;
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

    public function store(StoreEventRequest $request, AuditRecorder $audit): EventResource
    {
        $validated = $request->validated();
        $base = $this->slugBase($validated['title']);

        for ($attempt = 1; $attempt <= 100; $attempt++) {
            $slug = $attempt === 1 ? $base : "{$base}-{$attempt}";

            if (Event::where('slug', $slug)->exists()) {
                continue;
            }

            try {
                $event = DB::transaction(function () use ($request, $validated, $slug, $audit) {
                    $event = new Event($validated);
                    $event->slug = $slug;
                    $event->organizer()->associate($request->user());
                    $event->save();

                    $audit->recordUserAction($request->user(), 'event.created', $event->getKey(), metadata: [
                        // The database supplies the draft default, which is not
                        // hydrated onto a new model instance after insert.
                        'status' => $event->status?->value ?? EventStatus::Draft->value,
                    ]);

                    return $event;
                });

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

    public function update(UpdateEventRequest $request, Event $event, EventCapacityService $capacity, AuditRecorder $audit): EventResource
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
                $event = DB::transaction(function () use ($request, $current, $validated, $slug, $capacity, $audit) {
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

                    $previousCapacity = (int) $locked->capacity;
                    $locked->fill($validated);
                    $locked->slug = $slug;

                    $dirty = array_keys($locked->getDirty());
                    $changedFields = array_values(array_intersect(array_keys($validated), $dirty));
                    if (in_array('title', $changedFields, true) && $locked->isDirty('slug')) {
                        $changedFields[] = 'slug';
                    }
                    $changedFields = array_values(array_unique($changedFields));
                    $capacityChanged = in_array('capacity', $changedFields, true);
                    $locked->save();

                    if ($changedFields !== []) {
                        if ($capacityChanged) {
                            $audit->recordUserAction($request->user(), 'event.capacity_changed', $locked->getKey(), metadata: [
                                'previous_capacity' => $previousCapacity,
                                'new_capacity' => (int) $locked->capacity,
                                'changed_fields' => $changedFields,
                            ]);
                        } else {
                            $audit->recordUserAction($request->user(), 'event.updated', $locked->getKey(), metadata: [
                                'changed_fields' => $changedFields,
                            ]);
                        }
                    }

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

    public function publish(Request $request, Event $event, AuditRecorder $audit): EventResource
    {
        $event = DB::transaction(function () use ($request, $event, $audit) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->organizer_id === $request->user()->getKey(), 404);
            abort_unless($event->status === EventStatus::Draft, 409, 'Only draft events can be published.');

            $previousStatus = $event->status->value;

            $event->status = EventStatus::Published;
            $event->save();

            $audit->recordUserAction($request->user(), 'event.published', $event->getKey(), metadata: [
                'previous_status' => $previousStatus,
                'new_status' => EventStatus::Published->value,
            ]);

            return $event;
        });

        return new EventResource($event->refresh()->load('organizer:id,name'));
    }

    public function unpublish(Request $request, Event $event, AuditRecorder $audit): EventResource
    {
        $event = DB::transaction(function () use ($request, $event, $audit) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($event->organizer_id === $request->user()->getKey(), 404);
            abort_unless($event->status === EventStatus::Published, 409, 'Only published events can be unpublished.');

            $previousStatus = $event->status->value;

            $event->status = EventStatus::Draft;
            $event->save();

            $audit->recordUserAction($request->user(), 'event.unpublished', $event->getKey(), metadata: [
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
            abort_unless($event->organizer_id === $request->user()->getKey(), 404);
            abort_unless(! in_array($event->status, [EventStatus::Suspended, EventStatus::Cancelled], true), 409, 'Suspended or cancelled events cannot be changed by organizers.');

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
