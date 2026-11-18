<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class EventCapacityService
{
    public function configuredInventory(Event $event): int
    {
        return (int) $event->ticketTypes()->sum('quantity');
    }

    public function committedInventory(Event $event, ?int $ticketTypeId = null): int
    {
        $query = DB::table('reservation_items')
            ->join('reservations', 'reservations.id', '=', 'reservation_items.reservation_id')
            ->where('reservations.event_id', $event->id)
            ->where(function ($query) {
                $query->where('reservations.status', ReservationStatus::Confirmed->value)
                    ->orWhere('reservations.status', ReservationStatus::PendingPayment->value);
            });

        if ($ticketTypeId !== null) {
            $query->where('reservation_items.ticket_type_id', $ticketTypeId);
        }

        return (int) $query->sum('reservation_items.quantity');
    }
}
