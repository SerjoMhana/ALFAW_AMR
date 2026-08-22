<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the rows made while the integration was switched off.
 *
 * The stand-in hands back invented course ids, and once the school connects
 * Google for real those ids point at nothing. Without this flag the page would
 * show such a subject as already linked and hide the button that creates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_classroom_links', function (Blueprint $table): void {
            $table->boolean('simulated')->default(false)->after('google_course_id');
        });

        Schema::table('google_classroom_members', function (Blueprint $table): void {
            $table->boolean('simulated')->default(false)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('google_classroom_links', function (Blueprint $table): void {
            $table->dropColumn('simulated');
        });

        Schema::table('google_classroom_members', function (Blueprint $table): void {
            $table->dropColumn('simulated');
        });
    }
};
