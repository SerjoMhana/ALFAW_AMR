<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\CalendarEvent;
use App\Models\ClassPost;
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

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $password = Hash::make('Demo@12345');
            $academicYear = '2026-2027';
            $term = 'Quarter 1';
            $terms = TermWindow::TERMS;

            $admin = User::updateOrCreate(
                ['email' => 'demo.admin@school.test'],
                [
                    'name' => 'مدير النظام التجريبي',
                    'username' => 'demo.admin',
                    'password' => $password,
                    'user_type' => 'admin',
                    'is_active' => true,
                ],
            );

            $teacher = User::updateOrCreate(
                ['email' => 'demo.teacher@school.test'],
                [
                    'name' => 'الأستاذة سارة أحمد',
                    'username' => 'demo.teacher',
                    'phone' => '0500001001',
                    'academic_qualification' => 'بكالوريوس تربية وعلوم',
                    'password' => $password,
                    'user_type' => 'teacher',
                    'is_active' => true,
                ],
            );

            $parentUser = User::updateOrCreate(
                ['email' => 'demo.parent@school.test'],
                [
                    'name' => 'السيد خالد محمد',
                    'username' => 'demo.parent',
                    'phone' => '0500002001',
                    'password' => $password,
                    'user_type' => 'parent',
                    'is_active' => true,
                ],
            );

            $guardian = ParentGuardian::updateOrCreate(
                ['parent_admission_no' => 'DEMO-P-001'],
                [
                    'user_id' => $parentUser->id,
                    'first_name' => 'خالد',
                    'last_name' => 'محمد',
                    'full_name' => 'خالد محمد',
                    'relation' => 'father',
                    'mobile' => '0500002001',
                ],
            );

            $tier = GradeTier::query()
                ->where('min_grade', '<=', 7)
                ->where('max_grade', '>=', 7)
                ->where('is_active', true)
                ->firstOrFail();

            $section = CourseSection::query()->where('section_code', 'G7')->firstOrFail();
            $section->update([
                'teacher_id' => $teacher->id,
                'academic_year' => $academicYear,
                'term' => $term,
                'grade_tier_id' => $tier->id,
                'capacity' => 25,
            ]);

            $courses = Course::query()
                ->where('class_section_id', $section->id)
                ->orderBy('id')
                ->get();

            abort_if($courses->isEmpty(), 500, 'The G7 demo class has no subjects.');
            Course::query()->whereIn('id', $courses->pluck('id'))->update(['teacher_id' => $teacher->id]);

            $studentDefinitions = [
                [
                    'email' => 'demo.student@school.test',
                    'username' => 'demo.student',
                    'name' => 'ليان خالد محمد',
                    'student_number' => 'DEMO-S-001',
                    'arabic_name' => 'ليان خالد محمد',
                    'gender' => 'female',
                    'date_of_birth' => '2013-03-15',
                ],
                [
                    'email' => 'demo.student2@school.test',
                    'username' => 'demo.student2',
                    'name' => 'عمر خالد محمد',
                    'student_number' => 'DEMO-S-002',
                    'arabic_name' => 'عمر خالد محمد',
                    'gender' => 'male',
                    'date_of_birth' => '2013-09-08',
                ],
            ];

            $students = collect($studentDefinitions)->map(function (array $definition) use (
                $password,
                $academicYear,
                $section,
                $guardian,
            ): StudentProfile {
                $user = User::updateOrCreate(
                    ['email' => $definition['email']],
                    [
                        'name' => $definition['name'],
                        'username' => $definition['username'],
                        'password' => $password,
                        'user_type' => 'student',
                        'is_active' => true,
                    ],
                );

                $student = StudentProfile::updateOrCreate(
                    ['student_number' => $definition['student_number']],
                    [
                        'user_id' => $user->id,
                        'student_code' => $definition['student_number'],
                        'admission_no' => $definition['student_number'],
                        'admission_date' => '2026-08-23',
                        'grade_level' => 'G7',
                        'current_grade_level' => 7,
                        'section_id' => $section->id,
                        'academic_year' => $academicYear,
                        'full_name' => $definition['name'],
                        'arabic_name' => $definition['arabic_name'],
                        'date_of_birth' => $definition['date_of_birth'],
                        'gender' => $definition['gender'],
                        'nationality' => 'Saudi',
                        'nationality_ar' => 'سعودي',
                        'city' => 'الرياض',
                        'guardian_name' => 'خالد محمد',
                        'guardian_phone' => '0500002001',
                        'parent_full_name' => 'خالد محمد',
                        'parent_relation' => 'father',
                        'parent_email' => 'demo.parent@school.test',
                        'parent_mobile_phone' => '0500002001',
                        'parent_username' => 'demo.parent',
                        'status' => 'active',
                    ],
                );

                Enrollment::updateOrCreate(
                    [
                        'student_profile_id' => $student->id,
                        'course_section_id' => $section->id,
                    ],
                    ['status' => 'active', 'enrolled_at' => '2026-08-23'],
                );

                $guardian->students()->syncWithoutDetaching([
                    $student->id => ['relation' => 'father', 'is_primary' => true],
                ]);

                return $student;
            });

            foreach ($terms as $openTerm) {
                TermWindow::updateOrCreate(
                    ['academic_year' => $academicYear, 'term' => $openTerm],
                    [
                        'is_open' => true,
                        'opened_at' => now(),
                        'opened_by' => $admin->id,
                        'closed_at' => null,
                        'closed_by' => null,
                    ],
                );

                ReportCardPublication::updateOrCreate(
                    [
                        'course_section_id' => $section->id,
                        'academic_year' => $academicYear,
                        'period' => $openTerm,
                    ],
                    [
                        'type' => ReportCardPublication::TYPE_QUARTER,
                        'published_at' => now(),
                        'published_by' => $admin->id,
                    ],
                );
            }

            foreach ([1, 2] as $semester) {
                ReportCardPublication::updateOrCreate(
                    [
                        'course_section_id' => $section->id,
                        'academic_year' => $academicYear,
                        'period' => "Semester {$semester}",
                    ],
                    [
                        'type' => ReportCardPublication::TYPE_SEMESTER,
                        'published_at' => now(),
                        'published_by' => $admin->id,
                    ],
                );
            }

            $items = GradingItem::query()
                ->whereHas('category', fn ($query) => $query->where('grade_tier_id', $tier->id)->where('is_active', true))
                ->where('is_active', true)
                ->where('is_total_field', false)
                ->with('category')
                ->orderBy('display_order')
                ->get();

            foreach ($students as $studentIndex => $student) {
                foreach ($courses->where('has_exam', true) as $courseIndex => $course) {
                    foreach ($terms as $termIndex => $scoreTerm) {
                        foreach ($items as $itemIndex => $item) {
                            $maxScore = (float) $item->max_score;
                            $percentage = 0.72 + (($studentIndex + $courseIndex + $termIndex + $itemIndex) % 7) * 0.04;

                            StudentScore::updateOrCreate(
                                [
                                    'student_profile_id' => $student->id,
                                    'course_id' => $course->id,
                                    'grading_item_id' => $item->id,
                                    'term' => $scoreTerm,
                                    'academic_year' => $academicYear,
                                ],
                                [
                                    'course_section_id' => $section->id,
                                    'teacher_id' => $teacher->id,
                                    'score_obtained' => round($maxScore * $percentage, 2),
                                    'max_score' => $maxScore,
                                    'created_by' => $teacher->id,
                                    'updated_by' => $teacher->id,
                                ],
                            );
                        }
                    }
                }

                foreach (range(1, 5) as $daysAgo) {
                    AttendanceRecord::updateOrCreate(
                        [
                            'course_section_id' => $section->id,
                            'student_profile_id' => $student->id,
                            'attendance_date' => now()->subDays($daysAgo)->toDateString(),
                        ],
                        [
                            'status' => ($studentIndex === 1 && $daysAgo === 3) ? 'absent' : 'present',
                            'notes' => ($studentIndex === 1 && $daysAgo === 3) ? 'غياب تجريبي بعذر' : null,
                            'recorded_by' => $teacher->id,
                        ],
                    );
                }
            }

            foreach ($courses->take(2) as $index => $course) {
                ClassPost::updateOrCreate(
                    ['course_id' => $course->id, 'title' => $index === 0 ? 'مرحبًا بطلاب الصف السابع' : 'واجب الأسبوع الأول'],
                    [
                        'author_id' => $teacher->id,
                        'type' => $index === 0 ? ClassPost::TYPE_ANNOUNCEMENT : ClassPost::TYPE_MATERIAL,
                        'body' => $index === 0
                            ? 'نتمنى لكم عامًا دراسيًا ناجحًا. هذا إعلان تجريبي لاختبار ساحة الصف.'
                            : 'يرجى مراجعة الدرس الأول وحل الأسئلة المرفقة في الكتاب.',
                        'published_at' => now()->subDays(2 - $index),
                        'pinned_at' => $index === 0 ? now()->subDays(2) : null,
                        'comments_enabled' => true,
                        'academic_year' => $academicYear,
                    ],
                );
            }

            CalendarEvent::updateOrCreate(
                ['title' => 'اجتماع أولياء الأمور التجريبي', 'academic_year' => $academicYear],
                [
                    'description' => 'موعد تجريبي لمراجعة تقدم الطلاب مع المعلمين.',
                    'kind' => 'meeting',
                    'color' => CalendarEvent::KIND_COLORS['meeting'],
                    'starts_on' => now()->addDays(7)->toDateString(),
                    'ends_on' => now()->addDays(7)->toDateString(),
                    'all_day' => false,
                    'starts_at' => '16:00:00',
                    'ends_at' => '18:00:00',
                    'location' => 'قاعة الاجتماعات',
                    'created_by' => $admin->id,
                ],
            );

            CalendarEvent::updateOrCreate(
                ['title' => 'اختبار منتصف الفصل التجريبي', 'academic_year' => $academicYear],
                [
                    'description' => 'حدث تجريبي ظاهر في التقويم المدرسي.',
                    'kind' => 'exam',
                    'color' => CalendarEvent::KIND_COLORS['exam'],
                    'starts_on' => now()->addDays(14)->toDateString(),
                    'ends_on' => now()->addDays(14)->toDateString(),
                    'all_day' => true,
                    'starts_at' => null,
                    'ends_at' => null,
                    'location' => 'الفصول الدراسية',
                    'created_by' => $admin->id,
                ],
            );
        });

        $this->command?->info('Demo users and school data are ready. Password: Demo@12345');
    }
}
