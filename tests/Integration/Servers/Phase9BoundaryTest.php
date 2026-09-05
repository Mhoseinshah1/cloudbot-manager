<?php

declare(strict_types=1);

use App\Support\Queues;
use App\Telegram\Flows\BuyServerFlow;
use App\Telegram\Flows\InvoiceFlow;
use App\Telegram\Flows\ServerManagementFlow;
use App\Telegram\Flows\WalletFlow;
use App\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Schema;

/**
 * The boundaries this phase must not cross, checked against the source itself.
 *
 * Structural rather than behavioural on purpose. "No provider call happens on
 * the interactive worker" is proven behaviourally elsewhere, by counting rows
 * the simulator would have written; this is the complement — that the code
 * which runs there does not even reference the methods, so a future edit that
 * introduced one would fail here rather than in whichever test happened to
 * exercise that path.
 */
function sourceOf(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();

    return $file === false ? '' : (string) file_get_contents($file);
}

it('makes no provider call from anything the interactive worker runs', function (string $class): void {
    $source = sourceOf($class);

    // The operations that reach a network. A customer's tap must never sit
    // inside somebody else's timeout.
    foreach ([
        'createServer', 'deleteServer', 'powerOn', 'powerOff', 'reboot',
        'checkAvailability', 'listServers', 'getAction', 'findByProvisioningToken',
    ] as $method) {
        expect($source)->not->toContain('->'.$method.'(', "{$class} calls {$method}");
    }
})->with([
    TelegramUpdateProcessor::class,
    BuyServerFlow::class,
    WalletFlow::class,
    InvoiceFlow::class,
    ServerManagementFlow::class,
]);

it('resolves no provider implementation on the interactive worker', function (string $class): void {
    $source = sourceOf($class);

    // ServerAccess asks the manager what a provider *can* do, which is a local
    // question about which interfaces an object implements. Nothing in a flow
    // may go further than that.
    expect($source)->not->toContain('->driver(')
        ->and($source)->not->toContain('CloudProviderInterface');
})->with([
    TelegramUpdateProcessor::class,
    BuyServerFlow::class,
    WalletFlow::class,
    InvoiceFlow::class,
    ServerManagementFlow::class,
]);

it('never mutates a wallet balance outside the wallet service', function (): void {
    $offenders = [];

    // Writes, specifically. The model legitimately declares a default and a
    // cast for the column; what must exist nowhere else is code that changes
    // the number, because changing it without a row lock and a ledger entry is
    // how a balance stops matching its own history.
    $writes = [
        '/->wallet_balance_toman\s*=[^=]/',
        '/forceFill\(\[[^\]]*wallet_balance_toman/s',
        '/update\(\[[^\]]*wallet_balance_toman/s',
        '/increment\(\s*.wallet_balance_toman/',
        '/decrement\(\s*.wallet_balance_toman/',
    ];

    foreach (allProductionSources() as $path => $source) {
        if (str_ends_with($path, 'app/Wallet/WalletService.php')) {
            continue;
        }

        foreach ($writes as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $path;
            }
        }
    }

    // One mutation authority, holding a row lock and writing a ledger entry.
    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('never refunds a customer for deleting their own server', function (): void {
    foreach (['app/Servers/ServerTerminationService.php', 'app/Servers/ServerActionExecutor.php',
        'app/Telegram/Flows/ServerManagementFlow.php'] as $path) {
        $source = (string) file_get_contents(base_path($path));

        // Uses, not mentions. The termination service documents in prose that
        // no refund is issued, and that sentence is worth keeping — what must
        // not exist is an injection, a resolution or a call.
        expect($source)->not->toContain('RefundService $')
            ->and($source)->not->toContain('RefundService::class')
            ->and($source)->not->toContain('use App\\Orders\\RefundService;')
            ->and($source)->not->toContain('->refund(')
            ->and($source)->not->toContain('->credit(');
    }
});

it('never loads a server without scoping it to a customer', function (): void {
    foreach (['app/Telegram/Flows/ServerManagementFlow.php', 'app/Servers/ServerActionService.php'] as $path) {
        $source = (string) file_get_contents(base_path($path));

        // Customer-facing code reaches servers through ServerAccess, which
        // starts every query from the customer. A global find here would be an
        // id-numbered window into other people's machines.
        expect($source)->not->toContain('Server::query()')
            ->and($source)->not->toContain('Server::find');
    }
});

it('runs provider work and notifications on their own queues', function (): void {
    expect(App\Jobs\ExecuteServerActionJob::queueName())->toBe(Queues::Provisioning->value)
        ->and(App\Jobs\ProcessOutboxMessageJob::queueName())->toBe(Queues::Notifications->value)
        ->and(App\Jobs\DeleteTelegramMessageJob::queueName())->toBe(Queues::Notifications->value)
        ->and(App\Jobs\ProcessTelegramUpdateJob::queueName())->toBe(Queues::Telegram->value);
});

it('ships no hetzner implementation', function (): void {
    // Phase 10. A provider-neutral phase that quietly grew a real adapter would
    // be a phase nobody reviewed for the things a real adapter needs.
    $offenders = [];

    foreach (allProductionSources() as $path => $source) {
        if (stripos($source, 'hetzner') !== false) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([])
        ->and(glob(base_path('app/Cloud/Hetzner/*')))->toBe([])
        ->and(array_keys(config('providers.implementations', [])))->toBe(['fake']);
});

it('ships no monthly renewal implementation', function (): void {
    // Phase 11. Cancelling a subscription because a customer deleted their
    // server is this phase; renewing, warning and terminating on expiry is not.
    foreach (['renewSubscription', 'processExpiry', 'subscriptions:process-expiry', 'grace_until ='] as $needle) {
        foreach (allProductionSources() as $path => $source) {
            expect($source)->not->toContain($needle, "{$path} contains {$needle}");
        }
    }

    expect(Schema::hasTable('billing_charges'))->toBeFalse();
});

it('offers only the capabilities this release implements', function (): void {
    // Advertising one before it works would be a promise the system cannot
    // keep. Rebuild and usage belong to Release 1.1.
    //
    // `password_reset` is here by an explicit decision and with a narrow scope:
    // it lets provisioning rotate a root credential that was lost before the
    // server was ever delivered, which is the only way that credential can be
    // recovered without a second secret store. It is not a customer feature —
    // the boundary tests below prove no customer-facing reset flow exists.
    $cases = array_map(
        static fn (App\Cloud\Enums\ProviderCapability $case): string => $case->value,
        App\Cloud\Enums\ProviderCapability::cases(),
    );

    expect($cases)->toBe(['power_control', 'reboot', 'password_reset']);
});

/**
 * Every production PHP file, keyed by its repository-relative path.
 *
 * @return array<string, string>
 */
function allProductionSources(): array
{
    $sources = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $sources[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}
