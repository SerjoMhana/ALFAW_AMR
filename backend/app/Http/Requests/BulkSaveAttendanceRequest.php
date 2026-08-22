<?php

namespace App\Http\Requests;

use App\Models\CourseSection;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkSaveAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $section = $this->route('courseSection');

        // Kept by the office: the admin, or a member of staff granted the
        // permission. Teaching the class is not itself a grant.
        return $user && $section instanceof CourseSection && (
            $user->isAdmin() || $user->hasPermission('attendance.manage')
        );
    }

    public function rules(): array
    {
        return [
            'attendance_date' => ['required', 'date'],
            'records' => ['required', 'array'],
            'records.*.student_profile_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $section = $this->route('courseSection');
                $studentIds = Enrollment::where('course_section_id', $section->id)
                    ->where('status', 'active')
                    ->whereHas('studentProfile', fn ($query) => $query->active())
                    ->pluck('student_profile_id')
                    ->all();

                foreach ($this->input('records', []) as $index => $record) {
                    if (! in_array((int) ($record['student_profile_id'] ?? 0), $studentIds, true)) {
                        $validator->errors()->add("records.{$index}.student_profile_id", 'Student is not enrolled in this section.');
                    }
                }
            },
        ];
    }
}
