<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketTypeRequest;
use App\Http\Requests\UpdateTicketTypeRequest;
use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\AuditRecorder;
use App\Services\EventCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizerTicketTypeController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->ownedEvent($request, $event);

        return TicketTypeResource::collection($event->ticketTypes()->orderBy('id')->paginate(20));
    }

    public function store(StoreTicketTypeRequest $request, Event $event, EventCapacityService $capacity, AuditRecorder $audit): TicketTypeResource
    {
        $ticketType = DB::transaction(function () use ($request, $event, $capacity, $audit) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->assertOwnerAndEditable($request->user()->getKey(), $lockedEvent);

            $newQuantity = (int) $request->validated('quantity');
            if ($capacity->configuredInventory($lockedEvent) + $newQuantity > $lockedEvent->capacity) {
                throw ValidationException::withMessages([
                    'quantity' => 'The combined ticket inventory cannot exceed event capacity.',
                ]);
            }

            $ticketType = new TicketType($request->validated());
            $ticketType->available_quantity = $ticketType->quantity;
            $ticketType->is_active = $request->validated('is_active', true);
            $lockedEvent->ticketTypes()->save($ticketType);

            $audit->recordUserAction($request->user(), 'ticket_type.created', $ticketType->getKey(), metadata: [
                'event_id' => $lockedEvent->getKey(),
                'quantity' => (int) $ticketType->quantity,
                'is_active' => (bool) $ticketType->is_active,
            ]);

            return $ticketType;
        });

        return new TicketTypeResource($ticketType->refresh()->load('event:id,title,slug'));
    }

    public function show(Request $request, Event $event, TicketType $ticketType): TicketTypeResource
    {
        $this->ownedTicketType($request, $event, $ticketType);

        return new TicketTypeResource($ticketType->load('event:id,title,slug'));
    }

    public function update(UpdateTicketTypeRequest $request, Event $event, TicketType $ticketType, EventCapacityService $capacity, AuditRecorder $audit): TicketTypeResource
    {
        $updated = DB::transaction(function () use ($request, $event, $ticketType, $capacity, $audit) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->assertOwnerAndEditable($request->user()->getKey(), $lockedEvent);
            $lockedType = TicketType::query()
                ->where('event_id', $lockedEvent->id)
                ->lockForUpdate()
                ->findOrFail($ticketType->id);

            $validated = $request->validated();
            $reserved = $capacity->committedInventory($lockedEvent, $lockedType->id);
            $newQuantity = $validated['quantity'] ?? $lockedType->quantity;
            abort_unless($newQuantity >= $reserved, 409, 'Quantity cannot be reduced below the number of reserved tickets.');

            $configuredWithoutType = $capacity->configuredInventory($lockedEvent) - $lockedType->quantity;
            if ($configuredWithoutType + $newQuantity > $lockedEvent->capacity) {
                throw ValidationException::withMessages([
                    'quantity' => 'The combined ticket inventory cannot exceed event capacity.',
                ]);
            }

            $wasActive = (bool) $lockedType->is_active;
            $lockedType->fill($validated);
            $lockedType->available_quantity = $newQuantity - $reserved;

            $dirty = array_keys($lockedType->getDirty());
            $changedFields = array_values(array_intersect(array_keys($validated), $dirty));
            if (in_array('quantity', $changedFields, true) && in_array('available_quantity', $dirty, true)) {
                $changedFields[] = 'available_quantity';
            }
            $changedFields = array_values(array_unique($changedFields));
            $lockedType->save();

            if ($changedFields !== []) {
                $action = $wasActive && ! (bool) $lockedType->is_active
                    ? 'ticket_type.deactivated'
                    : 'ticket_type.updated';
                $audit->recordUserAction($request->user(), $action, $lockedType->getKey(), metadata: [
                    'event_id' => $lockedEvent->getKey(),
                    'changed_fields' => $changedFields,
                ]);
            }

            return $lockedType;
        });

        return new TicketTypeResource($updated->refresh()->load('event:id,title,slug'));
    }

    public function destroy(Request $request, Event $event, TicketType $ticketType, AuditRecorder $audit)
    {
        DB::transaction(function () use ($request, $event, $ticketType, $audit) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $this->assertOwnerAndEditable($request->user()->getKey(), $lockedEvent);
            $lockedType = TicketType::query()
                ->where('event_id', $lockedEvent->id)
                ->lockForUpdate()
                ->findOrFail($ticketType->id);

            abort_if($lockedType->reservationItems()->exists(), 409, 'A ticket type with reservation history cannot be deleted.');
            $lockedType->delete();

            $audit->recordUserAction($request->user(), 'ticket_type.deleted', $lockedType->getKey(), metadata: [
                'event_id' => $lockedEvent->getKey(),
            ]);
        });

        return response()->json(['message' => 'Ticket type deleted.']);
    }

    private function ownedEvent(Request $request, Event $event): Event
    {
        abort_unless($event->organizer_id === $request->user()->getKey(), 404);

        return $event;
    }

    private function ownedTicketType(Request $request, Event $event, TicketType $ticketType): void
    {
        $this->ownedEvent($request, $event);
        abort_unless($ticketType->event_id === $event->id, 404);
    }

    private function assertOwnerAndEditable(int $userId, Event $event): void
    {
        abort_unless($event->organizer_id === $userId, 404);
        abort_unless(! in_array($event->status, [EventStatus::Suspended, EventStatus::Cancelled], true), 409, 'Ticket types cannot be changed while the event is suspended or cancelled.');
    }
}
