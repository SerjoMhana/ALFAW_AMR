<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradeTier;
use App\Models\GradingItem;
use App\Models\ParentGuardian;
use App\Models\ReportCardPublication;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\TermWindow;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FullSchoolDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('Demo@12345');
            $admin = User::updateOrCreate(
                ['email' => 'demo.admin@school.test'],
                ['name' => 'مدير النظام التجريبي', 'username' => 'demo.admin', 'password' => $password, 'user_type' => 'admin', 'is_active' => true],
            );

            $sections = CourseSection::query()->with('courses')->orderBy('id')->get();
            abort_if($sections->isEmpty(), 500, 'No classes exist. Create the classes before loading demo data.');

            foreach ($sections as $sectionIndex => $section) {
                $grade = $this->gradeNumber($section);
                $tier = GradeTier::query()
                    ->where('is_active', true)
                    ->where('min_grade', '<=', $grade)
                    ->where('max_grade', '>=', $grade)
                    ->firstOrFail();

                $section->update(['grade_tier_id' => $tier->id, 'term' => 'Quarter 1']);
                $teachers = $this->assignTeachers($section, $password);
                if ($teachers->isNotEmpty()) {
                    $section->update(['teacher_id' => $teachers->first()->id]);
                }

                // Keep the two original G7 demo accounts so previously shared
                // credentials remain valid; every other class gets its own pair.
                $students = $section->section_code === 'G7'
                    ? StudentProfile::query()->whereIn('student_number', ['DEMO-S-001', 'DEMO-S-002'])->get()
                    : collect(range(1, 2))->map(fn (int $number) => $this->studentWithGuardian(
                        $section,
                        $grade,
                        $number,
                        $password,
                    ));

                foreach (TermWindow::TERMS as $term) {
                    TermWindow::updateOrCreate(
                        ['academic_year' => $section->academic_year, 'term' => $term],
                        ['is_open' => true, 'opened_at' => now(), 'opened_by' => $admin->id, 'closed_at' => null, 'closed_by' => null],
                    );
                    $this->publish($section, $admin, ReportCardPublication::TYPE_QUARTER, $term);
                }
                $this->publish($section, $admin, ReportCardPublication::TYPE_SEMESTER, 'Semester 1');
                $this->publish($section, $admin, ReportCardPublication::TYPE_SEMESTER, 'Semester 2');
                $this->publish($section, $admin, ReportCardPublication::TYPE_FINAL, 'Final Report');

                $items = GradingItem::query()
                    ->whereHas('category', fn ($query) => $query->where('grade_tier_id', $tier->id)->where('is_active', true))
                    ->where('is_active', true)
                    ->where('is_total_field', false)
                    ->get();

                foreach ($students as $studentIndex => $student) {
                    foreach ($section->courses->where('has_exam', true)->values() as $courseIndex => $course) {
                        foreach (TermWindow::TERMS as $termIndex => $term) {
                            foreach ($items as $itemIndex => $item) {
                                $ratio = 0.70 + (($sectionIndex + $studentIndex + $courseIndex + $termIndex + $itemIndex) % 8) * 0.035;
                                StudentScore::updateOrCreate(
                                    [
                                        'student_profile_id' => $student->id,
                                        'course_id' => $course->id,
                                        'grading_item_id' => $item->id,
                                        'term' => $term,
                                        'academic_year' => $section->academic_year,
                                    ],
                                    [
                                        'course_section_id' => $section->id,
                                        'teacher_id' => $course->teacher_id,
                                        'score_obtained' => round((float) $item->max_score * $ratio, 2),
                                        'max_score' => (float) $item->max_score,
                                        'created_by' => $course->teacher_id,
                                        'updated_by' => $course->teacher_id,
                                    ],
                                );
                            }
                        }
                    }

                    foreach (range(1, 5) as $day) {
                        AttendanceRecord::updateOrCreate(
                            ['course_section_id' => $section->id, 'student_profile_id' => $student->id, 'attendance_date' => now()->subDays($day)->toDateString()],
                            ['status' => ($studentIndex === 1 && $day === 3) ? 'absent' : 'present', 'recorded_by' => $section->teacher_id],
                        );
                    }
                }
            }
        $this->command?->info('Full-school demo data is ready. Every demo account password is Demo@12345');
    }

    private function assignTeachers(CourseSection $section, string $password)
    {
        return $section->courses->values()->chunk(2)->map(function ($courses, int $group) use ($section, $password) {
            $key = $this->key($section).'-'.($group + 1);
            $teacher = User::updateOrCreate(
                ['email' => "demo.teacher.{$key}@school.test"],
                [
                    'name' => 'معلم تجريبي '.($group + 1).' - '.$section->class_name,
                    'username' => "demo.teacher.{$key}",
                    'phone' => '05'.str_pad((string) ($section->id * 100 + $group), 8, '0', STR_PAD_LEFT),
                    'academic_qualification' => 'بكالوريوس تربية',
                    'password' => $password,
                    'user_type' => 'teacher',
                    'is_active' => true,
                ],
            );
            Course::query()->whereIn('id', $courses->pluck('id'))->update(['teacher_id' => $teacher->id]);
            $courses->each(fn (Course $course) => $course->teacher_id = $teacher->id);

            return $teacher;
        });
    }

    private function studentWithGuardian(CourseSection $section, int $grade, int $number, string $password): StudentProfile
    {
        $key = $this->key($section);
        $suffix = "{$key}.{$number}";
        $studentName = $number === 1 ? "ليان أحمد - {$section->class_name}" : "عمر أحمد - {$section->class_name}";
        $parentName = $number === 1 ? "أحمد ولي أمر ليان - {$section->class_name}" : "خالد ولي أمر عمر - {$section->class_name}";

        $parentUser = User::updateOrCreate(
            ['email' => "demo.parent.{$suffix}@school.test"],
            ['name' => $parentName, 'username' => "demo.parent.{$suffix}", 'password' => $password, 'user_type' => 'parent', 'is_active' => true],
        );
        $guardian = ParentGuardian::updateOrCreate(
            ['parent_admission_no' => 'DEMO-P-'.strtoupper($key).'-'.$number],
            ['user_id' => $parentUser->id, 'first_name' => $number === 1 ? 'أحمد' : 'خالد', 'last_name' => 'تجريبي', 'full_name' => $parentName, 'relation' => 'father', 'mobile' => '05'.str_pad((string) ($section->id * 10 + $number), 8, '0', STR_PAD_LEFT)],
        );
        $studentUser = User::updateOrCreate(
            ['email' => "demo.student.{$suffix}@school.test"],
            ['name' => $studentName, 'username' => "demo.student.{$suffix}", 'password' => $password, 'user_type' => 'student', 'is_active' => true],
        );
        $studentNumber = 'DEMO-'.strtoupper($key).'-S'.$number;
        $startYear = (int) substr($section->academic_year, 0, 4);
        $student = StudentProfile::updateOrCreate(
            ['student_number' => $studentNumber],
            [
                'user_id' => $studentUser->id,
                'student_code' => $studentNumber,
                'admission_no' => $studentNumber,
                'admission_date' => $startYear.'-08-23',
                'grade_level' => 'G'.$grade,
                'current_grade_level' => $grade,
                'section_id' => $section->id,
                'academic_year' => $section->academic_year,
                'full_name' => $studentName,
                'arabic_name' => $studentName,
                'date_of_birth' => (2019 - $grade).'-0'.($number + 2).'-15',
                'gender' => $number === 1 ? 'female' : 'male',
                'nationality' => 'Libyan',
                'nationality_ar' => 'ليبي',
                'city' => 'طرابلس',
                'guardian_name' => $parentName,
                'guardian_phone' => $guardian->mobile,
                'parent_full_name' => $parentName,
                'parent_relation' => 'father',
                'parent_email' => $parentUser->email,
                'parent_mobile_phone' => $guardian->mobile,
                'parent_username' => $parentUser->username,
                'status' => 'active',
            ],
        );
        Enrollment::updateOrCreate(
            ['student_profile_id' => $student->id, 'course_section_id' => $section->id],
            ['status' => 'active', 'enrolled_at' => $startYear.'-08-23'],
        );
        $guardian->students()->syncWithoutDetaching([$student->id => ['relation' => 'father', 'is_primary' => true]]);

        return $student;
    }

    private function publish(CourseSection $section, User $admin, string $type, string $period): void
    {
        ReportCardPublication::updateOrCreate(
            ['course_section_id' => $section->id, 'academic_year' => $section->academic_year, 'period' => $period],
            ['type' => $type, 'published_at' => now(), 'published_by' => $admin->id],
        );
    }

    private function gradeNumber(CourseSection $section): int
    {
        preg_match('/(?:GRADE|G)\s*(\d{1,2})/i', $section->class_name.' '.$section->section_code, $matches);

        return max(1, min(12, (int) ($matches[1] ?? 1)));
    }

    private function key(CourseSection $section): string
    {
        return strtolower(Str::slug($section->section_code ?: $section->class_name, '.'));
    }
}
