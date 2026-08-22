<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->string('academic_year', 20);
            $table->string('type');
            $table->string('period');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['course_section_id', 'academic_year', 'period'],
                'report_card_publications_unique_entry',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_publications');
    }
};
