<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventStaffAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'staff' => $this->whenLoaded('staff', fn () => [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
                'email' => $this->staff->email,
            ]),
            'assigned_by' => $this->assigned_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
