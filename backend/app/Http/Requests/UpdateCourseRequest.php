<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $course = $this->route('course');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('courses', 'code')
                    ->ignore($course)
                    ->where('class_section_id', $this->input('class_section_id', $course?->class_section_id)),
            ],
            'class_section_id' => ['sometimes', 'required', 'integer', 'exists:course_sections,id'],
            'teacher_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('user_type', 'teacher')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'grade_level' => ['sometimes', 'required', 'string', 'max:20'],
            'is_ap' => ['sometimes', 'boolean'],
            'has_exam' => ['sometimes', 'boolean'],
            'credit_hours' => ['sometimes', 'required', 'numeric', 'min:0.25'],
            'periods_per_week' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:40'],
            'description' => ['nullable', 'string'],
        ];
    }
}
