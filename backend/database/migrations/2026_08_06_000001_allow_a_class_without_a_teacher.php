<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class is created before the school knows who will teach it, so the teacher
 * is optional. Until now the column was NOT NULL, and the controller papered
 * over that by inventing a "Default Teacher" account — a real, weakly
 * passworded login that nobody asked for.
 *
 * Deleting a teacher now unassigns their classes rather than being blocked:
 * an unassigned class is a valid state, and the marks live on student_scores,
 * which does not reference the teacher for its own identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
        });

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreignId('teacher_id')->nullable()->change();
        });

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreign('teacher_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
        });

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreignId('teacher_id')->nullable(false)->change();
        });

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreign('teacher_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
