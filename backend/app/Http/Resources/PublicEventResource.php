<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'venue_name' => $this->venue_name,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'start_at' => $this->start_at?->toISOString(),
            'end_at' => $this->end_at?->toISOString(),
            'capacity' => $this->capacity,
            'cover_image' => $this->cover_image,
            'status' => $this->status->value,
        ];
    }
}
