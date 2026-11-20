<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StaffProvisioningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:new,promote,assign'],

            'name' => ['sometimes', 'required', 'string', 'max:255'],

            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email'],

            'password' => ['sometimes', 'required', 'string', 'min:8'],

            'existing_user_id' => ['sometimes', 'integer', 'exists:users,id'],

            'staff_user_id' => ['sometimes', 'integer', 'exists:users,id'],

            'event_id' => ['sometimes', 'integer', 'exists:events,id'],

            'assign_to_event_id' => ['sometimes', 'integer', 'exists:events,id'],

            'password_confirmation' => ['sometimes', 'string', 'min:8', 'same:password'],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.in' => 'The provisioning mode must be new, promote, or assign.',
            'name.required' => 'Name is required when creating a new staff user.',
            'email.required' => 'Email is required when creating a new staff user.',
            'email.unique' => 'This email is already registered.',
            'password.required' => 'A password is required when creating a new staff user.',
            'password.min' => 'Password must be at least 8 characters.',
            'password_confirmation.same' => 'The password confirmation does not match.',
            'existing_user_id.exists' => 'The specified user does not exist.',
            'staff_user_id.exists' => 'The specified staff user does not exist.',
            'event_id.exists' => 'The specified event does not exist.',
        ];
    }
}
