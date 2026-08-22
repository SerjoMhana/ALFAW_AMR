<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentParentSiblingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_creation_links_parent_and_siblings(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
        $token = $admin->createToken('test')->plainTextToken;

        $firstStudentId = $this->withToken($token)->postJson('/api/students', [
            'admission_no' => 'A1001',
            'admission_date' => '2026-06-24',
            'user_email' => 'mariam.smith@example.com',
            'first_name' => 'Mariam',
            'last_name' => 'Smith',
            'date_of_birth' => '2012-05-12',
            'gender' => 'female',
            'course' => 'Grade 8',
            'batch' => 'G8-A',
            'academic_year' => '2025-2026',
            'parent' => [
                'first_name' => 'Omar',
                'last_name' => 'Smith',
                'relation' => 'father',
                'mobile' => '5551001',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.parents.0.parent_admission_no', 'PA1001')
            ->json('data.id');

        $this->withToken($token)->getJson('/api/students/search-siblings?q=Mariam')
            ->assertOk()
            ->assertJsonPath('data.0.parent_full_name', 'Omar Smith');

        $secondStudentId = $this->withToken($token)->postJson('/api/students', [
            'admission_no' => 'A1002',
            'admission_date' => '2026-06-24',
            'user_email' => 'adam.smith@example.com',
            'first_name' => 'Adam',
            'last_name' => 'Smith',
            'date_of_birth' => '2014-03-20',
            'gender' => 'male',
            'course' => 'Grade 6',
            'batch' => 'G6-A',
            'academic_year' => '2025-2026',
            'sibling_ids' => [$firstStudentId],
            'use_sibling_parent' => true,
            'parent' => [
                'first_name' => 'Temporary',
                'relation' => 'other',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.parents.0.parent_admission_no', 'PA1001')
            ->json('data.id');

        $this->assertDatabaseCount('parents', 1);
        $this->assertDatabaseHas('parent_student', [
            'student_id' => $firstStudentId,
            'relation' => 'father',
        ]);
        $this->assertDatabaseHas('parent_student', [
            'student_id' => $secondStudentId,
            'relation' => 'father',
        ]);
        $this->assertDatabaseHas('student_siblings', [
            'student_id' => $firstStudentId,
            'sibling_student_id' => $secondStudentId,
        ]);
    }
}
