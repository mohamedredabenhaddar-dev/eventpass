<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizer_id' => $this->organizer_id,
            'organizer' => $this->organizer ? [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
            ] : null,
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
            'suspended_at' => $this->suspended_at?->toISOString(),
            'suspended_by' => $this->suspended_by,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
