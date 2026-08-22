<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('min_grade');
            $table->unsignedTinyInteger('max_grade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_tiers');
    }
};
