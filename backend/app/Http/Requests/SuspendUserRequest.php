<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The Super Admin role is enforced by route middleware.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('suspension_reason'))) {
            $this->merge(['suspension_reason' => trim($this->input('suspension_reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'suspension_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
