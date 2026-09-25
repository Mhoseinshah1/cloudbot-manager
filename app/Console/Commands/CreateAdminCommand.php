<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first owner account.
 *
 * Deliberately refuses to touch an account that already exists. Overwriting a
 * password or silently granting a role from a script is how an installer
 * re-run turns into a privilege escalation, so a clash stops here and the
 * operator decides what to do.
 *
 * The account is created without a second factor. Enrolment happens on first
 * sign-in, where the secret is generated server-side and shown once in the
 * browser, rather than passing through a terminal, shell history or CI log.
 *
 * Two options exist for the installer, and only for it:
 *
 *   --if-missing        makes a re-run a no-op when an owner already exists,
 *                       so installing twice is not an error. It still never
 *                       modifies the existing account.
 *   --password-from-env reads the password from CLOUDBOT_ADMIN_PASSWORD. There
 *                       is deliberately no --password option: an argument is
 *                       visible in the process list and in shell history,
 *                       which a password must never be.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'app:create-admin
                            {--name= : The administrator\'s name}
                            {--email= : The email address used to sign in}
                            {--password-from-env : Read the password from CLOUDBOT_ADMIN_PASSWORD instead of prompting}
                            {--if-missing : Succeed without doing anything when an owner account already exists}';

    protected $description = 'Create a privileged owner account.';

    /**
     * Where `--password-from-env` reads from.
     *
     * An environment variable rather than an argument: the installer passes it
     * into the container's environment, where it is not visible in the host's
     * process list.
     */
    private const PASSWORD_ENV = 'CLOUDBOT_ADMIN_PASSWORD';

    public function handle(RoleProvisioner $provisioner, AuditRecorder $audit): int
    {
        // An installer that runs twice must not fail the second time, and must
        // not touch the account the first run created.
        if ($this->option('if-missing') && $this->ownerExists()) {
            $this->info('An owner account already exists. Leaving it unchanged.');

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: text(
            label: 'Name',
            required: true,
        );

        $email = $this->option('email') ?: text(
            label: 'Email address',
            required: true,
        );

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $email));

        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with that email already exists. Refusing to modify it.');
            $this->line('Use a different address, or change the existing account deliberately.');

            return self::FAILURE;
        }

        if ($this->option('password-from-env')) {
            $secret = (string) getenv(self::PASSWORD_ENV);

            if ($secret === '') {
                $this->error(self::PASSWORD_ENV.' is empty or unset.');

                return self::FAILURE;
            }
        } else {
            // Hidden input, never echoed and never passed as an argument, so it
            // cannot end up in shell history or a process list.
            $secret = password(label: 'Password', required: true);
            $confirmation = password(label: 'Confirm password', required: true);

            if (! hash_equals($secret, $confirmation)) {
                $this->error('The passwords did not match.');

                return self::FAILURE;
            }
        }

        $passwordValidator = Validator::make(
            ['password' => $secret],
            ['password' => ['required', 'string', 'min:12']],
        );

        if ($passwordValidator->fails()) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $secret, $provisioner, $audit): User {
            // Roles must exist before one can be assigned; this is idempotent.
            $provisioner->sync();

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                // Hashed by the model's cast, never stored or logged in clear.
                'password' => $secret,
                'status' => UserStatus::Active,
                'created_via' => UserCreatedVia::Admin,
                'locale' => config('cloudbot.defaults.locale'),
                'timezone' => config('cloudbot.defaults.timezone'),
            ]);

            $user->assignRole(AdminRole::Owner->value);

            $audit->recordFromConsole(
                AuditEvent::AdminCreated,
                subject: $user,
                // Names the account, never the credential.
                metadata: ['email' => $email, 'role' => AdminRole::Owner->value],
            );

            return $user;
        });

        $this->info(sprintf('Created owner account for %s.', $user->email));
        $this->line('Sign in at /admin. You will be asked to set up two-factor authentication before anything else.');

        return self::SUCCESS;
    }

    /** Whether anybody already holds the owner role. */
    private function ownerExists(): bool
    {
        return User::query()->role(AdminRole::Owner->value)->exists();
    }
}
