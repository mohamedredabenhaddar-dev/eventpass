<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectOrganizerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The super_admin role is enforced by route middleware.
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
