<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private readonly EventCapacityService $capacity,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<array{ticket_type_id:int, quantity:int}> $items */
    public function create(User $user, Event $event, array $items): Reservation
    {
        $reservation = DB::transaction(function () use ($user, $event, $items) {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            abort_unless($lockedEvent->status === EventStatus::Published, 409, 'Reservations are only available for published events.');
            abort_unless($lockedEvent->start_at->isFuture(), 409, 'Reservations are only available before the event starts.');

            $requestedQuantity = array_sum(array_map(fn (array $item) => (int) $item['quantity'], $items));
            $committedQuantity = $this->capacity->committedInventory($lockedEvent);
            if ($requestedQuantity > max(0, $lockedEvent->capacity - $committedQuantity)) {
                throw ValidationException::withMessages([
                    'items' => 'The event does not have enough remaining capacity.',
                ]);
            }

            $ids = collect($items)->pluck('ticket_type_id')->map(fn ($id) => (int) $id)->all();
            $ticketTypes = TicketType::query()
                ->where('event_id', $lockedEvent->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($ticketTypes->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'items' => 'All ticket types must belong to the selected event.',
                ]);
            }

            $reservation = new Reservation([
                'reference' => 'EP-'.Str::upper((string) Str::ulid()),
                'total' => '0.00',
                'status' => ReservationStatus::PendingPayment,
                'payment_status' => 'unpaid',
                'payment_expires_at' => now()->addMinutes((int) config('ticketing.reservation_expiration_minutes', 15)),
            ]);
            $reservation->user()->associate($user);
            $reservation->event()->associate($lockedEvent);
            $reservation->save();

            $totalCents = 0;
            foreach ($items as $index => $item) {
                /** @var TicketType|null $ticketType */
                $ticketType = $ticketTypes->get((int) $item['ticket_type_id']);
                if (! $ticketType) {
                    throw ValidationException::withMessages([
                        "items.{$index}.ticket_type_id" => 'The selected ticket type is unavailable for this event.',
                    ]);
                }

                $this->assertTicketTypeCanBeReserved($ticketType, $index);
                $quantity = (int) $item['quantity'];
                if ($quantity > $ticketType->available_quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'There are not enough tickets available.',
                    ]);
                }

                $unitPriceCents = $this->moneyToCents($ticketType->price);
                $subtotalCents = $unitPriceCents * $quantity;
                $totalCents += $subtotalCents;

                $ticketType->available_quantity -= $quantity;
                $ticketType->save();

                $reservationItem = new ReservationItem([
                    'quantity' => $quantity,
                    'unit_price' => $this->centsToMoney($unitPriceCents),
                    'subtotal' => $this->centsToMoney($subtotalCents),
                ]);
                $reservationItem->ticketType()->associate($ticketType);
                $reservation->items()->save($reservationItem);

                $now = now();
                $ticketRows = [];
                for ($ticketIndex = 0; $ticketIndex < $quantity; $ticketIndex++) {
                    $ticketReference = 'TKT-'.Str::upper((string) Str::ulid());
                    $ticketRows[] = [
                        'reservation_id' => $reservation->id,
                        'reservation_item_id' => $reservationItem->id,
                        'ticket_type_id' => $ticketType->id,
                        'reference' => $ticketReference,
                        'status' => TicketStatus::Pending->value,
                        'qr_token_hash' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($ticketRows, 500) as $ticketChunk) {
                    Ticket::query()->insert($ticketChunk);
                }
            }

            $reservation->total = $this->centsToMoney($totalCents);
            $reservation->save();

            $this->audit->recordUserAction($user, 'reservation.created', $reservation->getKey(), metadata: [
                'event_id' => $lockedEvent->getKey(),
                'item_count' => count($items),
                'total_quantity' => $requestedQuantity,
            ]);

            return $reservation;
        }, attempts: 3);

        $reservation = $reservation->refresh()->load(['event', 'items.ticketType', 'items.tickets']);

        return $reservation;
    }

    public function cancel(User $user, Reservation $reservation): Reservation
    {
        $reservationSnapshot = Reservation::query()->select(['id', 'event_id'])->findOrFail($reservation->id);

        $cancelled = DB::transaction(function () use ($user, $reservationSnapshot) {
            $event = Event::query()->lockForUpdate()->findOrFail($reservationSnapshot->event_id);
            $lockedReservation = Reservation::query()->lockForUpdate()->findOrFail($reservationSnapshot->id);

            abort_unless($lockedReservation->user_id === $user->getKey(), 404);
            abort_unless(in_array($lockedReservation->status, [ReservationStatus::Confirmed, ReservationStatus::PendingPayment], true), 409, 'This reservation cannot be cancelled.');
            abort_unless($lockedReservation->payment_status !== PaymentStatus::Paid, 409, 'Paid reservations cannot be cancelled because demo mode has no refund processing.');
            abort_unless($event->start_at->isFuture(), 409, 'A reservation cannot be cancelled after the event starts.');

            $items = $lockedReservation->items()->orderBy('ticket_type_id')->get();
            $ticketTypeIds = $items->pluck('ticket_type_id')->all();
            $ticketTypes = TicketType::query()
                ->where('event_id', $event->id)
                ->whereIn('id', $ticketTypeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($items->isEmpty() || $ticketTypes->count() !== $items->count()) {
                abort(409, 'Reservation inventory could not be restored consistently.');
            }

            foreach ($items as $item) {
                $ticketType = $ticketTypes->get($item->ticket_type_id);
                abort_unless($ticketType->available_quantity + $item->quantity <= $ticketType->quantity, 409, 'Reservation inventory could not be restored consistently.');
            }

            foreach ($items as $item) {
                $ticketType = $ticketTypes->get($item->ticket_type_id);
                $ticketType->available_quantity += $item->quantity;
                $ticketType->save();
            }

            $lockedReservation->status = ReservationStatus::Cancelled;
            if ($lockedReservation->payment_status === PaymentStatus::Pending) {
                $lockedReservation->payment_status = PaymentStatus::Expired;
            }
            $lockedReservation->payment_expires_at = null;
            $lockedReservation->save();
            $lockedReservation->payments()->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => now()]);
            $lockedReservation->tickets()
                ->whereIn('status', [TicketStatus::Valid->value, TicketStatus::Pending->value])
                ->update(['status' => TicketStatus::Cancelled->value, 'updated_at' => Carbon::now()]);

            $this->audit->recordUserAction($user, 'reservation.cancelled', $lockedReservation->getKey(), metadata: [
                'event_id' => $event->getKey(),
                'item_count' => $items->count(),
                'total_quantity' => (int) $items->sum('quantity'),
            ]);

            return $lockedReservation;
        }, attempts: 3);

        return $cancelled->refresh()->load(['event', 'items.ticketType', 'items.tickets']);
    }

    /** Expire an unpaid hold once and return its inventory exactly once. */
    public function expire(Reservation $reservation): bool
    {
        $snapshot = Reservation::query()->select(['id', 'event_id'])->find($reservation->id);
        if (! $snapshot) {
            return false;
        }

        return DB::transaction(function () use ($snapshot) {
            $event = Event::query()->lockForUpdate()->find($snapshot->event_id);
            $locked = Reservation::query()->lockForUpdate()->find($snapshot->id);
            if (! $event || ! $locked || $locked->status !== ReservationStatus::PendingPayment || ! $locked->payment_expires_at || $locked->payment_expires_at->isFuture()) {
                return false;
            }

            $items = $locked->items()->orderBy('ticket_type_id')->get();
            $types = TicketType::query()->where('event_id', $event->id)->whereIn('id', $items->pluck('ticket_type_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($items->isNotEmpty() && $types->count() === $items->count(), 409, 'Reservation inventory could not be restored consistently.');

            foreach ($items as $item) {
                $type = $types->get($item->ticket_type_id);
                abort_unless($type->available_quantity + $item->quantity <= $type->quantity, 409, 'Reservation inventory could not be restored consistently.');
            }
            foreach ($items as $item) {
                $type = $types->get($item->ticket_type_id);
                $type->available_quantity += $item->quantity;
                $type->save();
            }

            $locked->status = ReservationStatus::Expired;
            $locked->payment_status = PaymentStatus::Expired;
            $locked->payment_expires_at = null;
            $locked->save();
            $locked->payments()->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => now()]);
            $locked->tickets()->where('status', TicketStatus::Pending->value)
                ->update(['status' => TicketStatus::Cancelled->value, 'updated_at' => now()]);

            $this->audit->recordSystemAction('reservation.expired', $locked->getKey(), metadata: [
                'event_id' => $event->getKey(),
                'item_count' => $items->count(),
                'total_quantity' => (int) $items->sum('quantity'),
                'source' => 'reservations:expire-pending',
            ]);

            return true;
        }, attempts: 3);
    }

    private function assertTicketTypeCanBeReserved(TicketType $ticketType, int $index): void
    {
        if (! $ticketType->is_active) {
            throw ValidationException::withMessages(["items.{$index}.ticket_type_id" => 'This ticket type is inactive.']);
        }

        $now = now();
        if ($ticketType->sales_start_at && $now->lt($ticketType->sales_start_at)) {
            throw ValidationException::withMessages(["items.{$index}.ticket_type_id" => 'Ticket sales have not started.']);
        }

        if ($ticketType->sales_end_at && $now->gte($ticketType->sales_end_at)) {
            throw ValidationException::withMessages(["items.{$index}.ticket_type_id" => 'Ticket sales have ended.']);
        }
    }

    private function moneyToCents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
