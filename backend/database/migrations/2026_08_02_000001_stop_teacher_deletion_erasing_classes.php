<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * course_sections.teacher_id cascaded on delete, so removing one teacher account
 * silently destroyed their classes — and student_scores.course_section_id
 * cascades in turn, taking every mark in those classes with it. A staffing
 * change must never be able to erase a year of grades.
 *
 * The column stays NOT NULL (a class has a teacher), so deletion is instead
 * blocked at the application layer until the classes are reassigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
            $table->foreign('teacher_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['teacher_id']);
            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
