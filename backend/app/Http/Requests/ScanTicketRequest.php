<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'action' => ['required', 'string', Rule::in(['ENTRY', 'EXIT'])],
            'gate' => ['sometimes', 'nullable', 'string', 'max:120'],
            'staff_id' => ['prohibited'],
            'event_id' => ['prohibited'],
            'ticket_id' => ['prohibited'],
            'ticket_status' => ['prohibited'],
            'access_state' => ['prohibited'],
        ];
    }
}
