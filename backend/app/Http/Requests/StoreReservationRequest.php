<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Client;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.ticket_type_id' => ['required', 'integer', 'distinct', 'exists:ticket_types,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
