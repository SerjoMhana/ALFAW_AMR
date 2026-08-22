<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('student_profiles', 'student_code')) {
                $table->string('student_code')->nullable()->index()->after('user_id');
            }
            if (! Schema::hasColumn('student_profiles', 'admission_no')) {
                $table->string('admission_no')->nullable()->index()->after('student_code');
            }
            if (! Schema::hasColumn('student_profiles', 'full_name')) {
                $table->string('full_name')->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('student_profiles', 'arabic_name')) {
                $table->string('arabic_name')->nullable()->after('full_name');
            }
            if (! Schema::hasColumn('student_profiles', 'current_grade_level')) {
                $table->unsignedTinyInteger('current_grade_level')->nullable()->index()->after('grade_level');
            }
            if (! Schema::hasColumn('student_profiles', 'grade_tier')) {
                $table->string('grade_tier')->nullable()->index()->after('current_grade_level');
            }
            if (! Schema::hasColumn('student_profiles', 'course')) {
                $table->string('course')->nullable()->after('batch');
            }
            if (! Schema::hasColumn('student_profiles', 'section_id')) {
                $table->foreignId('section_id')->nullable()->after('course')->constrained('course_sections')->nullOnDelete();
            }
            if (! Schema::hasColumn('student_profiles', 'academic_year')) {
                $table->string('academic_year')->nullable()->index()->after('section_id');
            }
            if (! Schema::hasColumn('student_profiles', 'status')) {
                $table->string('status')->default('active')->index()->after('parent_mobile_phone');
            }
            if (! Schema::hasColumn('student_profiles', 'archived_by')) {
                $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('student_profiles', 'previous_section_id')) {
                $table->foreignId('previous_section_id')->nullable()->after('archive_reason')->constrained('course_sections')->nullOnDelete();
            }
            if (! Schema::hasColumn('student_profiles', 'restored_at')) {
                $table->timestamp('restored_at')->nullable()->after('previous_section_id');
            }
            if (! Schema::hasColumn('student_profiles', 'restored_by')) {
                $table->foreignId('restored_by')->nullable()->after('restored_at')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('student_profiles')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'active']);

        DB::table('student_profiles')
            ->whereNotNull('archived_at')
            ->update(['status' => 'archived']);
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            foreach (['restored_by', 'previous_section_id', 'archived_by', 'section_id'] as $foreignColumn) {
                if (Schema::hasColumn('student_profiles', $foreignColumn)) {
                    $table->dropConstrainedForeignId($foreignColumn);
                }
            }

            $columns = [
                'student_code',
                'admission_no',
                'full_name',
                'arabic_name',
                'current_grade_level',
                'grade_tier',
                'course',
                'academic_year',
                'status',
                'restored_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('student_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
