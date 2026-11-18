<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSuperAdminUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The Super Admin role is enforced by route middleware.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) {
            $this->merge(['search' => trim($this->query('search'))]);
        }
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', Rule::in(UserRole::values())],
            'account_status' => ['sometimes', 'string', Rule::in(AccountStatus::values())],
        ];
    }
}
