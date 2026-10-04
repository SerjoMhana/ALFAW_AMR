<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\StudentProfile;
use App\Services\ClassReportCardService;
use App\Services\GradeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AnnualReportCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_semester_two_uses_quarters_three_and_four_only(): void
    {
        [$section, $student] = $this->schoolObjects();
        $grades = Mockery::mock(GradeCalculationService::class);
        $grades->shouldReceive('calculateStudentTermGrade')->once()->with($student, Mockery::type(Course::class), 'Quarter 3', '2026-2027')->andReturn(['final_grade' => 70]);
        $grades->shouldReceive('calculateStudentTermGrade')->once()->with($student, Mockery::type(Course::class), 'Quarter 4', '2026-2027')->andReturn(['final_grade' => 90]);

        $report = (new ClassReportCardService($grades))->semesterReport($section, $student, 2);

        $this->assertSame([70.0, 90.0], $report['subjects'][0]['columns']);
        $this->assertSame(80.0, $report['subjects'][0]['final']);
    }

    public function test_final_report_is_the_average_of_the_two_semesters_for_this_year(): void
    {
        [$section, $student] = $this->schoolObjects();
        $grades = Mockery::mock(GradeCalculationService::class);
        foreach (['Quarter 1' => 80, 'Quarter 2' => 90, 'Quarter 3' => 70, 'Quarter 4' => 100] as $term => $grade) {
            $grades->shouldReceive('calculateStudentTermGrade')->once()->with($student, Mockery::type(Course::class), $term, '2026-2027')->andReturn(['final_grade' => $grade]);
        }

        $report = (new ClassReportCardService($grades))->finalReport($section, $student);

        $this->assertSame([85.0, 85.0], $report['subjects'][0]['columns']);
        $this->assertSame(85.0, $report['subjects'][0]['final']);
        $this->assertSame(3.0, $report['gpa']);
        $this->assertTrue($report['is_final']);

        $html = view('pdf.semester-report', [
            'reports' => [$report],
            'semester' => null,
            'isFinal' => true,
            'reportMessage' => '',
        ])->render();
        $this->assertStringContainsString('Final Report Card', $html);
        $this->assertStringContainsString('Semester 1', $html);
        $this->assertStringContainsString('Semester 2', $html);
    }

    private function schoolObjects(): array
    {
        $course = new Course(['name' => 'Math', 'credit_hours' => 1, 'has_exam' => true]);
        $section = new CourseSection(['class_name' => 'G10', 'academic_year' => '2026-2027']);
        $section->setRelation('courses', collect([$course]));
        $student = new StudentProfile(['student_number' => 'S-1', 'full_name' => 'Student']);

        return [$section, $student];
    }
}
