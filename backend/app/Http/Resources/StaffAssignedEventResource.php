<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAssignedEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'event' => [
                'id' => $this->id,
                'title' => $this->title,
                'slug' => $this->slug,
                'description' => $this->description,
                'start_at' => $this->start_at?->toISOString(),
                'end_at' => $this->end_at?->toISOString(),
                'venue_name' => $this->venue_name,
                'address' => $this->address,
                'city' => $this->city,
                'country' => $this->country,
                'status' => $this->status->value,
            ],
            'assignment' => [
                'id' => $this->pivot->id,
                'assigned_at' => $this->pivot->created_at?->toISOString(),
            ],
        ];
    }
}
