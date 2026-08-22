<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subject code names a subject inside its class, not across the whole school.
 *
 * Every grade teaches ENGLISH; insisting the code be unique school-wide meant
 * the second grade to be set up could not use the name the school actually uses
 * for it. The code is unique within a class instead, which is what a school
 * means by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique('courses_code_unique');
            $table->unique(['class_section_id', 'code'], 'courses_class_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique('courses_class_code_unique');
            $table->unique('code', 'courses_code_unique');
        });
    }
};
