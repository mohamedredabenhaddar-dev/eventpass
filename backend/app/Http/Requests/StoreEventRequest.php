<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Organizer;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'category' => ['required', 'string', 'max:100'],
            'venue_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:512'],
            'city' => ['required', 'string', 'max:150'],
            'country' => ['required', 'string', 'max:150'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'capacity' => ['required', 'integer', 'min:1'],
            'cover_image' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
