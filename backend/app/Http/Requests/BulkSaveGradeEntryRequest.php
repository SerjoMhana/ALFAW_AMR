<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradingItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkSaveGradeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $course = $this->route('course');

        if (! $user || ! $course instanceof Course) {
            return false;
        }

        return $user->can('enterGrades', $course);
    }

    public function rules(): array
    {
        return [
            'term' => ['required', Rule::in(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])],
            'academic_year' => ['required', 'string', 'max:20'],
            'scores' => ['required', 'array'],
            'scores.*.student_profile_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'scores.*.grading_item_id' => ['required', 'integer', 'exists:grading_items,id'],
            'scores.*.score_obtained' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $course = $this->route('course');
                if (! $course instanceof Course) {
                    return;
                }

                $course->loadMissing('classSection');
                $gradeTier = $course->gradeTier();

                $enrolledStudentIds = $course->class_section_id
                    ? Enrollment::query()
                        ->where('course_section_id', $course->class_section_id)
                        ->where('status', 'active')
                        ->whereHas('studentProfile', fn ($query) => $query->active())
                        ->pluck('student_profile_id')
                        ->all()
                    : [];

                $gradingItemIds = collect($this->input('scores', []))->pluck('grading_item_id')->filter()->unique()->all();
                $gradingItems = GradingItem::whereIn('id', $gradingItemIds)->with('category')->get()->keyBy('id');

                foreach ($this->input('scores', []) as $index => $score) {
                    if (! in_array((int) ($score['student_profile_id'] ?? 0), $enrolledStudentIds, true)) {
                        $validator->errors()->add("scores.{$index}.student_profile_id", 'Student is not enrolled in this course\'s class.');

                        continue;
                    }

                    $item = $gradingItems->get((int) ($score['grading_item_id'] ?? 0));

                    if (! $item) {
                        continue;
                    }

                    if ($item->is_total_field) {
                        $validator->errors()->add("scores.{$index}.grading_item_id", 'Computed total fields cannot be entered directly.');

                        continue;
                    }

                    if (! $gradeTier || $item->category->grade_tier_id !== $gradeTier->id) {
                        $validator->errors()->add("scores.{$index}.grading_item_id", 'Grading item does not belong to this course\'s grade tier.');

                        continue;
                    }

                    if (($score['score_obtained'] ?? null) !== null && (float) $score['score_obtained'] > (float) $item->max_score) {
                        $validator->errors()->add("scores.{$index}.score_obtained", "Score cannot exceed the item's max score ({$item->max_score}).");
                    }
                }
            },
        ];
    }
}
