<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkSaveGradeEntryRequest;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeAuditLog;
use App\Models\GradeSubmission;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use App\Models\StudentScore;
use App\Models\TermWindow;
use App\Models\User;
use App\Services\GPAService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradeEntryController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->user_type === 'teacher') {
            $courses = Course::query()
                ->with('classSection')
                ->where('teacher_id', $user->id)
                ->latest()
                ->get();

            return response()->json(['data' => $this->withGradeTier($courses)]);
        }

        abort_unless($user->isAdmin() || $user->hasPermission('admin_manage_grades'), 403);

        $courses = Course::query()
            ->with(['classSection', 'teacher'])
            ->when($request->query('teacher_id'), fn ($query, $teacherId) => $query->where('teacher_id', $teacherId))
            ->when($request->query('academic_year'), function ($query, $year) {
                $query->whereHas('classSection', fn ($sectionQuery) => $sectionQuery->where('academic_year', $year));
            })
            ->when($request->query('course_id'), fn ($query, $courseId) => $query->where('id', $courseId))
            ->when($request->query('grade_level'), fn ($query, $gradeLevel) => $query->where('grade_level', $gradeLevel))
            ->latest()
            ->get();

        return response()->json(['data' => $this->withGradeTier($courses)]);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        abort_unless($request->user()->can('enterGrades', $course), 403);

        $course->loadMissing('classSection');
        $classSection = $course->classSection;
        abort_if(! $classSection, 422, 'This subject has no class assigned.');

        $gradeTier = $course->gradeTier();
        $term = $request->query('term', 'Quarter 1');
        $academicYear = $request->query('academic_year', $classSection->academic_year);

        if (! $gradeTier) {
            $assigned = $classSection->gradeTier;

            return response()->json([
                'message' => $assigned
                    ? "مخطط «{$assigned->name}» مُسند لهذا الفصل لكنه غير مفعّل. فعّله من إعداد نظام الدرجات لتبدأ الدرجات."
                    : 'لا يوجد مخطط درجات مُسند لهذا الفصل. أسند مخططاً وفعّله من إعداد نظام الدرجات.',
            ], 422);
        }

        $categories = GradingCategory::query()
            ->where('grade_tier_id', $gradeTier->id)
            ->where('is_active', true)
            ->with(['items' => fn ($query) => $query->where('is_active', true)->orderBy('display_order')])
            ->orderBy('display_order')
            ->get();

        $scoresByStudent = StudentScore::query()
            ->where('course_id', $course->id)
            ->where('term', $term)
            ->where('academic_year', $academicYear)
            ->get()
            ->groupBy('student_profile_id');

        // Members rather than active-only, so a past year's sheet still lists the
        // students who sat in that class before being promoted or graduated.
        $students = Enrollment::query()
            ->members()
            ->with('studentProfile.user')
            ->where('course_section_id', $classSection->id)
            ->orderBy('student_profile_id')
            ->get()
            ->map(function (Enrollment $enrollment) use ($scoresByStudent) {
                $scores = $scoresByStudent->get($enrollment->student_profile_id, collect())
                    ->mapWithKeys(fn (StudentScore $score) => [$score->grading_item_id => $score->score_obtained !== null ? (float) $score->score_obtained : null]);

                return [
                    'student_profile' => $enrollment->studentProfile,
                    'scores' => $scores,
                ];
            });

        return response()->json([
            'data' => [
                'course' => $course,
                'class_section' => $classSection,
                'grade_tier' => $gradeTier,
                'term' => $term,
                'academic_year' => $academicYear,
                'categories' => $categories,
                // Every quarter sheet states what the subject is worth and where
                // that came from, so a missing legend is impossible to overlook.
                'credits' => app(GPAService::class)->creditsFor($course) + [
                    'periods_per_week' => $course->periods_per_week,
                ],
                'students' => $students->values(),
                'submission' => $this->submissionState(
                    $this->findSubmission($course, $term, $academicYear),
                    $request->user(),
                    TermWindow::isOpen($academicYear, $term),
                ),
            ],
        ]);
    }

    public function bulkSave(BulkSaveGradeEntryRequest $request, Course $course): JsonResponse
    {
        $term = $request->validated('term');
        $academicYear = $request->validated('academic_year');
        $course->loadMissing('classSection');

        $this->ensureTermOpen($request->user(), $academicYear, $term);

        $submission = $this->findSubmission($course, $term, $academicYear);

        abort_if(
            $submission?->isSubmitted() && ! $this->canBypassLock($request->user()),
            403,
            'تم إرسال درجات هذه المادة لهذا الفصل ولا يمكن تعديلها. راجع الإدارة لإعادة فتحها.',
        );

        $savedScores = DB::transaction(function () use ($request, $course, $term, $academicYear) {
            return collect($request->validated('scores'))
                ->map(function (array $row) use ($request, $course, $term, $academicYear) {
                    $existing = StudentScore::where('student_profile_id', $row['student_profile_id'])
                        ->where('course_id', $course->id)
                        ->where('grading_item_id', $row['grading_item_id'])
                        ->where('term', $term)
                        ->where('academic_year', $academicYear)
                        ->first();

                    $gradingItem = $existing?->gradingItem ?? GradingItem::find($row['grading_item_id']);
                    $newScore = $row['score_obtained'] ?? null;

                    $studentScore = StudentScore::updateOrCreate(
                        [
                            'student_profile_id' => $row['student_profile_id'],
                            'course_id' => $course->id,
                            'grading_item_id' => $row['grading_item_id'],
                            'term' => $term,
                            'academic_year' => $academicYear,
                        ],
                        [
                            'course_section_id' => $course->class_section_id,
                            'teacher_id' => $course->teacher_id,
                            'score_obtained' => $newScore,
                            'max_score' => $gradingItem->max_score,
                            'created_by' => $existing?->created_by ?? $request->user()->id,
                            'updated_by' => $request->user()->id,
                        ],
                    );

                    $oldScore = $existing?->score_obtained !== null ? (float) $existing->score_obtained : null;
                    $comparableNew = $newScore !== null ? (float) $newScore : null;

                    if ($oldScore !== $comparableNew) {
                        GradeAuditLog::create([
                            'student_score_id' => $studentScore->id,
                            'old_score' => $oldScore,
                            'new_score' => $comparableNew,
                            'changed_by' => $request->user()->id,
                        ]);
                    }

                    return $studentScore;
                })
                ->values();
        });

        return response()->json([
            'data' => $savedScores,
            'submission' => $this->submissionState(
                $this->findSubmission($course, $term, $academicYear),
                $request->user(),
                TermWindow::isOpen($academicYear, $term),
            ),
        ]);
    }

    /**
     * Lock the sheet: after this the assigned teacher can no longer change scores.
     * Idempotent — re-submitting keeps the original submitter and timestamp.
     */
    public function submit(Request $request, Course $course): JsonResponse
    {
        abort_unless($request->user()->can('enterGrades', $course), 403);

        $validated = $this->validateTerm($request);
        $this->ensureTermOpen($request->user(), $validated['academic_year'], $validated['term']);
        $submission = $this->findSubmission($course, $validated['term'], $validated['academic_year']);

        if (! $submission?->isSubmitted()) {
            $submission = GradeSubmission::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'term' => $validated['term'],
                    'academic_year' => $validated['academic_year'],
                ],
                [
                    'status' => GradeSubmission::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'submitted_by' => $request->user()->id,
                ],
            );
        }

        return response()->json([
            'data' => $this->submissionState(
                $submission,
                $request->user(),
                TermWindow::isOpen($validated['academic_year'], $validated['term']),
            ),
        ]);
    }

    /**
     * Reopen a submitted sheet so the teacher can edit again. Admin-side only.
     */
    public function unlock(Request $request, Course $course): JsonResponse
    {
        abort_unless($this->canBypassLock($request->user()), 403);

        $validated = $this->validateTerm($request);
        $submission = $this->findSubmission($course, $validated['term'], $validated['academic_year']);

        abort_unless($submission?->isSubmitted(), 422, 'لم يتم إرسال درجات هذه المادة بعد.');

        $submission->update([
            'status' => GradeSubmission::STATUS_DRAFT,
            'unlocked_at' => now(),
            'unlocked_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->submissionState(
                $submission->fresh(),
                $request->user(),
                TermWindow::isOpen($validated['academic_year'], $validated['term']),
            ),
        ]);
    }

    /**
     * Grades may only be touched while the admin has that quarter open.
     * Admin-side users stay exempt so they are never locked out of their own gate.
     */
    private function ensureTermOpen(User $user, string $academicYear, string $term): void
    {
        abort_if(
            ! TermWindow::isOpen($academicYear, $term) && ! $this->canBypassLock($user),
            403,
            "لم تفتح الإدارة {$term} لهذه السنة الدراسية، لا يمكن إدخال الدرجات الآن.",
        );
    }

    private function validateTerm(Request $request): array
    {
        return $request->validate([
            'term' => ['required', Rule::in(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])],
            'academic_year' => ['required', 'string', 'max:20'],
        ]);
    }

    private function findSubmission(Course $course, string $term, string $academicYear): ?GradeSubmission
    {
        return GradeSubmission::query()
            ->with('submittedBy:id,name')
            ->where('course_id', $course->id)
            ->where('term', $term)
            ->where('academic_year', $academicYear)
            ->first();
    }

    /**
     * Admins (and staff granted admin_manage_grades) can always edit, submit, and reopen.
     */
    private function canBypassLock(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('admin_manage_grades');
    }

    private function submissionState(?GradeSubmission $submission, User $user, bool $termOpen): array
    {
        $isLocked = (bool) $submission?->isSubmitted();
        $canBypass = $this->canBypassLock($user);
        $termUsable = $termOpen || $canBypass;

        return [
            'status' => $submission?->status ?? GradeSubmission::STATUS_DRAFT,
            'is_locked' => $isLocked,
            'term_open' => $termOpen,
            'can_edit' => $termUsable && (! $isLocked || $canBypass),
            'can_submit' => $termUsable && ! $isLocked,
            'can_unlock' => $isLocked && $canBypass,
            'submitted_at' => $submission?->submitted_at?->toDateTimeString(),
            'submitted_by' => $submission?->submittedBy?->name,
        ];
    }

    private function withGradeTier($courses)
    {
        return $courses->map(function (Course $course) {
            return [
                ...$course->toArray(),
                'grade_tier' => $course->gradeTier(),
            ];
        });
    }
}
