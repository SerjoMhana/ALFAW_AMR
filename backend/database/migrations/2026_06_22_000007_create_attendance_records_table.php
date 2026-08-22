<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Named explicitly: the generated name runs to 78 characters and
            // MySQL caps an identifier at 64.
            $table->unique(
                ['course_section_id', 'student_profile_id', 'attendance_date'],
                'attendance_records_day_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
