<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Organizer;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $start = $this->input('sales_start_at');
            $end = $this->input('sales_end_at');

            if ($start && $end && strtotime((string) $end) <= strtotime((string) $start)) {
                $validator->errors()->add('sales_end_at', 'The sales end time must be after the sales start time.');
            }
        });
    }
}
