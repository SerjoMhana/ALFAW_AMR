<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->change();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('teacher_id')->nullable()->after('class_section_id')->constrained('users')->nullOnDelete();
        });

        /*
         * The replacement key goes on before the old one comes off.
         *
         * A foreign key on student_profile_id leans on whichever index leads
         * with that column, and MySQL refuses to drop the last one covering it.
         * SQLite never minded, which is why this only shows up on MySQL.
         */
        Schema::table('student_scores', function (Blueprint $table): void {
            $table->unique(
                ['student_profile_id', 'course_id', 'grading_item_id', 'term', 'academic_year'],
                'student_scores_entry_unique',
            );
        });

        Schema::table('student_scores', function (Blueprint $table): void {
            $table->dropUnique('student_scores_unique_entry');
        });

        Schema::table('student_scores', function (Blueprint $table): void {
            $table->foreignId('course_section_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_scores', function (Blueprint $table): void {
            $table->unique(
                ['student_profile_id', 'course_section_id', 'grading_item_id', 'term', 'academic_year'],
                'student_scores_unique_entry',
            );
        });

        Schema::table('student_scores', function (Blueprint $table): void {
            $table->dropUnique('student_scores_entry_unique');
        });

        Schema::table('student_scores', function (Blueprint $table): void {
            $table->foreignId('course_section_id')->nullable(false)->change();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('teacher_id');
        });

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable(false)->change();
        });
    }
};
