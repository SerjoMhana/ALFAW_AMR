<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The classroom stream: one wall of posts per subject.
 *
 * Shaped for a school that will accumulate thousands of posts and files a year,
 * so the list query never scans the whole table and never joins to count:
 * reading a page is one index range on (course_id, id) plus the attachments of
 * the twenty rows on that page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            // announcement = a note to the class; material = teaching material.
            $table->string('type')->default('announcement');
            $table->string('title')->nullable();
            $table->text('body')->nullable();

            // Null until published, so a teacher can prepare a post in advance.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->boolean('comments_enabled')->default(true);

            // Counters kept on the row: a stream page would otherwise need two
            // aggregate joins per post, which is what makes such lists crawl.
            $table->unsignedInteger('attachments_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);

            // Copied from the class so a year can be filtered or archived
            // without joining through courses and sections.
            $table->string('academic_year')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The stream itself: newest first within one subject.
            $table->index(['course_id', 'id']);
            // Pinned posts are pulled out first, so they get their own path.
            $table->index(['course_id', 'pinned_at']);
            $table->index(['academic_year', 'id']);
            $table->index(['author_id', 'id']);
        });

        Schema::create('class_post_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Where the bytes actually live. The path is never public: files are
            // served by a controller that checks who is asking.
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();

            $table->timestamps();

            $table->index(['class_post_id', 'id']);
        });

        Schema::create('class_post_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_post_id', 'id']);
        });

        /*
         * How far each person has read, one row per person per subject.
         *
         * The obvious design — a row per person per post — would be half a
         * million rows in the first year alone for one school. A high-water
         * mark answers "how many are new" with a single counted range.
         */
        Schema::create('class_post_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_seen_post_id')->default(0);
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_post_views');
        Schema::dropIfExists('class_post_comments');
        Schema::dropIfExists('class_post_attachments');
        Schema::dropIfExists('class_posts');
    }
};
