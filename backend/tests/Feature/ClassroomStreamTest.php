<?php

namespace Tests\Feature;

use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostComment;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The in-house classroom: a wall per subject, its files, and who may see them.
 *
 * The paging tests use a stream big enough to prove the cursor actually walks —
 * a school accumulates thousands of these, and page one must not get slower as
 * page two hundred appears behind it.
 */
class ClassroomStreamTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $otherTeacher;

    private User $student;

    private User $outsider;

    private CourseSection $section;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['classroom.disk' => 'local']);

        $this->teacher = $this->user('teacher', 'teacher@school.test');
        $this->otherTeacher = $this->user('teacher', 'other@school.test');

        $this->section = CourseSection::create([
            'section_code' => 'G12-A',
            'class_name' => 'G12',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
            'teacher_id' => $this->teacher->id,
        ]);

        $this->course = Course::create([
            'code' => 'MTH',
            'name' => 'Mathematics',
            'grade_level' => 'G12',
            'class_section_id' => $this->section->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $this->student = $this->enrolledStudent('Ali', 'S-1');
        $this->outsider = $this->user('student', 'outsider@school.test');
    }

    // ---- who sees what ------------------------------------------------------

    public function test_a_teacher_sees_the_subjects_they_teach(): void
    {
        $body = $this->withUser($this->teacher)->getJson('/api/classroom/courses')->assertOk()->json('data');

        $this->assertCount(1, $body);
        $this->assertSame('Mathematics', $body[0]['name']);
        $this->assertTrue($body[0]['can_post']);
    }

    public function test_a_teacher_does_not_see_another_teachers_subject(): void
    {
        $this->withUser($this->otherTeacher)
            ->getJson('/api/classroom/courses')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withUser($this->otherTeacher)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertForbidden();
    }

    public function test_a_student_sees_the_subjects_of_their_class_but_cannot_post(): void
    {
        $body = $this->withUser($this->student)->getJson('/api/classroom/courses')->assertOk()->json('data');

        $this->assertCount(1, $body);
        $this->assertFalse($body[0]['can_post']);

        $this->withUser($this->student)
            ->postJson("/api/classroom/courses/{$this->course->id}/posts", [
                'type' => 'announcement',
                'body' => 'مرحباً',
            ])
            ->assertForbidden();
    }

    public function test_a_student_from_another_class_is_kept_out(): void
    {
        $this->withUser($this->outsider)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertForbidden();
    }

    public function test_an_admin_sees_every_subject(): void
    {
        $admin = $this->user('admin', 'admin@school.test');

        $this->withUser($admin)->getJson('/api/classroom/courses')->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- posting ------------------------------------------------------------

    public function test_the_teacher_posts_to_their_class(): void
    {
        $this->withUser($this->teacher)
            ->postJson("/api/classroom/courses/{$this->course->id}/posts", [
                'type' => 'announcement',
                'title' => 'اختبار الأسبوع القادم',
                'body' => 'راجعوا الفصل الثالث.',
            ])
            ->assertCreated();

        $post = ClassPost::firstOrFail();
        $this->assertNotNull($post->published_at);
        // Copied from the class, so a year can be filtered without a join.
        $this->assertSame('2026-2027', $post->academic_year);
    }

    public function test_an_empty_post_is_refused(): void
    {
        $this->withUser($this->teacher)
            ->postJson("/api/classroom/courses/{$this->course->id}/posts", ['type' => 'announcement'])
            ->assertStatus(422);
    }

    public function test_a_draft_is_hidden_from_the_class_until_published(): void
    {
        $post = $this->announce(publish: false);

        $this->withUser($this->student)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertOk()
            ->assertJsonCount(0, 'data.posts');

        // Its author still sees it, marked as unpublished.
        $this->withUser($this->teacher)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertOk()
            ->assertJsonCount(1, 'data.posts')
            ->assertJsonPath('data.posts.0.is_published', false);

        $this->withUser($this->teacher)->postJson("/api/classroom/posts/{$post->id}/publish")->assertOk();

        $this->withUser($this->student)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertOk()
            ->assertJsonCount(1, 'data.posts');
    }

    public function test_a_student_cannot_delete_the_teachers_post(): void
    {
        $post = $this->announce();

        $this->withUser($this->student)->deleteJson("/api/classroom/posts/{$post->id}")->assertForbidden();
        $this->assertDatabaseCount('class_posts', 1);
    }

    public function test_the_teacher_deletes_their_own_post(): void
    {
        $post = $this->announce();

        $this->withUser($this->teacher)->deleteJson("/api/classroom/posts/{$post->id}")->assertOk();
        $this->assertSoftDeleted('class_posts', ['id' => $post->id]);
    }

    // ---- paging a big stream -------------------------------------------------

    public function test_the_stream_is_walked_by_cursor_without_repeating_a_post(): void
    {
        $this->seedPosts(55);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $body = $this->withUser($this->student)
                ->getJson("/api/classroom/courses/{$this->course->id}/stream".($cursor ? "?before={$cursor}" : ''))
                ->assertOk()
                ->json('data');

            $ids = array_column($body['posts'], 'id');
            $this->assertLessThanOrEqual(20, count($ids));

            $seen = array_merge($seen, $ids);
            $cursor = $body['next_cursor'];
            $pages++;
        } while ($cursor && $pages < 10);

        $this->assertCount(55, $seen);
        $this->assertCount(55, array_unique($seen));
        // Newest first, all the way down.
        $this->assertSame($seen, array_reverse(range(min($seen), max($seen))));
    }

    public function test_a_pinned_post_leads_the_first_page_and_is_not_repeated_below(): void
    {
        $this->seedPosts(30);
        $old = ClassPost::orderBy('id')->first();

        $this->withUser($this->teacher)->postJson("/api/classroom/posts/{$old->id}/pin")->assertOk();

        $first = $this->withUser($this->student)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertOk()
            ->json('data');

        $this->assertSame($old->id, $first['posts'][0]['id']);
        $this->assertTrue($first['posts'][0]['is_pinned']);
        // It leads the page instead of one of the twenty, not as well as.
        $this->assertCount(21, $first['posts']);

        $rest = $this->withUser($this->student)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream?before={$first['next_cursor']}")
            ->assertOk()
            ->json('data.posts');

        $this->assertNotContains($old->id, array_column($rest, 'id'));
    }

    // ---- files ---------------------------------------------------------------

    public function test_a_pdf_is_attached_and_stored_off_the_public_path(): void
    {
        $post = $this->announce(publish: false);

        $this->withUser($this->teacher)
            ->post("/api/classroom/posts/{$post->id}/attachments", [
                'file' => UploadedFile::fake()->create('worksheet.pdf', 400, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'worksheet.pdf')
            ->assertJsonPath('data.is_pdf', true);

        $attachment = ClassPostAttachment::firstOrFail();

        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith("classroom/{$this->course->id}/2026-2027/", $attachment->path);
        // The uploader does not get to choose the stored name.
        $this->assertStringNotContainsString('worksheet', $attachment->path);
        $this->assertSame(1, $post->fresh()->attachments_count);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        config(['classroom.uploads.max_size' => 100]);
        $post = $this->announce();

        $this->withUser($this->teacher)
            ->post("/api/classroom/posts/{$post->id}/attachments", [
                'file' => UploadedFile::fake()->create('huge.pdf', 500, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, ClassPostAttachment::count());
    }

    public function test_an_executable_is_refused(): void
    {
        $post = $this->announce();

        $this->withUser($this->teacher)
            ->post("/api/classroom/posts/{$post->id}/attachments", [
                'file' => UploadedFile::fake()->create('nasty.exe', 10),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_the_per_post_file_limit_holds(): void
    {
        config(['classroom.uploads.max_per_post' => 2]);
        $post = $this->announce();

        foreach (range(1, 2) as $i) {
            $this->withUser($this->teacher)
                ->post("/api/classroom/posts/{$post->id}/attachments", [
                    'file' => UploadedFile::fake()->create("f{$i}.pdf", 10, 'application/pdf'),
                ])
                ->assertCreated();
        }

        $this->withUser($this->teacher)
            ->post("/api/classroom/posts/{$post->id}/attachments", [
                'file' => UploadedFile::fake()->create('f3.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_a_class_member_downloads_the_file_and_an_outsider_cannot(): void
    {
        $post = $this->announce();
        $attachment = $this->attach($post);

        $this->withUser($this->student)
            ->get("/api/classroom/attachments/{$attachment->id}")
            ->assertOk()
            ->assertDownload('worksheet.pdf');

        $this->withUser($this->outsider)
            ->get("/api/classroom/attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_a_draft_file_is_not_downloadable_by_the_class(): void
    {
        $post = $this->announce(publish: false);
        $attachment = $this->attach($post);

        $this->withUser($this->student)->get("/api/classroom/attachments/{$attachment->id}")->assertForbidden();
        $this->withUser($this->teacher)->get("/api/classroom/attachments/{$attachment->id}")->assertOk();
    }

    public function test_deleting_an_attachment_removes_the_stored_file(): void
    {
        $post = $this->announce();
        $attachment = $this->attach($post);
        $path = $attachment->path;

        $this->withUser($this->teacher)->deleteJson("/api/classroom/attachments/{$attachment->id}")->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $post->fresh()->attachments_count);
    }

    // ---- comments -------------------------------------------------------------

    public function test_a_student_comments_and_the_count_follows(): void
    {
        $post = $this->announce();

        $this->withUser($this->student)
            ->postJson("/api/classroom/posts/{$post->id}/comments", ['body' => 'شكراً أستاذ'])
            ->assertCreated();

        $this->assertSame(1, $post->fresh()->comments_count);

        $this->withUser($this->student)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertOk()
            ->assertJsonPath('data.posts.0.comments_count', 1)
            ->assertJsonPath('data.posts.0.comments.0.body', 'شكراً أستاذ');
    }

    public function test_only_the_last_few_comments_ride_along_with_the_stream(): void
    {
        $post = $this->announce();

        foreach (range(1, 12) as $i) {
            ClassPostComment::create(['class_post_id' => $post->id, 'user_id' => $this->student->id, 'body' => "c{$i}"]);
        }
        $post->forceFill(['comments_count' => 12])->save();

        $body = $this->withUser($this->student)
            ->getJson("/api/classroom/courses/{$this->course->id}/stream")
            ->assertOk()
            ->json('data.posts.0');

        $this->assertCount(3, $body['comments']);
        $this->assertSame(12, $body['comments_count']);
        // The newest three, in reading order.
        $this->assertSame(['c10', 'c11', 'c12'], array_column($body['comments'], 'body'));
    }

    public function test_the_whole_thread_is_fetched_on_demand(): void
    {
        $post = $this->announce();

        foreach (range(1, 12) as $i) {
            ClassPostComment::create(['class_post_id' => $post->id, 'user_id' => $this->student->id, 'body' => "c{$i}"]);
        }

        $this->withUser($this->student)
            ->getJson("/api/classroom/posts/{$post->id}/comments")
            ->assertOk()
            ->assertJsonCount(12, 'data');
    }

    public function test_a_student_deletes_their_own_comment_but_not_another_students(): void
    {
        $post = $this->announce();
        $mine = ClassPostComment::create(['class_post_id' => $post->id, 'user_id' => $this->student->id, 'body' => 'mine']);
        $theirs = ClassPostComment::create(['class_post_id' => $post->id, 'user_id' => $this->teacher->id, 'body' => 'theirs']);
        $post->forceFill(['comments_count' => 2])->save();

        $this->withUser($this->student)->deleteJson("/api/classroom/comments/{$mine->id}")->assertOk();
        $this->withUser($this->student)->deleteJson("/api/classroom/comments/{$theirs->id}")->assertForbidden();

        $this->assertSame(1, $post->fresh()->comments_count);
    }

    public function test_the_teacher_can_remove_any_comment_in_their_class(): void
    {
        $post = $this->announce();
        $comment = ClassPostComment::create(['class_post_id' => $post->id, 'user_id' => $this->student->id, 'body' => 'oops']);

        $this->withUser($this->teacher)->deleteJson("/api/classroom/comments/{$comment->id}")->assertOk();
    }

    public function test_comments_can_be_switched_off_for_a_post(): void
    {
        $post = $this->announce();
        $post->update(['comments_enabled' => false]);

        $this->withUser($this->student)
            ->postJson("/api/classroom/posts/{$post->id}/comments", ['body' => 'hi'])
            ->assertForbidden();
    }

    // ---- unread ---------------------------------------------------------------

    public function test_unread_counts_down_as_the_class_reads(): void
    {
        $this->seedPosts(5);

        $this->assertSame(5, $this->withUser($this->student)
            ->getJson('/api/classroom/courses')->json('data.0.unread'));

        $this->withUser($this->student)->postJson("/api/classroom/courses/{$this->course->id}/seen")->assertOk();

        $this->assertSame(0, $this->withUser($this->student)
            ->getJson('/api/classroom/courses')->json('data.0.unread'));

        $this->announce();

        $this->assertSame(1, $this->withUser($this->student)
            ->getJson('/api/classroom/courses')->json('data.0.unread'));
    }

    public function test_a_draft_is_not_counted_as_unread(): void
    {
        $this->announce(publish: false);

        $this->assertSame(0, $this->withUser($this->student)
            ->getJson('/api/classroom/courses')->json('data.0.unread'));
    }

    // ---- fixtures -------------------------------------------------------------

    private function announce(bool $publish = true): ClassPost
    {
        return ClassPost::create([
            'course_id' => $this->course->id,
            'author_id' => $this->teacher->id,
            'type' => ClassPost::TYPE_ANNOUNCEMENT,
            'body' => 'إعلان',
            'published_at' => $publish ? now() : null,
            'academic_year' => '2026-2027',
        ]);
    }

    private function seedPosts(int $count): void
    {
        foreach (range(1, $count) as $i) {
            ClassPost::create([
                'course_id' => $this->course->id,
                'author_id' => $this->teacher->id,
                'type' => ClassPost::TYPE_ANNOUNCEMENT,
                'body' => "منشور {$i}",
                'published_at' => now(),
                'academic_year' => '2026-2027',
            ]);
        }
    }

    private function attach(ClassPost $post): ClassPostAttachment
    {
        $this->withUser($this->teacher)
            ->post("/api/classroom/posts/{$post->id}/attachments", [
                'file' => UploadedFile::fake()->create('worksheet.pdf', 20, 'application/pdf'),
            ])
            ->assertCreated();

        return ClassPostAttachment::where('class_post_id', $post->id)->latest('id')->firstOrFail();
    }

    private function enrolledStudent(string $name, string $number): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($number).'@school.test',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'admission_no' => $number,
            'full_name' => $name,
            'grade_level' => 'G12',
            'academic_year' => '2026-2027',
            'section_id' => $this->section->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $this->section->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function user(string $type, string $email): User
    {
        return User::create([
            'name' => ucfirst($type),
            'email' => $email,
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => $type,
            'is_active' => true,
        ]);
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
