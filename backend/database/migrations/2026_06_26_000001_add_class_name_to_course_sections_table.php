<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->string('class_name')->nullable()->after('section_code');
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropColumn('class_name');
        });
    }
};
