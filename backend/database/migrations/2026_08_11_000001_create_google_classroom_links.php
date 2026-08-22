<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a subject in this system to one course in Google Classroom.
 *
 * The school's own accounts are separate from Google's: a student signs in here
 * with whatever we gave them, and into Classroom with their Workspace address.
 * google_email is that second address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_email')->nullable()->after('email');
        });

        Schema::create('google_classroom_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            // Our own key, so a course is found again without trusting a stored
            // id — this is what makes "create if missing" safe to re-run.
            $table->string('alias')->unique();
            $table->string('google_course_id')->nullable();

            $table->string('name');
            $table->string('section')->nullable();
            $table->string('state')->default('PENDING');
            $table->string('enrollment_code')->nullable();
            $table->string('alternate_link')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('google_classroom_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('google_classroom_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role');            // teacher | student
            $table->string('state')->default('PENDING'); // PENDING | ACTIVE | INVITED | FAILED
            $table->string('google_email');
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // One row per person per course, whichever way they were added.
            $table->unique(['google_classroom_link_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_classroom_members');
        Schema::dropIfExists('google_classroom_links');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('google_email');
        });
    }
};
