<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_reference' => $this->ticket?->reference,
            'action' => $this->action->value,
            'gate' => $this->gate,
            'staff' => $this->whenLoaded('staff', fn () => [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
            ]),
            'scanned_at' => $this->scanned_at?->toISOString(),
        ];
    }
}
