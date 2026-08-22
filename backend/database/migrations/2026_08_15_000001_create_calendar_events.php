<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school calendar: activities, holidays, exams and anything else the school
 * wants everyone to know about.
 *
 * One table serves everybody — the office writes to it, parents, students and
 * teachers read from it — because a school has one calendar, not four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();

            // What kind of day it is: activity | holiday | exam | meeting | other.
            $table->string('kind')->default('activity');
            // Chosen by whoever adds it, so the month reads at a glance.
            $table->string('color', 7)->default('#465fff');

            $table->date('starts_on');
            // Equal to starts_on for a single day; later for a week of exams.
            $table->date('ends_on');
            $table->boolean('all_day')->default(true);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            $table->string('location')->nullable();
            $table->string('academic_year')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A month view asks for everything overlapping a range, which is a
            // scan of these two columns.
            $table->index(['starts_on', 'ends_on']);
            $table->index('academic_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
