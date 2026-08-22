<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('parent_nationality')->nullable()->after('parent_relation');
            $table->string('second_parent_full_name')->nullable()->after('parent_mobile_phone');
            $table->string('second_parent_relation')->nullable()->after('second_parent_full_name');
            $table->string('second_parent_nationality')->nullable()->after('second_parent_relation');
            $table->string('second_parent_phone')->nullable()->after('second_parent_nationality');
            $table->string('second_parent_email')->nullable()->after('second_parent_phone');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'parent_nationality',
                'second_parent_full_name',
                'second_parent_relation',
                'second_parent_nationality',
                'second_parent_phone',
                'second_parent_email',
            ]);
        });
    }
};
