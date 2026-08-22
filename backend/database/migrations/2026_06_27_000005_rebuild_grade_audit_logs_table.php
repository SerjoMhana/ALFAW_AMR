<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_score_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_score', 6, 2)->nullable();
            $table->decimal('new_score', 6, 2)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_audit_logs');
    }
};
