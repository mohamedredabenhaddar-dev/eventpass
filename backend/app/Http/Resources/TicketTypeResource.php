<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'title' => $this->event->title,
                'slug' => $this->event->slug,
            ]),
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'available_quantity' => $this->available_quantity,
            'sales_start_at' => $this->sales_start_at?->toISOString(),
            'sales_end_at' => $this->sales_end_at?->toISOString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
