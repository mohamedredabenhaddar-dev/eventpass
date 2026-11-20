<?php

namespace App\Http\Requests;

use App\Services\AuditRecorder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexSuperAdminAuditEventsRequest extends FormRequest
{
    private const MAX_PAGE = 100000;

    private const ALLOWED_QUERY_PARAMETERS = [
        'page',
        'per_page',
        'action',
        'actor_id',
        'actor_type',
        'entity_type',
        'entity_id',
        'outcome',
        'date_from',
        'date_to',
    ];

    public function authorize(): bool
    {
        return true; // Super Admin and active-account access are enforced by route middleware.
    }

    public function rules(): array
    {
        $types = AuditRecorder::supportedFilterValues();

        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action' => ['sometimes', 'string', Rule::in($types['actions'])],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'actor_type' => ['sometimes', 'string', Rule::in($types['actor_types'])],
            'entity_type' => ['sometimes', 'string', Rule::in($types['entity_types'])],
            'entity_id' => ['sometimes', 'integer', 'min:1'],
            'outcome' => ['sometimes', 'string', Rule::in($types['outcomes'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateSupportedQueryParameters($validator);
            $this->validateActorFilterCombination($validator);
            $this->validateEntityFilterCombination($validator);
            $this->validateDateRange($validator);
        });
    }

    private function validateSupportedQueryParameters(Validator $validator): void
    {
        foreach (array_diff(array_keys($this->query()), self::ALLOWED_QUERY_PARAMETERS) as $parameter) {
            $validator->errors()->add((string) $parameter, 'The selected filter is not supported.');
        }
    }

    private function validateActorFilterCombination(Validator $validator): void
    {
        if ($this->filled('actor_id') && $this->filled('actor_type') && $this->input('actor_type') !== 'user') {
            $validator->errors()->add('actor_type', 'The actor type must be user when an actor ID is provided.');
        }
    }

    private function validateEntityFilterCombination(Validator $validator): void
    {
        if ($this->filled('entity_id') && ! $this->filled('entity_type')) {
            $validator->errors()->add('entity_type', 'The entity type field is required when an entity ID is provided.');
        }
    }

    private function validateDateRange(Validator $validator): void
    {
        if (! $validator->errors()->has('date_from')
            && ! $validator->errors()->has('date_to')
            && $this->filled('date_from') && $this->filled('date_to')
            && $this->date('date_from') > $this->date('date_to')) {
            $validator->errors()->add('date_to', 'The date to field must be on or after date from.');
        }
    }
}
