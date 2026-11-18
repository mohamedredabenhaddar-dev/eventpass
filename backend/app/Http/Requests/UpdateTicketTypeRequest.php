<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Organizer;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:4294967295'],
            'sales_start_at' => ['sometimes', 'nullable', 'date'],
            'sales_end_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ticketType = $this->route('ticketType');
            $start = $this->input('sales_start_at', $ticketType?->sales_start_at);
            $end = $this->input('sales_end_at', $ticketType?->sales_end_at);

            if ($start && $end && strtotime((string) $end) <= strtotime((string) $start)) {
                $validator->errors()->add('sales_end_at', 'The sales end time must be after the sales start time.');
            }
        });
    }
}
