<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('max_score', 6, 2);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_total_field')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['grading_category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_items');
    }
};
