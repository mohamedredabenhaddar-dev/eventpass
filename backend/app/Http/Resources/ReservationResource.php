<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'event_id' => $this->event_id,
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'title' => $this->event->title,
                'slug' => $this->event->slug,
                'start_at' => $this->event->start_at?->toISOString(),
                'status' => $this->event->status->value,
            ]),
            'reference' => $this->reference,
            'total' => $this->total,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'items' => ReservationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
