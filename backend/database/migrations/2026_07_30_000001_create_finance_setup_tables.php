<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable definitions: what the school charges, how it is split into
 * instalments, and which discounts exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->string('academic_year', 20);
            // Null grade level means the fee applies to every grade that year.
            $table->string('grade_level')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['academic_year', 'grade_level']);
        });

        Schema::create('installment_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('academic_year', 20);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('installment_plan_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installment_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('label')->nullable();
            $table->decimal('percentage', 5, 2);
            $table->date('due_date');
            $table->timestamps();

            $table->unique(['installment_plan_id', 'sequence']);
        });

        Schema::create('discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            // Which fee categories the discount may touch, e.g. ["tuition"].
            $table->json('applies_to_categories')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            // Sibling ordinal -> percentage, e.g. {"2": 10, "3": 15}.
            $table->json('sibling_tiers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rules');
        Schema::dropIfExists('installment_plan_rows');
        Schema::dropIfExists('installment_plans');
        Schema::dropIfExists('fee_templates');
    }
};
