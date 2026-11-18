<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ClientReservationResource extends ReservationResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($this->resource->relationLoaded('event') && $this->resource->event) {
            $data['event'] = array_merge($data['event'], [
                'category' => $this->resource->event->category,
                'venue_name' => $this->resource->event->venue_name,
                'address' => $this->resource->event->address,
                'city' => $this->resource->event->city,
                'country' => $this->resource->event->country,
            ]);
        }

        return $data;
    }
}
