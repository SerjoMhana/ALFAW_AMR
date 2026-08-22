<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpa_scale_bands', function (Blueprint $table): void {
            $table->id();
            $table->string('letter', 5);
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->decimal('points', 3, 1);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('credit_legend_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('classes_per_week')->unique();
            $table->decimal('credits', 4, 2);
            $table->timestamps();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('periods_per_week')->nullable()->after('credit_hours');
        });

        $now = now();

        DB::table('gpa_scale_bands')->insert([
            ['letter' => 'A+', 'min_score' => 98, 'max_score' => 100, 'points' => 4.0, 'display_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'A', 'min_score' => 93, 'max_score' => 97, 'points' => 4.0, 'display_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'A-', 'min_score' => 90, 'max_score' => 92, 'points' => 3.7, 'display_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'B+', 'min_score' => 87, 'max_score' => 89, 'points' => 3.3, 'display_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'B', 'min_score' => 83, 'max_score' => 86, 'points' => 3.0, 'display_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'B-', 'min_score' => 80, 'max_score' => 82, 'points' => 2.7, 'display_order' => 6, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'C+', 'min_score' => 77, 'max_score' => 79, 'points' => 2.3, 'display_order' => 7, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'C', 'min_score' => 73, 'max_score' => 76, 'points' => 2.0, 'display_order' => 8, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'C-', 'min_score' => 70, 'max_score' => 72, 'points' => 1.7, 'display_order' => 9, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'D', 'min_score' => 65, 'max_score' => 69, 'points' => 1.3, 'display_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['letter' => 'F', 'min_score' => 0, 'max_score' => 64, 'points' => 0.0, 'display_order' => 11, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('credit_legend_rows')->insert([
            ['classes_per_week' => 1, 'credits' => 0.2, 'created_at' => $now, 'updated_at' => $now],
            ['classes_per_week' => 2, 'credits' => 0.3, 'created_at' => $now, 'updated_at' => $now],
            ['classes_per_week' => 3, 'credits' => 0.5, 'created_at' => $now, 'updated_at' => $now],
            ['classes_per_week' => 4, 'credits' => 0.7, 'created_at' => $now, 'updated_at' => $now],
            ['classes_per_week' => 5, 'credits' => 0.8, 'created_at' => $now, 'updated_at' => $now],
            ['classes_per_week' => 6, 'credits' => 1.0, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('periods_per_week');
        });
        Schema::dropIfExists('credit_legend_rows');
        Schema::dropIfExists('gpa_scale_bands');
    }
};
