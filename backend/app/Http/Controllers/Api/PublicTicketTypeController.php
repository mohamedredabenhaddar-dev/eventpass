<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use App\Models\Event;

class PublicTicketTypeController extends Controller
{
    public function index(Event $event)
    {
        abort_unless($event->status === EventStatus::Published, 404);
        $now = now();

        return TicketTypeResource::collection(
            $event->ticketTypes()
                ->where('is_active', true)
                ->where('available_quantity', '>', 0)
                ->where(fn ($query) => $query->whereNull('sales_start_at')->orWhere('sales_start_at', '<=', $now))
                ->where(fn ($query) => $query->whereNull('sales_end_at')->orWhere('sales_end_at', '>', $now))
                ->orderBy('price')
                ->get()
                ->load('event:id,title,slug'),
        );
    }
}
