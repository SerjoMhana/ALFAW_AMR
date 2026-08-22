<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $studentProfile = $this->route('student_profile') ?? $this->route('studentProfile');

        return [
            'student_number' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('student_profiles', 'student_number')->ignore($studentProfile),
            ],
            'student_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('student_profiles', 'student_code')->ignore($studentProfile),
            ],
            'admission_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('student_profiles', 'admission_no')->ignore($studentProfile),
            ],
            'grade_level' => ['sometimes', 'required', 'string', 'max:20'],
            'current_grade_level' => ['nullable', 'integer', 'between:1,12'],
            'user_name' => ['sometimes', 'required', 'string', 'max:255'],
            'user_email' => ['sometimes', 'required', 'email', 'max:255'],
            'admission_date' => ['nullable', 'date'],
            'student_category' => ['nullable', 'string', 'max:255'],
            'batch' => ['nullable', 'string', 'max:255'],
            'course' => ['nullable', 'string', 'max:255'],
            'section_id' => ['nullable', 'integer', 'exists:course_sections,id'],
            'academic_year' => ['nullable', 'string', 'max:255'],
            'grade_tier' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'arabic_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:30', 'in:male,female,m,f'],
            'blood_group' => ['nullable', 'string', 'max:50'],
            'mother_tongue' => ['nullable', 'string', 'max:255'],
            'religion' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'nationality_ar' => ['nullable', 'string', 'max:255'],
            'national_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('student_profiles', 'national_id')->ignore($studentProfile),
            ],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'pin_code' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'roll_number' => ['nullable', 'string', 'max:255'],
            'biometric_id' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'parent_first_name' => ['nullable', 'string', 'max:255'],
            'parent_last_name' => ['nullable', 'string', 'max:255'],
            'parent_full_name' => ['nullable', 'string', 'max:255'],
            'parent_relation' => ['nullable', 'string', 'max:255'],
            'parent_nationality' => ['nullable', 'string', 'max:255'],
            'parent_username' => ['nullable', 'string', 'max:255'],
            'parent_date_of_birth' => ['nullable', 'date'],
            'parent_education' => ['nullable', 'string', 'max:255'],
            'parent_occupation' => ['nullable', 'string', 'max:255'],
            'parent_income' => ['nullable', 'string', 'max:255'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'parent_office_address_1' => ['nullable', 'string', 'max:255'],
            'parent_office_address_2' => ['nullable', 'string', 'max:255'],
            'parent_city' => ['nullable', 'string', 'max:255'],
            'parent_state' => ['nullable', 'string', 'max:255'],
            'parent_office_phone' => ['nullable', 'string', 'max:50'],
            'parent_mobile_phone' => ['nullable', 'string', 'max:50'],
            'second_parent_full_name' => ['nullable', 'string', 'max:255'],
            'second_parent_relation' => ['nullable', 'string', 'max:255'],
            'second_parent_nationality' => ['nullable', 'string', 'max:255'],
            'second_parent_phone' => ['nullable', 'string', 'max:50'],
            'second_parent_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
