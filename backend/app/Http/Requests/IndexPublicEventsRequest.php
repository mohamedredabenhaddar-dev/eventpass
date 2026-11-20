<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexPublicEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.integer' => 'Page must be a positive integer.',
            'per_page.integer' => 'Per page must be a positive integer.',
            'per_page.max' => 'Per page must not exceed 100.',
            'search.max' => 'Search query must not exceed 255 characters.',
            'city.max' => 'City filter must not exceed 255 characters.',
            'category.max' => 'Category filter must not exceed 255 characters.',
        ];
    }
}
