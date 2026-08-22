<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A code names a subject inside its class, so that is where it
            // has to be unique — every grade teaches ENGLISH.
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('courses', 'code')
                    ->where('class_section_id', $this->input('class_section_id')),
            ],
            'class_section_id' => ['required', 'integer', 'exists:course_sections,id'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('user_type', 'teacher')],
            'name' => ['required', 'string', 'max:255'],
            'grade_level' => ['required', 'string', 'max:20'],
            'is_ap' => ['boolean'],
            'has_exam' => ['boolean'],
            'credit_hours' => ['nullable', 'numeric', 'min:0.25'],
            'description' => ['nullable', 'string'],
        ];
    }
}
