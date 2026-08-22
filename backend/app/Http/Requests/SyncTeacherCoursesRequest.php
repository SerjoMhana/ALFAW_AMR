<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncTeacherCoursesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_ids' => ['present', 'array'],
            'course_ids.*' => ['integer', 'exists:courses,id'],
        ];
    }
}
