<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the instalment layer and makes the fee itself the unit of receivable.
 *
 * A charge now carries its own due date and paid amount, and a receipt is
 * allocated straight onto charges — so a guardian can pay, say, transport only.
 * Categories become admin-defined rather than a fixed list, and one template can
 * cover several grades at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('student_discounts');
        Schema::dropIfExists('student_installments');
        Schema::dropIfExists('student_fees');
        Schema::dropIfExists('installment_plan_rows');
        Schema::dropIfExists('installment_plans');
        Schema::dropIfExists('fee_templates');

        Schema::create('fee_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fee_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('fee_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('academic_year', 20);
            // One template can price several grades identically, e.g. books.
            // An empty list means every grade that year.
            $table->json('grade_levels')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('academic_year');
        });

        Schema::create('student_fees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_template_id')->nullable()->constrained()->nullOnDelete();
            // Name and category are snapshots so later edits never rewrite history.
            $table->string('name');
            $table->string('category');
            $table->string('academic_year', 20);
            $table->decimal('amount', 12, 2);
            $table->date('due_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status')->default('unpaid')->index();
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

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_fee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('student_discounts');
        Schema::dropIfExists('student_fees');
        Schema::dropIfExists('fee_templates');
        Schema::dropIfExists('fee_categories');
    }
};
