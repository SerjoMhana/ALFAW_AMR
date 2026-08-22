<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each student owes: the charges raised against them, any discounts, and
 * the instalments those net down to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            // Kept for traceability only; the amount below is a snapshot so that
            // editing a template later never rewrites history.
            $table->foreignId('fee_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category');
            $table->string('academic_year', 20);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['student_profile_id', 'academic_year']);
        });

        Schema::create('student_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('student_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('academic_year', 20);
            $table->unsignedSmallInteger('sequence');
            $table->string('label')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status')->default('unpaid')->index();
            $table->timestamps();

            $table->unique(['student_profile_id', 'academic_year', 'sequence'], 'student_installments_unique_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_installments');
        Schema::dropIfExists('student_discounts');
        Schema::dropIfExists('student_fees');
    }
};
