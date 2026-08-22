<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('parent_admission_no')->unique();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('full_name');
            $table->enum('relation', ['father', 'mother', 'other']);
            $table->string('mobile', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('parent_student', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('parents')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->enum('relation', ['father', 'mother', 'other']);
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
            $table->unique(['parent_id', 'student_id']);
        });

        Schema::create('student_siblings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('sibling_student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'sibling_student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_siblings');
        Schema::dropIfExists('parent_student');
        Schema::dropIfExists('parents');
    }
};
