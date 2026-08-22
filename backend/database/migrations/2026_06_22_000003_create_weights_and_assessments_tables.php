<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_category_weights', function (Blueprint $table) {
            $table->id();
            $table->string('grade_tier')->index();
            $table->string('category_name');
            $table->decimal('weight', 5, 2);
            $table->timestamps();

            $table->unique(['grade_tier', 'category_name']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category_name');
            $table->string('term')->index();
            $table->decimal('max_score', 8, 2);
            $table->date('assessment_date')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('tier_category_weights');
    }
};
