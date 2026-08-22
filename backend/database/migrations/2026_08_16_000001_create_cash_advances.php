<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash advances — العهد.
 *
 * The school hands someone money to spend on its behalf; they come back with
 * receipts and whatever is left. The advance stays open until it is accounted
 * for in full, which is the whole point of keeping it as a record rather than
 * as a note in a drawer.
 *
 * Closing is deliberately not a free-text amount: the returned or reimbursed
 * figure is worked out from what was actually spent, so an advance cannot be
 * signed off on a number nobody can justify.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advances', function (Blueprint $table): void {
            $table->id();
            $table->string('academic_year', 20);
            $table->unsignedInteger('advance_number');

            // The person holding the money. Linked to an account where there is
            // one, but a school also hands cash to people who have no login.
            $table->foreignId('holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('holder_name');

            $table->string('purpose');
            $table->decimal('amount', 12, 2);
            $table->string('method');
            $table->string('reference')->nullable();
            $table->date('issued_on');
            $table->text('notes')->nullable();

            // open | settled | cancelled
            $table->string('status')->default('open');

            // Exactly one of these carries a figure at settlement: money handed
            // back, or money the school owed and paid out.
            $table->decimal('returned_amount', 12, 2)->default(0);
            $table->decimal('reimbursed_amount', 12, 2)->default(0);

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            // Serials are unique per year; a clash fails the insert rather than
            // silently reusing a number.
            $table->unique(['academic_year', 'advance_number'], 'cash_advances_serial_unique');
            $table->index(['status', 'issued_on']);
            $table->index('holder_id');
        });

        Schema::create('cash_advance_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('spent_on');
            // The invoice or receipt number the paper trail hangs on.
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cash_advance_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_expenses');
        Schema::dropIfExists('cash_advances');
    }
};
