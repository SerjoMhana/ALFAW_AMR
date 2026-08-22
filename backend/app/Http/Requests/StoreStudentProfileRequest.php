<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class StoreStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->is('api/student-profiles')) {
            return [
                'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('user_type', 'student')],
                'user_name' => ['required_without:user_id', 'string', 'max:255'],
                'user_email' => ['required_without:user_id', 'email', 'max:255', 'unique:users,email'],
                'user_password' => ['nullable', 'string', Password::defaults()],
                'student_number' => ['required', 'string', 'max:255', 'unique:student_profiles,student_number'],
                'student_code' => ['nullable', 'string', 'max:255', 'unique:student_profiles,student_code'],
                'admission_no' => ['nullable', 'string', 'max:255', 'unique:student_profiles,admission_no', 'unique:users,username'],
                'grade_level' => ['required', 'string', 'max:20'],
                'current_grade_level' => ['nullable', 'integer', 'between:1,12'],
                'sibling_ids' => ['nullable', 'array'],
                'sibling_ids.*' => ['integer', 'distinct', 'exists:student_profiles,id'],
                'use_sibling_parent' => ['nullable', 'boolean'],
                'parent' => ['nullable', 'array'],
                'parent.first_name' => ['nullable', 'string', 'max:255'],
                'parent.last_name' => ['nullable', 'string', 'max:255'],
                'parent.relation' => ['nullable', 'string', 'in:father,mother,other'],
                'parent.mobile' => ['nullable', 'string', 'max:50'],
                ...$this->optionalProfileRules(),
            ];
        }

        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('user_type', 'student')],
            'user_name' => ['nullable', 'string', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'user_password' => ['nullable', 'string', Password::defaults()],
            'student_number' => ['nullable', 'string', 'max:255', 'unique:student_profiles,student_number'],
            'student_code' => ['nullable', 'string', 'max:255', 'unique:student_profiles,student_code'],
            'admission_no' => ['nullable', 'string', 'max:255', 'unique:student_profiles,admission_no', 'unique:users,username'],
            'admission_date' => ['required', 'date'],
            'first_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', 'string', 'max:30', 'in:male,female,m,f'],
            'course' => ['required', 'string', 'max:255'],
            'batch' => ['nullable', 'string', 'max:255'],
            'academic_year' => ['required', 'string', 'max:20'],
            'grade_level' => ['nullable', 'string', 'max:20'],
            'current_grade_level' => ['nullable', 'integer', 'between:1,12'],
            'sibling_ids' => ['nullable', 'array'],
            'sibling_ids.*' => ['integer', 'distinct', 'exists:student_profiles,id'],
            'use_sibling_parent' => ['nullable', 'boolean'],
            'parent' => ['required', 'array'],
            'parent.first_name' => ['required', 'string', 'max:255'],
            'parent.last_name' => ['nullable', 'string', 'max:255'],
            'parent.relation' => ['required', 'string', 'in:father,mother,other'],
            'parent.mobile' => ['nullable', 'string', 'max:50'],
            ...$this->profileRules(),
        ];
    }

    private function profileRules(): array
    {
        return [
            'admission_date' => ['required', 'date'],
            'student_category' => ['nullable', 'string', 'max:255'],
            'batch' => ['nullable', 'string', 'max:255'],
            'course' => ['required', 'string', 'max:255'],
            'section_id' => ['nullable', 'integer', 'exists:course_sections,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'grade_tier' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'arabic_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'gender' => ['required', 'string', 'max:30', 'in:male,female,m,f'],
            'blood_group' => ['nullable', 'string', 'max:50'],
            'mother_tongue' => ['nullable', 'string', 'max:255'],
            'religion' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'nationality_ar' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:255', 'unique:student_profiles,national_id'],
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

    private function optionalProfileRules(): array
    {
        $rules = $this->profileRules();

        foreach (['admission_date', 'batch', 'course', 'academic_year', 'first_name', 'date_of_birth', 'gender'] as $field) {
            $rules[$field][0] = 'nullable';
        }

        return $rules;
    }
}
