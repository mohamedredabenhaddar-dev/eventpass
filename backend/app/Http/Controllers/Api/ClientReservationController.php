<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ClientReservationResource;
use App\Http\Resources\ReservationResource;
use App\Models\Event;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;

class ClientReservationController extends Controller
{
    public function index(Request $request)
    {
        return ClientReservationResource::collection(
            $request->user()->reservations()
                ->with([
                    'event:id,title,slug,category,venue_name,address,city,country,start_at,status',
                    'items.ticketType:id,name',
                    'items.tickets:id,reservation_item_id,ticket_type_id,reference,status,access_state',
                ])
                ->latest()
                ->paginate(15),
        );
    }

    public function store(StoreReservationRequest $request, Event $event, ReservationService $reservations)
    {
        $reservation = $reservations->create($request->user(), $event, $request->validated('items'));

        return (new ReservationResource($reservation))->response()->setStatusCode(201);
    }

    public function show(Request $request, Reservation $reservation): ClientReservationResource
    {
        abort_unless($reservation->user_id === $request->user()->getKey(), 404);

        return new ClientReservationResource($reservation->load([
            'event:id,title,slug,category,venue_name,address,city,country,start_at,status',
            'items.ticketType:id,name',
            'items.tickets:id,reservation_item_id,ticket_type_id,reference,status,access_state',
        ]));
    }

    public function cancel(Request $request, Reservation $reservation, ReservationService $reservations): ReservationResource
    {
        $cancelled = $reservations->cancel($request->user(), $reservation);

        return new ReservationResource($cancelled);
    }
}
