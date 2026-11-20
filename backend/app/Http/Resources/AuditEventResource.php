<?php

namespace App\Http\Resources;

use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_role' => $this->actor_role,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'outcome' => $this->outcome,
            'metadata' => AuditRecorder::safeMetadataForResponse($this->action, $this->metadata),
        ];
    }
}
