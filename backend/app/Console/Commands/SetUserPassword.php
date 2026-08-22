<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password as promptPassword;

/**
 * Sets a password from the terminal.
 *
 * The school has no password-reset email yet, so someone locked out needs a
 * person with server access to let them back in. Typing it here keeps it off
 * the screen, out of the shell history and out of any chat window — which is
 * where passwords tend to end up when the only alternative is telling someone.
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:password
        {email : the account to set a password for}
        {--generate : make a strong one and print it instead of asking}';

    protected $description = 'Set a password for one account';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))
            ->orWhere('username', $this->argument('email'))
            ->first();

        if (! $user) {
            $this->error("No account found for [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $this->line("Account: {$user->name} <{$user->email}> ({$user->user_type})");

        if ($this->option('generate')) {
            $plain = Str::password(16);
            $this->save($user, $plain);

            $this->newLine();
            $this->warn('Password (shown once): '.$plain);
            $this->line('Hand it over in person and have them change it.');

            return self::SUCCESS;
        }

        // Hidden as it is typed, and asked twice — a typo here locks someone out.
        $plain = promptPassword(
            label: 'New password',
            required: true,
            validate: fn (string $value) => $this->firstError($value),
        );

        if (promptPassword(label: 'Type it again') !== $plain) {
            $this->error('The two entries did not match. Nothing was changed.');

            return self::FAILURE;
        }

        if (! confirm("Set this as the password for {$user->email}?")) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->save($user, $plain);
        $this->info('Done. The account signs in with the new password from now on.');

        return self::SUCCESS;
    }

    private function save(User $user, string $plain): void
    {
        $user->forceFill(['password' => Hash::make($plain)])->save();

        // Every token and session is a way in that predates the change.
        $user->tokens()->delete();
    }

    private function firstError(string $value): ?string
    {
        $validator = Validator::make(
            ['password' => $value],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        return $validator->fails() ? $validator->errors()->first('password') : null;
    }
}
