<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route role and ownership checks authorize cancellation.
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
