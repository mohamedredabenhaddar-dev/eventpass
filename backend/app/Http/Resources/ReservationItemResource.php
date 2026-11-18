<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_type_id' => $this->ticket_type_id,
            'ticket_type' => $this->whenLoaded('ticketType', fn () => [
                'id' => $this->ticketType->id,
                'name' => $this->ticketType->name,
            ]),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'subtotal' => $this->subtotal,
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
