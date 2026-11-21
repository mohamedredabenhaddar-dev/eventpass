<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizerProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'organization_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
