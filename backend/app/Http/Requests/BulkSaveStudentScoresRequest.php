<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkSaveStudentScoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $assessment = $this->route('assessment');

        if (! $user || ! $assessment instanceof Assessment) {
            return false;
        }

        $assessment->loadMissing('courseSection');

        return $user->can('manage', $assessment);
    }

    public function rules(): array
    {
        return [
            'scores' => ['required', 'array'],
            'scores.*.student_profile_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'scores.*.score_obtained' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $assessment = $this->route('assessment');

                if (! $assessment instanceof Assessment) {
                    return;
                }

                $assessment->loadMissing('courseSection');
                $enrolledStudentIds = Enrollment::query()
                    ->where('course_section_id', $assessment->course_section_id)
                    ->where('status', 'active')
                    ->whereHas('studentProfile', fn ($query) => $query->active())
                    ->pluck('student_profile_id')
                    ->all();

                foreach ($this->input('scores', []) as $index => $score) {
                    if (($score['score_obtained'] ?? 0) > $assessment->max_score) {
                        $validator->errors()->add(
                            "scores.{$index}.score_obtained",
                            'Score cannot exceed assessment max score.',
                        );
                    }

                    if (! in_array((int) ($score['student_profile_id'] ?? 0), $enrolledStudentIds, true)) {
                        $validator->errors()->add(
                            "scores.{$index}.student_profile_id",
                            'Student is not enrolled in this assessment section.',
                        );
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'scores.required' => 'At least one score row is required.',
            'scores.*.student_profile_id.exists' => 'Selected student profile does not exist.',
            'scores.*.score_obtained.numeric' => 'Score must be a number.',
            'scores.*.score_obtained.min' => 'Score cannot be negative.',
        ];
    }
}
