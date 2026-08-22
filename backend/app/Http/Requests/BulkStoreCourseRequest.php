<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\CourseSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Adding a list of subjects to one class — or to several at once.
 *
 * Grades usually share a syllabus: the same eleven subjects with the same
 * periods run in Grade 1 and Grade 2 alike, so the list is entered once and
 * applied to every class chosen.
 */
class BulkStoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The screen used to send a single class. Accepting both keeps older
     * callers working while the list is what the rules read.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('class_section_ids') && $this->filled('class_section_id')) {
            $this->merge(['class_section_ids' => [$this->input('class_section_id')]]);
        }
    }

    public function rules(): array
    {
        return [
            'class_section_ids' => ['required', 'array', 'min:1'],
            'class_section_ids.*' => ['integer', 'distinct', 'exists:course_sections,id'],
            'courses' => ['required', 'array', 'min:1', 'max:100'],
            'courses.*.code' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'courses.*.name' => ['required', 'string', 'max:255'],
            'courses.*.periods_per_week' => ['nullable', 'integer', 'min:1', 'max:40'],
            // A subject the school does not examine, kept off the report card.
            'courses.*.has_exam' => ['boolean'],
        ];
    }

    /**
     * A code identifies a subject inside its class, so the clash to look for is
     * per class — and the message says which class, since one bad row would
     * otherwise be a mystery among a dozen.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sections = CourseSection::whereIn('id', $this->input('class_section_ids', []))->get();

                foreach ($this->input('courses', []) as $index => $course) {
                    $code = strtoupper(trim((string) ($course['code'] ?? '')));

                    if ($code === '') {
                        continue;
                    }

                    foreach ($sections as $section) {
                        $taken = Course::where('class_section_id', $section->id)
                            ->whereRaw('upper(code) = ?', [$code])
                            ->exists();

                        if ($taken) {
                            $name = $section->class_name ?: $section->section_code;
                            $validator->errors()->add(
                                "courses.{$index}.code",
                                "الكود {$code} مستعمل بالفعل في فصل {$name}.",
                            );
                        }
                    }
                }
            },
        ];
    }
}
