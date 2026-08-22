<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\GradeSubmission;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Services\ClassReportCardService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Attaching a subject to a class, and moving it between classes.
 *
 * A subject with no class is invisible to the students who take it — a student
 * reaches a subject through their class — so this link is what makes the rest
 * of the system able to see it at all.
 */
class CourseSectionLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CourseSection $g11;

    private CourseSection $g12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test');
        $this->g11 = $this->section('G11', 'G11-A');
        $this->g12 = $this->section('G12', 'G12-A');
    }

    public function test_loose_subjects_are_attached_to_a_class_in_one_pass(): void
    {
        $first = $this->course('MTH', 'Mathematics');
        $second = $this->course('PHY', 'Physics');

        $this->withUser($this->admin)
            ->postJson('/api/courses/assign-section', [
                'class_section_id' => $this->g12->id,
                'course_ids' => [$first->id, $second->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.linked', 2);

        $this->assertSame($this->g12->id, $first->fresh()->class_section_id);
        $this->assertSame($this->g12->id, $second->fresh()->class_section_id);
    }

    /**
     * The class decides the grade, so the subject cannot keep saying G11 after
     * moving into G12 — the grading scheme is chosen from that label.
     */
    public function test_linking_takes_the_grade_from_the_class(): void
    {
        $course = $this->course('MTH', 'Mathematics', gradeLevel: 'G9');

        $this->withUser($this->admin)
            ->postJson('/api/courses/assign-section', [
                'class_section_id' => $this->g12->id,
                'course_ids' => [$course->id],
            ])
            ->assertOk();

        $this->assertSame('G12', $course->fresh()->grade_level);
    }

    public function test_a_subject_is_moved_to_another_class_from_the_edit_form(): void
    {
        $course = $this->course('MTH', 'Mathematics', section: $this->g11, gradeLevel: 'G11');

        $this->withUser($this->admin)
            ->putJson("/api/courses/{$course->id}", ['class_section_id' => $this->g12->id])
            ->assertOk()
            ->assertJsonPath('data.class_section_id', $this->g12->id);

        $this->assertSame('G12', $course->fresh()->grade_level);
    }

    public function test_editing_a_subject_without_moving_it_leaves_its_grade_alone(): void
    {
        $course = $this->course('MTH', 'Mathematics', section: $this->g11, gradeLevel: 'Grade 11 Advanced');

        $this->withUser($this->admin)
            ->putJson("/api/courses/{$course->id}", ['name' => 'Mathematics II'])
            ->assertOk();

        $this->assertSame('Grade 11 Advanced', $course->fresh()->grade_level);
    }

    public function test_an_explicit_grade_wins_over_the_one_taken_from_the_class(): void
    {
        $course = $this->course('MTH', 'Mathematics', section: $this->g11);

        $this->withUser($this->admin)
            ->putJson("/api/courses/{$course->id}", [
                'class_section_id' => $this->g12->id,
                'grade_level' => 'AP',
            ])
            ->assertOk();

        $this->assertSame('AP', $course->fresh()->grade_level);
    }

    public function test_a_class_that_does_not_exist_is_refused(): void
    {
        $course = $this->course('MTH', 'Mathematics');

        $this->withUser($this->admin)
            ->postJson('/api/courses/assign-section', [
                'class_section_id' => 9999,
                'course_ids' => [$course->id],
            ])
            ->assertStatus(422);

        $this->assertNull($course->fresh()->class_section_id);
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/assign-section', [
                'class_section_id' => $this->g12->id,
                'course_ids' => [],
            ])
            ->assertStatus(422);
    }

    public function test_staff_without_the_permission_cannot_relink_subjects(): void
    {
        $staff = $this->user('staff', 'staff@school.test');
        $course = $this->course('MTH', 'Mathematics');

        $this->withUser($staff)
            ->postJson('/api/courses/assign-section', [
                'class_section_id' => $this->g12->id,
                'course_ids' => [$course->id],
            ])
            ->assertForbidden();

        $this->assertNull($course->fresh()->class_section_id);
    }

    /**
     * The point of the whole exercise: once linked, the class's students can
     * reach the subject in the classroom.
     */
    public function test_a_linked_subject_becomes_visible_to_its_teacher(): void
    {
        $teacher = $this->user('teacher', 'teacher@school.test');
        $course = $this->course('MTH', 'Mathematics');
        $course->update(['teacher_id' => $teacher->id]);

        // A subject with no class belongs to no year, so the classroom — which
        // shows the year being worked in — has nothing to show yet.
        $this->withUser($teacher)
            ->getJson('/api/classroom/courses')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withUser($this->admin)
            ->postJson('/api/courses/assign-section', [
                'class_section_id' => $this->g12->id,
                'course_ids' => [$course->id],
            ])
            ->assertOk();

        $after = $this->withUser($teacher)->getJson('/api/classroom/courses')->json('data.0');
        $this->assertSame('G12', $after['class_name']);
    }

    // ---- deleting subjects that belong nowhere ---------------------------------

    public function test_the_warning_counts_what_deleting_the_subjects_would_take(): void
    {
        $first = $this->course('MTH', 'Mathematics');
        $second = $this->course('PHY', 'Physics');
        GradeSubmission::create([
            'course_id' => $first->id,
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
            'status' => 'submitted',
        ]);

        $body = $this->withUser($this->admin)
            ->postJson('/api/courses/deletion-impact', ['course_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $body['counts']['courses']);
        $this->assertSame(1, $body['counts']['grade_submissions']);
        // Something other than the subjects themselves would go, so the admin
        // is asked to acknowledge it.
        $this->assertTrue($body['carries_data']);
        $this->assertSame(['Mathematics', 'Physics'], $body['names']);
    }

    public function test_subjects_carrying_nothing_are_flagged_as_safe_to_remove(): void
    {
        $course = $this->course('MTH', 'Mathematics');

        $this->withUser($this->admin)
            ->postJson('/api/courses/deletion-impact', ['course_ids' => [$course->id]])
            ->assertOk()
            ->assertJsonPath('data.carries_data', false)
            ->assertJsonPath('data.counts.courses', 1);
    }

    public function test_several_subjects_are_deleted_in_one_pass(): void
    {
        $first = $this->course('MTH', 'Mathematics');
        $second = $this->course('PHY', 'Physics');
        $kept = $this->course('BIO', 'Biology');

        $this->withUser($this->admin)
            ->postJson('/api/courses/delete-many', ['course_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertDatabaseMissing('courses', ['id' => $first->id]);
        $this->assertDatabaseMissing('courses', ['id' => $second->id]);
        $this->assertDatabaseHas('courses', ['id' => $kept->id]);
    }

    public function test_deleting_a_subject_takes_its_submitted_sheets_with_it(): void
    {
        $course = $this->course('MTH', 'Mathematics');
        GradeSubmission::create([
            'course_id' => $course->id,
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
            'status' => 'submitted',
        ]);

        $this->withUser($this->admin)
            ->postJson('/api/courses/delete-many', ['course_ids' => [$course->id]])
            ->assertOk();

        $this->assertSame(0, GradeSubmission::count());
    }

    public function test_deleting_with_nothing_selected_is_refused(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/delete-many', ['course_ids' => []])
            ->assertStatus(422);
    }

    public function test_staff_without_the_permission_cannot_delete_subjects(): void
    {
        $staff = $this->user('staff', 'clerk@school.test');
        $course = $this->course('MTH', 'Mathematics');

        $this->withUser($staff)
            ->postJson('/api/courses/deletion-impact', ['course_ids' => [$course->id]])
            ->assertForbidden();

        $this->withUser($staff)
            ->postJson('/api/courses/delete-many', ['course_ids' => [$course->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('courses', ['id' => $course->id]);
    }

    // ---- subjects the school does not examine ----------------------------------

    public function test_a_subject_is_examined_unless_it_is_said_otherwise(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_id' => $this->g12->id,
                'courses' => [
                    ['code' => 'MTH', 'name' => 'Mathematics', 'periods_per_week' => 5],
                    ['code' => 'ACT', 'name' => 'Activity', 'periods_per_week' => 1, 'has_exam' => false],
                ],
            ])
            ->assertCreated();

        $this->assertTrue(Course::where('code', 'MTH')->firstOrFail()->has_exam);
        $this->assertFalse(Course::where('code', 'ACT')->firstOrFail()->has_exam);
    }

    public function test_the_flag_is_turned_on_and_off_from_the_edit_form(): void
    {
        $course = $this->course('ACT', 'Activity', section: $this->g12);

        $this->withUser($this->admin)
            ->putJson("/api/courses/{$course->id}", ['has_exam' => false])
            ->assertOk()
            ->assertJsonPath('data.has_exam', false);

        $this->withUser($this->admin)
            ->putJson("/api/courses/{$course->id}", ['has_exam' => true])
            ->assertOk()
            ->assertJsonPath('data.has_exam', true);
    }

    /**
     * The point of the flag: an unexamined subject is not printed on the sheet.
     */
    public function test_an_unexamined_subject_stays_off_the_report_card(): void
    {
        $examined = $this->course('MTH', 'Mathematics', section: $this->g12);
        $this->course('ACT', 'Activity', section: $this->g12)->update(['has_exam' => false]);

        $student = $this->enrolledStudent();

        $sheet = app(ClassReportCardService::class)->quarterReport($this->g12, $student, 'Quarter 1');
        $names = array_column($sheet['subjects'], 'name');

        $this->assertSame(['Mathematics'], $names);
        $this->assertSame($examined->name, $names[0]);
    }

    public function test_an_unexamined_subject_stays_out_of_the_semester_sheet_too(): void
    {
        $this->course('MTH', 'Mathematics', section: $this->g12);
        $this->course('ACT', 'Activity', section: $this->g12)->update(['has_exam' => false]);

        $student = $this->enrolledStudent();

        $sheet = app(ClassReportCardService::class)->semesterReport($this->g12, $student, 1);

        $this->assertSame(['Mathematics'], array_column($sheet['subjects'], 'name'));
    }

    /**
     * It keeps its class and its teacher — it is only the sheet it is absent
     * from, not the school.
     */
    public function test_an_unexamined_subject_still_belongs_to_its_class(): void
    {
        $teacher = $this->user('teacher', 'activity.teacher@school.test');
        $course = $this->course('ACT', 'Activity', section: $this->g12);
        $course->update(['has_exam' => false, 'teacher_id' => $teacher->id]);

        $this->assertSame($this->g12->id, $course->fresh()->class_section_id);

        $this->withUser($teacher)
            ->getJson('/api/classroom/courses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Activity');
    }

    // ---- the same subjects across several classes ------------------------------

    /**
     * Grades share a syllabus: the list is typed once and lands in each class.
     */
    public function test_the_same_subjects_are_added_to_several_classes_at_once(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_ids' => [$this->g11->id, $this->g12->id],
                'courses' => [
                    ['code' => 'ENGLISH', 'name' => 'English', 'periods_per_week' => 7],
                    ['code' => 'MATHS', 'name' => 'Mathematics', 'periods_per_week' => 6],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(4, 'data');

        $this->assertSame(2, Course::where('class_section_id', $this->g11->id)->count());
        $this->assertSame(2, Course::where('class_section_id', $this->g12->id)->count());
        // The same code in both, because a code names a subject in its class.
        $this->assertSame(2, Course::where('code', 'ENGLISH')->count());
    }

    public function test_each_class_stamps_its_own_grade_on_its_copy(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_ids' => [$this->g11->id, $this->g12->id],
                'courses' => [['code' => 'ENGLISH', 'name' => 'English', 'periods_per_week' => 7]],
            ])
            ->assertCreated();

        $this->assertSame('G11', Course::where('class_section_id', $this->g11->id)->firstOrFail()->grade_level);
        $this->assertSame('G12', Course::where('class_section_id', $this->g12->id)->firstOrFail()->grade_level);
    }

    public function test_the_periods_and_the_exam_flag_carry_to_every_class(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_ids' => [$this->g11->id, $this->g12->id],
                'courses' => [['code' => 'ACT', 'name' => 'Activity', 'periods_per_week' => 2, 'has_exam' => false]],
            ])
            ->assertCreated();

        foreach (Course::where('code', 'ACT')->get() as $course) {
            $this->assertSame(2, $course->periods_per_week);
            $this->assertFalse($course->has_exam);
        }
    }

    public function test_a_code_already_used_in_one_of_the_chosen_classes_is_refused(): void
    {
        $this->course('ENGLISH', 'English', section: $this->g12);

        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_ids' => [$this->g11->id, $this->g12->id],
                'courses' => [['code' => 'ENGLISH', 'name' => 'English', 'periods_per_week' => 7]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('courses.0.code');

        // Nothing was written anywhere, not even into the class that was free.
        $this->assertSame(0, Course::where('class_section_id', $this->g11->id)->count());
    }

    public function test_at_least_one_class_has_to_be_chosen(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_ids' => [],
                'courses' => [['code' => 'ENGLISH', 'name' => 'English', 'periods_per_week' => 7]],
            ])
            ->assertStatus(422);
    }

    /**
     * The screen used to send one class; that shape still works.
     */
    public function test_a_single_class_may_still_be_sent_the_old_way(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/courses/bulk', [
                'class_section_id' => $this->g12->id,
                'courses' => [['code' => 'ENGLISH', 'name' => 'English', 'periods_per_week' => 7]],
            ])
            ->assertCreated();

        $this->assertSame(1, Course::where('class_section_id', $this->g12->id)->count());
    }

    // ---- fixtures -------------------------------------------------------------

    private function enrolledStudent(): StudentProfile
    {
        $user = User::create([
            'name' => 'Ali',
            'email' => 'ali@school.test',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'S-1',
            'admission_no' => 'S-1',
            'full_name' => 'Ali',
            'grade_level' => 'G12',
            'academic_year' => '2026-2027',
            'section_id' => $this->g12->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $this->g12->id,
            'status' => 'active',
        ]);

        return $profile;
    }

    private function course(string $code, string $name, ?CourseSection $section = null, ?string $gradeLevel = null): Course
    {
        return Course::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => $gradeLevel ?? 'G12',
            'class_section_id' => $section?->id,
        ]);
    }

    private function section(string $className, string $code): CourseSection
    {
        return CourseSection::create([
            'section_code' => $code,
            'class_name' => $className,
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);
    }

    private function user(string $type, string $email): User
    {
        return User::create([
            'name' => ucfirst($type),
            'email' => $email,
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => $type,
            'is_active' => true,
        ]);
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
