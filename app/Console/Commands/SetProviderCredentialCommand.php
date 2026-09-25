<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\ProviderManager;
use App\Models\Provider;
use App\Models\ProviderCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\password;

/**
 * Stores a provider's API token, encrypted, from the installer.
 *
 * This exists because the installer has to put a token somewhere and every
 * other route is worse. The token must not go in `.env`: the credential
 * resolver reads `provider_credentials` and deliberately offers no environment
 * fallback, so a token there would be a second place to look and a second
 * place to leak from. It must not go in by hand-written SQL either, because
 * the column is an encrypted cast and writing it raw would store plaintext in
 * a column everything else assumes is encrypted.
 *
 * So the token arrives one of two ways, and neither is a command-line
 * argument — an argument is visible in `ps` and lands in shell history:
 *
 *   --token-from-env   reads CLOUDBOT_PROVIDER_TOKEN
 *   (interactive)      a hidden prompt
 *
 * The token is never echoed, never logged, and never named in the audit entry
 * this writes. Replacing an existing credential is allowed and is the point:
 * rotating a token is a normal operation. What is recorded is that a
 * credential was set, by whom the change came from, and for which provider.
 */
final class SetProviderCredentialCommand extends Command
{
    protected $signature = 'app:set-provider-credential
                            {provider : The provider code, e.g. hetzner}
                            {--token-from-env : Read the token from CLOUDBOT_PROVIDER_TOKEN instead of prompting}';

    protected $description = 'Store an API credential for a provider, encrypted at rest.';

    private const TOKEN_ENV = 'CLOUDBOT_PROVIDER_TOKEN';

    public function handle(ProviderManager $providers, AuditRecorder $audit): int
    {
        $code = mb_strtolower(trim((string) $this->argument('provider')));

        // Only a provider this build can actually drive. Storing a credential
        // for a code with no implementation would be a promise nothing keeps.
        if (! $providers->isRegistered($code)) {
            $this->error(sprintf('No provider is registered under the code "%s".', $code));
            $this->line('Registered codes: '.implode(', ', $providers->registeredCodes()));

            return self::FAILURE;
        }

        $token = $this->readToken();

        if ($token === null) {
            return self::FAILURE;
        }

        $provider = DB::transaction(function () use ($code, $token, $audit): Provider {
            $provider = Provider::query()->firstOrCreate(
                ['code' => $code],
                ['name' => ucfirst($code), 'enabled' => false],
            );

            // One active credential per provider. The previous row is replaced
            // rather than left behind, so a rotated token cannot be resurrected
            // by something reading the older row.
            ProviderCredential::query()
                ->where('provider_id', $provider->getKey())
                ->delete();

            ProviderCredential::query()->create([
                'provider_id' => $provider->getKey(),
                // Encrypted by the model's cast on the way in.
                'credentials' => ['api_token' => $token],
                'is_active' => true,
            ]);

            $audit->recordFromConsole(
                AuditEvent::ProviderCredentialReplaced,
                subject: $provider,
                // Names the provider, never the credential.
                metadata: ['provider' => $provider->code],
            );

            return $provider;
        });

        $this->info(sprintf('Stored an encrypted credential for %s.', $provider->code));

        if (! $provider->enabled) {
            $this->line('The provider is not enabled. Enable it in the admin panel once you have verified the credential.');
        }

        return self::SUCCESS;
    }

    /** The token, or null when none usable was supplied. */
    private function readToken(): ?string
    {
        if ($this->option('token-from-env')) {
            $token = trim((string) getenv(self::TOKEN_ENV));

            if ($token === '') {
                $this->error(self::TOKEN_ENV.' is empty or unset.');

                return null;
            }

            return $token;
        }

        if (! $this->input->isInteractive()) {
            $this->error('No terminal available. Pass --token-from-env and set '.self::TOKEN_ENV.'.');

            return null;
        }

        $token = trim((string) password(label: 'API token', required: true));

        if ($token === '') {
            $this->error('The token cannot be empty.');

            return null;
        }

        return $token;
    }
}
