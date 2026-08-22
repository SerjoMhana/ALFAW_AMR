<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * POST /api/teachers always creates a teacher, so callers need not repeat it.
     */
    protected function prepareForValidation(): void
    {
        if ($this->is('api/teachers')) {
            $this->merge(['user_type' => 'teacher']);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_2' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'academic_qualification' => ['nullable', 'string', 'max:255'],
            // Required: an account created without one used to fall back to
            // the literal 'password', which is no password at all.
            'password' => ['required', 'string', Password::defaults()],
            'user_type' => ['required', Rule::in(['admin', 'staff', 'teacher', 'student'])],
            'is_active' => ['boolean'],
            'course_ids' => ['sometimes', 'array'],
            'course_ids.*' => ['integer', 'exists:courses,id'],
        ];
    }
}
