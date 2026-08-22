<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A grading scheme is now something the admin builds, points at specific
 * classes, and then switches on — rather than something that applies by grade
 * range the moment it exists. A class works with exactly one scheme, which is
 * why the link lives on the class row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_tiers', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false)->after('max_grade');
        });

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->foreignId('grade_tier_id')->nullable()->after('class_name')->constrained()->nullOnDelete();
        });

        // Schemes that predate the switch are already in use, so leave them on.
        DB::table('grade_tiers')->update(['is_active' => true]);

        // Point every class at the scheme its grade level already resolved to,
        // so nothing changes for classes that are mid-year.
        foreach (DB::table('course_sections')->get() as $section) {
            if (! preg_match('/(\d{1,2})/', (string) ($section->class_name ?: $section->section_code), $matches)) {
                continue;
            }

            $tierId = DB::table('grade_tiers')
                ->where('min_grade', '<=', (int) $matches[1])
                ->where('max_grade', '>=', (int) $matches[1])
                ->orderBy('min_grade')
                ->value('id');

            if ($tierId) {
                DB::table('course_sections')->where('id', $section->id)->update(['grade_tier_id' => $tierId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['grade_tier_id']);
            $table->dropColumn('grade_tier_id');
        });

        Schema::table('grade_tiers', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
