<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_tier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('weight_percentage', 5, 2);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['grade_tier_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_categories');
    }
};
