<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods become school-defined rather than a fixed list, so the
 * cashier's dropdown reflects however this school actually takes money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        collect(['نقداً', 'حوالة مصرفية', 'شيك'])
            ->each(fn (string $name, int $index) => \Illuminate\Support\Facades\DB::table('payment_methods')->insert([
                'name' => $name,
                'is_active' => true,
                'display_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
