<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before the rule was enforced on every write path, a database could end up
 * with several years flagged active at once — which made "the active year"
 * ambiguous for every screen that reads it. Keep the newest and stand the rest
 * down; a database with no active year at all gets its newest year promoted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keep = DB::table('academic_years')
            ->where('is_active', true)
            ->orderByDesc('name')
            ->value('id')
            ?? DB::table('academic_years')->orderByDesc('name')->value('id');

        if ($keep === null) {
            return;
        }

        DB::table('academic_years')->where('id', '!=', $keep)->update(['is_active' => false]);
        DB::table('academic_years')->where('id', $keep)->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Nothing to undo: the previous state was simply inconsistent.
    }
};
