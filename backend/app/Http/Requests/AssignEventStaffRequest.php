<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignEventStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'staff'),
            ],
        ];
    }
}
