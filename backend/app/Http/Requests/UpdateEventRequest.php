<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Organizer;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:50000'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'venue_name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'required', 'string', 'max:512'],
            'city' => ['sometimes', 'required', 'string', 'max:150'],
            'country' => ['sometimes', 'required', 'string', 'max:150'],
            'start_at' => ['sometimes', 'required', 'date'],
            'end_at' => ['sometimes', 'required', 'date'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1'],
            'cover_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('start_at') || $validator->errors()->has('end_at')) {
                return;
            }

            $event = $this->route('event');
            $start = $this->input('start_at', $event->start_at);
            $end = $this->input('end_at', $event->end_at);

            if (strtotime((string) $start) >= strtotime((string) $end)) {
                $validator->errors()->add('end_at', 'The end date must be after the start date.');
            }
        });
    }
}
