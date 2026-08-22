<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The nightly backup.
 *
 * A backup nobody has opened is a file, not a backup — so these check what
 * comes out of the run, not merely that it exited zero.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/backups');
        File::deleteDirectory($this->folder);

        $this->uploads = storage_path('framework/testing/uploads');

        config([
            'backup.path' => $this->folder,
            'backup.files.include' => [$this->uploads],
            'backup.files.exclude' => [$this->folder],
            'backup.keep_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        File::deleteDirectory($this->uploads);

        parent::tearDown();
    }

    public function test_a_run_writes_one_archive(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertCount(1, File::glob($this->folder.'/vis-*.zip'));
    }

    public function test_the_archive_holds_a_readable_dump_of_the_data(): void
    {
        CourseSection::create([
            'section_code' => 'G12-A',
            'class_name' => 'صف الاختبار',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        $this->artisan('backup:run')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open(File::glob($this->folder.'/vis-*.zip')[0]);

        $entry = collect(range(0, $zip->numFiles - 1))
            ->map(fn (int $i) => $zip->getNameIndex($i))
            ->first(fn (string $name) => str_ends_with($name, 'database.sql'));

        $this->assertNotNull($entry, 'The archive holds no dump.');

        $dump = (string) $zip->getFromName($entry);
        $zip->close();

        // The school's own rows, and its Arabic, come back out intact.
        $this->assertStringContainsString('course_sections', $dump);
        $this->assertStringContainsString('صف الاختبار', $dump);
    }

    public function test_uploaded_files_travel_with_the_database(): void
    {
        File::ensureDirectoryExists($this->uploads);
        File::put($this->uploads.'/worksheet.pdf', '%PDF-1.4 test');

        $this->artisan('backup:run')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open(File::glob($this->folder.'/vis-*.zip')[0]);

        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i));
        $zip->close();

        $this->assertTrue(
            $names->contains(fn (string $name) => str_contains($name, 'worksheet.pdf')),
            'The uploaded file is missing from the archive.',
        );
    }

    /**
     * Otherwise each night's archive swallows the last, and a month of backups
     * becomes one enormous file.
     */
    public function test_previous_archives_are_not_packed_into_the_new_one(): void
    {
        config(['backup.files.include' => [$this->folder]]);

        $this->artisan('backup:run')->assertSuccessful();
        $first = File::glob($this->folder.'/vis-*.zip')[0];

        $this->artisan('backup:run')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open(collect(File::glob($this->folder.'/vis-*.zip'))->last());
        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i));
        $zip->close();

        $this->assertFalse(
            $names->contains(fn (string $name) => str_contains($name, basename($first))),
            'The new archive contains the previous one.',
        );
    }

    public function test_archives_older_than_the_window_are_removed(): void
    {
        File::ensureDirectoryExists($this->folder);

        $stale = $this->folder.'/vis-2020-01-01-000000.zip';
        File::put($stale, 'old');
        touch($stale, now()->subDays(45)->getTimestamp());

        $recent = $this->folder.'/vis-2026-08-01-000000.zip';
        File::put($recent, 'recent');
        touch($recent, now()->subDays(3)->getTimestamp());

        $this->artisan('backup:run --keep=30')->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($recent);
    }

    public function test_a_quiet_run_says_nothing_when_it_worked(): void
    {
        $this->artisan('backup:run --quiet-success')
            ->doesntExpectOutputToContain('Backed up to')
            ->assertSuccessful();
    }

    public function test_the_schedule_carries_the_nightly_run(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => $event->command.' @ '.$event->expression);

        $this->assertTrue(
            $events->contains(fn (string $line) => str_contains($line, 'backup:run')),
            'The backup is not scheduled.',
        );
    }
}
