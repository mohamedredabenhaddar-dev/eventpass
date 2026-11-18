<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use App\Models\TicketType;

class SuperAdminTicketTypeController extends Controller
{
    public function index()
    {
        return TicketTypeResource::collection(
            TicketType::query()->with('event:id,title,slug')->latest()->paginate(15),
        );
    }

    public function show(TicketType $ticketType): TicketTypeResource
    {
        return new TicketTypeResource($ticketType->load('event:id,title,slug'));
    }
}
