<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user)],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone_2' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'academic_qualification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', Password::defaults()],
            'user_type' => ['sometimes', 'required', Rule::in(['admin', 'staff', 'teacher', 'student'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
