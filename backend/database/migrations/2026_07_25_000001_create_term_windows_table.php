<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('academic_year', 20);
            $table->string('term');
            $table->boolean('is_open')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_year', 'term'], 'term_windows_unique_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_windows');
    }
};
