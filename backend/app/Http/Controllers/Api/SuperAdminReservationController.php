<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;

class SuperAdminReservationController extends Controller
{
    public function index()
    {
        return ReservationResource::collection(
            Reservation::query()
                ->with(['event:id,title,slug,start_at,status', 'items.ticketType:id,name'])
                ->latest()
                ->paginate(15),
        );
    }

    public function show(Reservation $reservation): ReservationResource
    {
        return new ReservationResource($reservation->load([
            'event:id,title,slug,start_at,status',
            'items.ticketType:id,name',
            'items.tickets',
        ]));
    }
}
