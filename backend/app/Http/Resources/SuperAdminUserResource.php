<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuperAdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'account_status' => $this->account_status->value,
            'created_at' => $this->created_at?->toISOString(),
            'suspended_at' => $this->suspended_at?->toISOString(),
            'suspended_by' => $this->suspended_by,
            'suspension_reason' => $this->suspension_reason,
        ];
    }
}
