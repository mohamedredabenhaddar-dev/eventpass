<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Client;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
