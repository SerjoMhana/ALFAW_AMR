<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop in dependency order: grade_audit_logs references both
        // assessments and the old student_scores.
        Schema::dropIfExists('grade_audit_logs');
        Schema::dropIfExists('student_scores');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('tier_category_weights');

        Schema::create('student_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('grading_item_id')->constrained()->cascadeOnDelete();
            $table->string('term')->index();
            $table->string('academic_year')->index();
            $table->decimal('score_obtained', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['student_profile_id', 'course_section_id', 'grading_item_id', 'term', 'academic_year'],
                'student_scores_unique_entry',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_scores');
    }
};
