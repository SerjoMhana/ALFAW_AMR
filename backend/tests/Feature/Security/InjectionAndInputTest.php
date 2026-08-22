<?php

namespace Tests\Feature\Security;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hostile input must be treated as data everywhere it lands: search boxes,
 * filters, login fields and stored names.
 */
class InjectionAndInputTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function injectionPayloads(): array
    {
        return [
            'drop table' => ["'; DROP TABLE student_profiles; --"],
            'always true' => ["' OR '1'='1"],
            'union select' => ["' UNION SELECT id, password FROM users --"],
            'comment out' => ['admin"--'],
            'stacked update' => ["'; UPDATE users SET user_type='admin' WHERE id=1; --"],
            'boolean blind' => ["' AND (SELECT COUNT(*) FROM users) > 0 --"],
            'null byte' => ["test\0' OR 1=1 --"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('injectionPayloads')]
    public function test_search_treats_injection_payloads_as_plain_text(string $payload): void
    {
        $admin = $this->admin();
        $this->student('Ali Hassan', 'S-1');

        $response = $this->withUser($admin)
            ->getJson('/api/students?search='.urlencode($payload))
            ->assertOk();

        // The payload matched nothing rather than everything, and the table lives.
        $this->assertSame([], $response->json('data'));
        $this->assertTrue(Schema::hasTable('student_profiles'));
        $this->assertSame(1, StudentProfile::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('injectionPayloads')]
    public function test_login_treats_injection_payloads_as_plain_text(string $payload): void
    {
        $this->admin();

        $this->postJson('/api/login', ['email' => $payload, 'password' => $payload])
            ->assertStatus(422);

        $this->assertSame(1, User::count(), 'the injection must not have created or removed accounts');
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_filters_treat_injection_payloads_as_plain_text(): void
    {
        $admin = $this->admin();
        $this->student('Ali Hassan', 'S-1');

        foreach (['grade', 'gender', 'status', 'academic_year'] as $filter) {
            $this->withUser($admin)
                ->getJson('/api/students?'.$filter."=' OR 1=1 --")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }

        $this->assertSame(1, StudentProfile::count());
    }

    /**
     * A payload that survives storage must come back as the text that went in,
     * not as something the database interpreted.
     */
    public function test_a_stored_payload_round_trips_untouched(): void
    {
        $admin = $this->admin();
        $payload = "Robert'); DROP TABLE students;--";

        $id = $this->withUser($admin)->postJson('/api/students', [
            'admission_no' => 'A1001',
            'admission_date' => '2025-09-01',
            'first_name' => $payload,
            'last_name' => 'Tables',
            'date_of_birth' => '2010-01-01',
            'gender' => 'male',
            'course' => 'G1',
            'academic_year' => '2025-2026',
            'parent' => [
                'first_name' => 'Parent',
                'last_name' => 'Tables',
                'relation' => 'father',
            ],
        ])->assertCreated()->json('data.id');

        $this->assertSame($payload, StudentProfile::findOrFail($id)->first_name);
        $this->assertTrue(Schema::hasTable('student_profiles'));
    }

    /**
     * The query builder is the thing doing the protecting, so assert directly
     * that it binds rather than interpolates.
     */
    public function test_the_query_builder_binds_rather_than_interpolates(): void
    {
        $query = StudentProfile::query()->where('first_name', "' OR '1'='1");

        $this->assertStringNotContainsString("OR '1'='1", $query->toSql());
        $this->assertContains("' OR '1'='1", $query->getBindings());
    }

    public function test_no_raw_sql_fragment_interpolates_a_variable(): void
    {
        $offenders = [];
        $root = base_path('app');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            // A raw fragment containing a PHP variable or interpolation is the
            // shape that lets user input reach SQL unbound.
            if (preg_match('/(whereRaw|orderByRaw|selectRaw|havingRaw|groupByRaw|DB::raw)\s*\(\s*["\'][^"\']*\$/', $source)) {
                $offenders[] = str_replace($root, 'app', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Raw SQL built with a variable: '.implode(', ', $offenders));
    }

    public function test_an_oversized_login_payload_is_refused(): void
    {
        $this->postJson('/api/login', ['email' => str_repeat('a', 5000), 'password' => 'x'])
            ->assertStatus(422);
    }

    private function student(string $name, string $number): StudentProfile
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($number).'@example.com',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'full_name' => $name,
            'first_name' => $name,
            'grade_level' => 'G1',
            'academic_year' => '2025-2026',
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
