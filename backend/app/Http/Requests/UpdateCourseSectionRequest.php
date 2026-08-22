<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $courseSection = $this->route('course_section');

        return [
            'course_id' => ['sometimes', 'integer', 'exists:courses,id'],
            'teacher_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('user_type', 'teacher')],
            'section_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('course_sections', 'section_code')->ignore($courseSection),
            ],
            'class_name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_year' => ['sometimes', 'required', 'string', 'max:20'],
            'term' => ['sometimes', 'nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
