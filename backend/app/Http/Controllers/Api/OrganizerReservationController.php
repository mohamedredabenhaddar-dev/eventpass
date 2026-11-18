<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Http\Request;

class OrganizerReservationController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->assertOwner($request, $event);

        return ReservationResource::collection(
            $event->reservations()
                ->with(['event:id,title,slug,start_at,status', 'items.ticketType:id,name'])
                ->latest()
                ->paginate(15),
        );
    }

    public function show(Request $request, Event $event, Reservation $reservation): ReservationResource
    {
        $this->assertOwner($request, $event);
        abort_unless($reservation->event_id === $event->id, 404);

        return new ReservationResource($reservation->load([
            'event:id,title,slug,start_at,status',
            'items.ticketType:id,name',
            'items.tickets',
        ]));
    }

    private function assertOwner(Request $request, Event $event): void
    {
        abort_unless($event->organizer_id === $request->user()->getKey(), 404);
    }
}
