<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every subject is examined.
 *
 * A school teaches things it never sits a paper in — an activity period, a
 * reading hour — and those have no place on a report card built out of marks.
 * Flagging the subject keeps it in the timetable and in its classroom while
 * leaving it off the sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            // Existing subjects are examined: that is what they have been doing.
            $table->boolean('has_exam')->default(true)->after('is_ap');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('has_exam');
        });
    }
};
