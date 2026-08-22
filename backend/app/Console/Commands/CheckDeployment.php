<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The pre-flight check before the school goes online.
 *
 * Every item here is something that has actually gone wrong in this project or
 * would be silent until the day it mattered: debug output shown to strangers,
 * a session cookie scoped to localhost, an account still on the password the
 * seeder used to hand out.
 *
 * Run it on the server, after deploying and before letting anyone in.
 */
class CheckDeployment extends Command
{
    protected $signature = 'deploy:check {--strict : fail on warnings too}';

    protected $description = 'Check the settings and data that decide whether this install is safe to expose';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->line('');
        $this->components->info('Checking this install');

        $this->environment();
        $this->secrets();
        $this->database();
        $this->accounts();
        $this->storage();

        $this->line('');

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} problem(s) must be fixed before going online.");

            return self::FAILURE;
        }

        if ($this->warnings > 0 && $this->option('strict')) {
            $this->components->error("{$this->warnings} warning(s), and --strict was given.");

            return self::FAILURE;
        }

        $this->components->info($this->warnings > 0
            ? "Ready, with {$this->warnings} warning(s) worth reading."
            : 'Ready.');

        return self::SUCCESS;
    }

    private function environment(): void
    {
        $production = app()->isProduction();

        $this->check(
            'APP_DEBUG is off',
            ! config('app.debug'),
            'A stack trace shows file paths, queries and config values to whoever triggered the error.',
            fatal: $production,
        );

        $this->check(
            'APP_ENV is production',
            $production,
            'Set APP_ENV=production so the framework and this check know where they are.',
            fatal: false,
        );

        $url = (string) config('app.url');

        $this->check(
            'APP_URL is not localhost',
            $url !== '' && ! str_contains($url, 'localhost') && ! str_contains($url, '127.0.0.1'),
            "APP_URL is [{$url}]. Links in mail and PDFs point wherever this says.",
            fatal: $production,
        );

        $this->check(
            'APP_URL uses https',
            str_starts_with($url, 'https://'),
            'Sign-in details and student records travel in the clear without it.',
            fatal: $production,
        );
    }

    private function secrets(): void
    {
        $this->check(
            'APP_KEY is set',
            filled(config('app.key')),
            'Run php artisan key:generate. Sessions and encrypted values depend on it.',
            fatal: true,
        );

        $stateful = config('sanctum.stateful', []);
        $localOnly = collect($stateful)->every(
            fn ($domain) => str_contains($domain, 'localhost') || str_contains($domain, '127.0.0.1'),
        );

        $this->check(
            'SANCTUM_STATEFUL_DOMAINS names the real site',
            ! $localOnly,
            'Sign-in will fail from the deployed frontend while this lists only localhost.',
            fatal: app()->isProduction(),
        );

        $this->check(
            'The session cookie is https-only',
            (bool) config('session.secure'),
            'Set SESSION_SECURE_COOKIE=true so the cookie is never sent over plain http.',
            fatal: app()->isProduction(),
        );

        $this->check(
            'The session cookie is not scoped to localhost',
            config('session.domain') === null || ! str_contains((string) config('session.domain'), 'localhost'),
            'SESSION_DOMAIN still says localhost, so the browser will drop the cookie.',
            fatal: app()->isProduction(),
        );
    }

    private function database(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->check(
            'The database is not a SQLite file',
            $driver !== 'sqlite',
            'SQLite works, but its locking breaks on the network storage some hosts use.',
            fatal: false,
        );

        try {
            DB::connection()->getPdo();
            $this->report('The database answers');
        } catch (\Throwable $e) {
            $this->flag('The database answers', $e->getMessage(), fatal: true);
        }

        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
            ->keys()
            ->diff(app('migrator')->getRepository()->getRan())
            ->count();

        $this->check(
            'Every migration has run',
            $pending === 0,
            "{$pending} migration(s) still pending. Run php artisan migrate --force.",
            fatal: true,
        );
    }

    private function accounts(): void
    {
        $weak = User::query()->get(['id', 'email', 'password'])
            ->filter(fn (User $user) => Hash::check('password', $user->password ?? ''))
            ->pluck('email');

        $this->check(
            'No account uses the password "password"',
            $weak->isEmpty(),
            'Still weak: '.$weak->implode(', ').'. Fix with php artisan user:password <email>.',
            fatal: true,
        );

        $this->check(
            'At least one administrator exists',
            User::where('user_type', 'admin')->where('is_active', true)->exists(),
            'Nobody can administer the school.',
            fatal: true,
        );

        $samples = User::whereIn('email', [
            'staff@school.test', 'teacher@school.test', 'student@school.test',
        ])->pluck('email');

        $this->check(
            'The sample accounts are gone',
            $samples->isEmpty(),
            'Left over: '.$samples->implode(', '),
            fatal: false,
        );
    }

    private function storage(): void
    {
        $this->check(
            'Uploads are stored off the public path',
            ! str_contains((string) config('classroom.disk'), 'public'),
            'Class attachments would be reachable by anyone who guesses the URL.',
            fatal: true,
        );

        $this->check(
            'The storage directory is writable',
            is_writable(storage_path('app')) && is_writable(storage_path('logs')),
            'Uploads, PDFs and logs all need it.',
            fatal: true,
        );

        $this->check(
            'Logs rotate',
            config('logging.default') !== 'single'
                && (config('logging.channels.stack.channels') ?? []) !== ['single'],
            'A single log file grows without limit. Use the daily channel.',
            fatal: false,
        );
    }

    private function check(string $label, bool $ok, string $hint, bool $fatal): void
    {
        $ok ? $this->report($label) : $this->flag($label, $hint, $fatal);
    }

    private function report(string $label): void
    {
        $this->components->twoColumnDetail($label, '<fg=green>ok</>');
    }

    private function flag(string $label, string $hint, bool $fatal): void
    {
        $this->components->twoColumnDetail(
            $label,
            $fatal ? '<fg=red>must fix</>' : '<fg=yellow>warning</>',
        );
        $this->line("     <fg=gray>{$hint}</>");

        $fatal ? $this->failures++ : $this->warnings++;
    }
}
