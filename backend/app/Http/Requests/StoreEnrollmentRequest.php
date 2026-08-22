<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_profile_id' => [
                'required',
                'integer',
                Rule::exists('student_profiles', 'id')->where('status', 'active')->whereNull('archived_at'),
                Rule::unique('enrollments', 'student_profile_id')
                    ->where('course_section_id', $this->integer('course_section_id')),
            ],
            'course_section_id' => ['required', 'integer', 'exists:course_sections,id'],
            'status' => ['nullable', 'string', Rule::in(['active', 'dropped', 'completed'])],
            'enrolled_at' => ['nullable', 'date'],
        ];
    }
}
