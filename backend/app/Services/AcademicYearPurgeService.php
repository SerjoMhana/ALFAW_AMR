<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CashAdvance;
use App\Models\ClassPost;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\FeeTemplate;
use App\Models\GradeSubmission;
use App\Models\Payment;
use App\Models\ReportCardPublication;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\TermWindow;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Deleting an academic year.
 *
 * A year is not a label on a shelf: classes, marks, registers, fees, receipts
 * and posts all hang off it. Removing the row alone would leave every one of
 * them behind with nothing to belong to — which is how a database ends up with
 * subjects attached to no class.
 *
 * So the year goes with its contents, and the admin is shown exactly what that
 * means before agreeing to it.
 */
class AcademicYearPurgeService
{
    /**
     * What deleting this year would take with it. Counted, never guessed, so
     * the warning the admin reads is the truth.
     *
     * @return array<string, mixed>
     */
    public function summary(AcademicYear $year): array
    {
        $name = $year->name;
        $sectionIds = CourseSection::where('academic_year', $name)->pluck('id');
        $courseIds = Course::whereIn('class_section_id', $sectionIds)->pluck('id');

        $counts = [
            'classes' => $sectionIds->count(),
            'courses' => $courseIds->count(),
            'enrollments' => Enrollment::whereIn('course_section_id', $sectionIds)->count(),
            'scores' => StudentScore::where('academic_year', $name)
                ->orWhereIn('course_section_id', $sectionIds)
                ->count(),
            'attendance' => DB::table('attendance_records')->whereIn('course_section_id', $sectionIds)->count(),
            'grade_submissions' => GradeSubmission::where('academic_year', $name)->count(),
            'term_windows' => TermWindow::where('academic_year', $name)->count(),
            'report_cards' => ReportCardPublication::where('academic_year', $name)->count(),
            'posts' => ClassPost::withTrashed()
                ->where('academic_year', $name)
                ->orWhereIn('course_id', $courseIds)
                ->count(),
            'fee_templates' => FeeTemplate::where('academic_year', $name)->count(),
            'student_fees' => StudentFee::where('academic_year', $name)->count(),
            'payments' => Payment::where('academic_year', $name)->count(),
            'advances' => CashAdvance::where('academic_year', $name)->count(),
            'calendar_events' => CalendarEvent::where('academic_year', $name)->count(),
            'students' => $this->studentsOnlyIn($name)->count(),
        ];

        return [
            'year' => ['id' => $year->id, 'name' => $name, 'is_active' => $year->is_active],
            'counts' => $counts,
            'total' => array_sum($counts),
            'is_empty' => array_sum($counts) === 0,
        ];
    }

    /**
     * Removes the year and everything recorded under it, in one transaction.
     *
     * @return array<string, mixed>
     */
    public function purge(AcademicYear $year, User $actor): array
    {
        $summary = $this->summary($year);
        $name = $year->name;

        // Irreversible and wide, so a copy of the database goes aside first —
        // a wrong year picked late in the day should not be the end of it.
        $backup = $summary['is_empty'] ? null : $this->backup($name);

        DB::transaction(function () use ($year, $name): void {
            $sectionIds = CourseSection::where('academic_year', $name)->pluck('id');
            $studentIds = $this->studentsOnlyIn($name)->pluck('id');

            // Subjects first: their link to a class is SET NULL, so deleting the
            // classes alone would strand them rather than remove them.
            Course::whereIn('class_section_id', $sectionIds)->delete();
            // Takes enrolments, attendance and any remaining marks with it.
            CourseSection::whereIn('id', $sectionIds)->delete();

            StudentScore::where('academic_year', $name)->delete();
            GradeSubmission::where('academic_year', $name)->delete();
            TermWindow::where('academic_year', $name)->delete();
            ReportCardPublication::where('academic_year', $name)->delete();
            ClassPost::withTrashed()->where('academic_year', $name)->forceDelete();

            // Receipts and their allocations, then the charges they paid.
            Payment::where('academic_year', $name)->delete();
            StudentFee::where('academic_year', $name)->delete();
            FeeTemplate::where('academic_year', $name)->delete();

            CashAdvance::where('academic_year', $name)->delete();
            CalendarEvent::where('academic_year', $name)->delete();

            // Only students who exist nowhere else. Anyone who also sat in
            // another year keeps their record; only this year's part of it went.
            $userIds = StudentProfile::whereIn('id', $studentIds)->pluck('user_id')->filter();
            StudentProfile::whereIn('id', $studentIds)->delete();
            User::whereIn('id', $userIds)->where('user_type', 'student')->delete();

            $year->delete();
        });

        return [
            'deleted' => $summary['counts'],
            'total' => $summary['total'],
            'backup' => $backup ? basename($backup) : null,
        ];
    }

    /**
     * Students whose whole record sits in this year — registered for it or
     * enrolled in it, and enrolled in nothing outside it.
     *
     * @return Builder<StudentProfile>
     */
    private function studentsOnlyIn(string $name): Builder
    {
        return StudentProfile::query()
            ->where(fn (Builder $query) => $query
                ->where('academic_year', $name)
                ->orWhereHas(
                    'enrollments.courseSection',
                    fn (Builder $section) => $section->where('academic_year', $name),
                ))
            ->whereDoesntHave(
                'enrollments.courseSection',
                fn (Builder $section) => $section->where('academic_year', '!=', $name),
            );
    }

    /**
     * Copies the SQLite file aside. Returns null for any other driver, where
     * the school's own backup arrangements apply.
     */
    private function backup(string $name): ?string
    {
        $path = config('database.connections.'.config('database.default').'.database');

        if (! is_string($path) || ! is_file($path)) {
            return null;
        }

        $folder = storage_path('app/private/backups');
        File::ensureDirectoryExists($folder);

        $target = sprintf(
            '%s/before-deleting-%s-%s.sqlite',
            $folder,
            str_replace('/', '-', $name),
            now()->format('Ymd-His'),
        );
        File::copy($path, $target);

        return $target;
    }
}
