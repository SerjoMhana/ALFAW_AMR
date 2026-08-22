<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->date('admission_date')->nullable()->after('student_number');
            $table->string('student_category')->nullable()->after('grade_level');
            $table->string('batch')->nullable()->after('student_category');
            $table->string('first_name')->nullable()->after('batch');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('gender')->nullable()->after('date_of_birth');
            $table->string('blood_group')->nullable()->after('gender');
            $table->string('mother_tongue')->nullable()->after('blood_group');
            $table->string('religion')->nullable()->after('mother_tongue');
            $table->string('country')->nullable()->after('religion');
            $table->string('nationality')->nullable()->after('country');
            $table->string('nationality_ar')->nullable()->after('nationality');
            $table->string('national_id')->nullable()->after('nationality_ar');
            $table->string('birth_place')->nullable()->after('national_id');
            $table->string('address_line_1')->nullable()->after('birth_place');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('state')->nullable()->after('city');
            $table->string('pin_code')->nullable()->after('state');
            $table->string('phone')->nullable()->after('pin_code');
            $table->string('mobile')->nullable()->after('phone');
            $table->string('roll_number')->nullable()->after('mobile');
            $table->string('biometric_id')->nullable()->after('roll_number');
            $table->string('parent_first_name')->nullable()->after('guardian_phone');
            $table->string('parent_last_name')->nullable()->after('parent_first_name');
            $table->string('parent_full_name')->nullable()->after('parent_last_name');
            $table->string('parent_relation')->nullable()->after('parent_full_name');
            $table->string('parent_username')->nullable()->after('parent_relation');
            $table->date('parent_date_of_birth')->nullable()->after('parent_username');
            $table->string('parent_education')->nullable()->after('parent_date_of_birth');
            $table->string('parent_occupation')->nullable()->after('parent_education');
            $table->string('parent_income')->nullable()->after('parent_occupation');
            $table->string('parent_email')->nullable()->after('parent_income');
            $table->string('parent_office_address_1')->nullable()->after('parent_email');
            $table->string('parent_office_address_2')->nullable()->after('parent_office_address_1');
            $table->string('parent_city')->nullable()->after('parent_office_address_2');
            $table->string('parent_state')->nullable()->after('parent_city');
            $table->string('parent_office_phone')->nullable()->after('parent_state');
            $table->string('parent_mobile_phone')->nullable()->after('parent_office_phone');
            $table->timestamp('archived_at')->nullable()->index()->after('parent_mobile_phone');
            $table->string('archive_reason')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'admission_date',
                'student_category',
                'batch',
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'blood_group',
                'mother_tongue',
                'religion',
                'country',
                'nationality',
                'nationality_ar',
                'national_id',
                'birth_place',
                'address_line_1',
                'address_line_2',
                'city',
                'state',
                'pin_code',
                'phone',
                'mobile',
                'roll_number',
                'biometric_id',
                'parent_first_name',
                'parent_last_name',
                'parent_full_name',
                'parent_relation',
                'parent_username',
                'parent_date_of_birth',
                'parent_education',
                'parent_occupation',
                'parent_income',
                'parent_email',
                'parent_office_address_1',
                'parent_office_address_2',
                'parent_city',
                'parent_state',
                'parent_office_phone',
                'parent_mobile_phone',
                'archived_at',
                'archive_reason',
            ]);
        });
    }
};
