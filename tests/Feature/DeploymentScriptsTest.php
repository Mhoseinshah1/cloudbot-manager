<?php

declare(strict_types=1);

/**
 * Static guards over the deployment scripts.
 *
 * These scripts run as root on a production host, against the live database.
 * The failure modes they have to be protected from are the ones that destroy
 * data rather than the ones that return a wrong number, and most of those are
 * a single careless line: a `down -v`, a `reset --hard` over an operator's
 * hotfix, a password on a command line, a backup written in the clear.
 *
 * Checking the source text is crude, but it is the only check that runs
 * without a Docker daemon, a real host and a real database — and it is
 * exactly the class of regression that a reviewer skims past.
 */
function deploymentScript(string $name): string
{
    return (string) file_get_contents(base_path($name));
}

/** @return list<string> */
function deploymentScripts(): array
{
    return ['install.sh', 'update.sh', 'backup.sh', 'restore.sh'];
}

/**
 * The script with its comment lines removed.
 *
 * These files explain at length what they refuse to do — "never runs
 * `docker compose down -v`", "$RANDOM is not good enough" — and that prose is
 * worth keeping. So the checks below look at what the script *executes*, the
 * same uses-not-mentions distinction the phase boundary tests draw.
 */
function deploymentCode(string $name): string
{
    $lines = preg_split('/\R/', deploymentScript($name)) ?: [];

    return implode("\n", array_filter(
        $lines,
        static fn (string $line): bool => ! preg_match('/^\s*#/', $line),
    ));
}

/** @return list<string> */
function deploymentShellSources(): array
{
    return [
        ...deploymentScripts(),
        'scripts/lib/common.sh',
        'scripts/lib/stack.sh',
        'scripts/lib/hostnginx.sh',
        'docker/php/entrypoint.sh',
    ];
}

it('ships the four deployment scripts, executable', function (string $name): void {
    $path = base_path($name);

    expect(file_exists($path))->toBeTrue("{$name} is missing")
        ->and(is_executable($path))->toBeTrue("{$name} is not executable");
})->with(deploymentScripts());

it('fails fast in every script', function (string $name): void {
    // Without pipefail a failed pg_dump inside a pipeline produces a truncated
    // backup that exits zero. Without -e a failed step runs the next one.
    expect(deploymentScript($name))->toContain('set -Eeuo pipefail', "{$name}");
})->with(deploymentScripts());

it('never destroys a volume', function (string $name): void {
    $code = deploymentCode($name);

    // `down -v` removes the PostgreSQL and Redis volumes. There is no
    // deployment situation in which that is the right thing to do
    // automatically: those volumes are the production data.
    expect($code)->not->toMatch('/\bdown\s+(-v|--volumes)\b/', "{$name} runs down -v")
        ->and($code)->not->toMatch('/\bvolume\s+(rm|prune)\b/', "{$name} removes a volume");
})->with(deploymentShellSources());

it('never discards an operator\'s local changes', function (): void {
    $update = deploymentScript('update.sh');

    // An operator's edit to a tracked file is a decision somebody made, quite
    // possibly mid-incident. The updater refuses to run rather than throwing
    // it away.
    $code = deploymentCode('update.sh');

    expect($update)->toContain('assert_clean_worktree')
        // Not executed. Both appear in the refusal message, which is the point.
        ->and($code)->not->toMatch('/^\s*git\b[^\n]*\bclean\b/m')
        ->and($code)->not->toMatch('/^\s*git\b[^\n]*checkout -- \./m');

    // One `reset --hard` exists, and only on the rollback path, to a revision
    // the script itself recorded moments earlier.
    expect(preg_match_all('/^\s*git\b[^\n]*reset --hard/m', $code))->toBe(1)
        ->and($code)->toContain('git -C "${INSTALL_DIR}" reset --hard "${OLD_REVISION}"');
});

it('stops the workers before it migrates', function (): void {
    $update = deploymentScript('update.sh');

    $stop = strpos($update, 'FAILED_STAGE="worker shutdown"');
    $migrate = strpos($update, 'FAILED_STAGE="migrations"');
    $restart = strpos($update, 'FAILED_STAGE="worker restart"');

    expect($stop)->not->toBeFalse()
        ->and($migrate)->not->toBeFalse()
        ->and($restart)->not->toBeFalse()
        // A job running against a half-migrated schema is how a deploy
        // corrupts data rather than merely failing.
        ->and($stop)->toBeLessThan($migrate)
        ->and($migrate)->toBeLessThan($restart);
});

it('backs up before it changes anything', function (): void {
    $update = deploymentScript('update.sh');

    expect(strpos($update, 'FAILED_STAGE="backup"'))
        ->toBeLessThan(strpos($update, 'FAILED_STAGE="source deployment"'));
});

it('recreates the proxy whenever the app container is replaced', function (string $name): void {
    // Recreating the app gives it a new address on the Docker network. A proxy
    // left pointing at the old one answers every public request with 502,
    // while `docker compose ps` still reports everything as running.
    expect(deploymentScript($name))->toContain('recreate_proxy', "{$name}");
})->with(['install.sh', 'update.sh', 'restore.sh']);

it('never regenerates an existing application key', function (): void {
    $install = deploymentScript('install.sh');

    // A second APP_KEY does not reset anything; it makes every encrypted
    // provider token and server credential permanently unreadable.
    expect($install)->toContain('Existing APP_KEY preserved')
        ->and($install)->not->toContain('key:generate');

    // And it is validated before any runtime container writes data with it.
    expect($install)->toContain('assert_key_usable')
        ->and($install)->toContain('verify_app_key_in_container');
});

it('orders the first install so nothing starts without a key', function (): void {
    $install = deploymentScript('install.sh');

    $key = strpos($install, 'FAILED_STAGE="application key"');
    $build = strpos($install, 'FAILED_STAGE="image build"');
    $infra = strpos($install, 'FAILED_STAGE="infrastructure startup"');
    $app = strpos($install, 'FAILED_STAGE="application startup"');
    $verify = strpos($install, 'FAILED_STAGE="application key verification"');
    $migrate = strpos($install, 'FAILED_STAGE="migrations"');

    expect($key)->toBeLessThan($build)
        ->and($build)->toBeLessThan($infra)
        ->and($infra)->toBeLessThan($app)
        ->and($app)->toBeLessThan($verify)
        // Nothing is written until the running container has been asked what
        // key it actually loaded.
        ->and($verify)->toBeLessThan($migrate);
});

it('never ships a known database password', function (): void {
    $install = deploymentScript('install.sh');

    // A default such as "secret" becomes a known production credential the
    // moment somebody reads the repository.
    expect($install)->not->toMatch('/DB_PASSWORD[=:]\s*["\']?secret/i')
        ->and($install)->toContain('random_secret')
        // A mismatch against an initialized volume is reported, never "fixed".
        ->and($install)->toContain('assert_database_credentials_usable');
});

it('generates secrets from a cryptographic source', function (): void {
    $common = deploymentScript('scripts/lib/common.sh');

    expect($common)->toContain('openssl rand')
        ->and($common)->toContain('/dev/urandom')
        // $RANDOM is a 15-bit PRNG seeded from the pid and the clock. A
        // webhook secret or database password drawn from it is guessable.
        // The name survives in a comment saying exactly that.
        ->and(deploymentCode('scripts/lib/common.sh'))->not->toContain('$RANDOM');
});

it('never puts a secret on a command line', function (string $name): void {
    $source = deploymentScript($name);

    // Anything in argv is visible in `ps` to every user on the host and lands
    // in the shell history of whoever ran it.
    expect($source)->not->toMatch('/--password[= ]\$/')
        ->and($source)->not->toMatch('/PGPASSWORD=\S+ (psql|pg_dump|pg_restore)/')
        ->and($source)->not->toMatch('/--passphrase [^-]/');
})->with(deploymentScripts());

it('encrypts every backup and verifies it', function (): void {
    $backup = deploymentScript('backup.sh');

    // The archive carries a full database dump and the .env holding APP_KEY.
    expect($backup)->toContain('select_encryptor')
        ->and($backup)->toContain('age')
        ->and($backup)->toContain('gpg')
        // A backup nobody has opened is a guess, not a backup.
        ->and($backup)->toContain('verify_archive')
        ->and($backup)->toContain('sha256sum')
        // Finalised only once encryption succeeded, so an interrupted run
        // cannot leave something that looks usable.
        ->and($backup)->toContain('.partial')
        ->and($backup)->toContain('chmod 600');
});

it('keeps the backup key outside the backup', function (): void {
    $backup = deploymentScript('backup.sh');

    // A key stored inside the thing it encrypts is not a key.
    expect($backup)->toContain('PASSPHRASE_FILE')
        ->and($backup)->toContain('/etc/cloudbot-manager/backup.passphrase');

    // The manifest lists what the archive carries; the passphrase is not on it.
    $contents = [];
    preg_match('/"contents":\s*\[([^\]]*)\]/', $backup, $contents);
    expect($contents[1] ?? '')->not->toContain('passphrase');
});

it('backs up only what cannot be reinstalled', function (): void {
    $backup = deploymentScript('backup.sh');

    foreach (['vendor', 'node_modules', 'bootstrap/cache'] as $excluded) {
        expect($backup)->not->toContain("{$excluded}.tar.gz");
    }

    expect($backup)->toContain('pg_dump')
        ->and($backup)->toContain('--format=custom');
});

it('retains backups without touching unrelated files', function (): void {
    $backup = deploymentScript('backup.sh');

    expect($backup)->toContain('KEEP_DAILY')
        ->and($backup)->toContain('KEEP_WEEKLY')
        // Only files this script wrote are ever candidates for removal.
        ->and($backup)->toContain("-name 'cloudbot-*.tar.gz.*'")
        // And an empty variable aborts rather than expanding to a wider path.
        ->and($backup)->toContain('"${OUTPUT_DIR:?}/${file:?}"');
});

it('invokes the encryptor with flags it actually has', function (string $name): void {
    $code = deploymentCode($name);

    // Phase 14 found this the only way it can be found: by running it. age has
    // no --passphrase-file; it prompts on a terminal and takes a key file only
    // as --identity. The scripts used the flag that does not exist, so every
    // age-encrypted backup failed at the point of encryption — after the dump
    // had already been taken, which is the worst moment to discover it.
    // Matched on the invocation form, not the word: these scripts legitimately
    // mention age and their own --passphrase-file flag in prose.
    expect($code)->not->toMatch('/\bage --(encrypt|decrypt)[^\n]*--passphrase-file/', "{$name} passes --passphrase-file to age");

    if (str_contains($code, 'age --encrypt') || str_contains($code, 'age --decrypt')) {
        expect($code)->toMatch('/\bage --(encrypt|decrypt)[^\n]*--identity/', "{$name} must give age an --identity");
    }

    // gpg does support a passphrase file, and must never take one as an
    // inline argument.
    expect($code)->not->toMatch('/\bgpg\b[^\n|]*--passphrase [^-]/', "{$name} passes a gpg passphrase in argv");
})->with(['backup.sh', 'restore.sh']);

it('rolls the source back with something that moves a checkout backwards', function (): void {
    $code = deploymentCode('update.sh');

    // `git merge --ff-only <ancestor>` reports "already up to date" and exits
    // zero without moving anything, so a rollback built on it silently leaves
    // the failed revision deployed while saying it rolled back. Only a reset
    // moves a checkout backwards.
    expect($code)->not->toMatch('/merge --ff-only "\$\{OLD_REVISION\}"/');

    $rollback = substr($code, (int) strpos($code, 'attempt_source_rollback() {'));
    expect($rollback)->toContain('reset --hard "${OLD_REVISION}"')
        // And the rollback is only reported as done once HEAD agrees.
        ->and($rollback)->toContain('rev-parse HEAD')
        ->and($rollback)->toContain('Operator intervention is required');
});

it('makes restore explicit and never blind', function (): void {
    $restore = deploymentScript('restore.sh');

    // A typed sentence, because "y" is too easy to hit by reflex on the one
    // command that replaces the production database.
    expect($restore)->toContain('CONFIRM_PHRASE')
        ->and($restore)->toContain('replace the database')
        // Non-interactive runs must say so; --yes is never a default.
        ->and($restore)->toContain('ASSUME_YES=false')
        ->and($restore)->toContain('--yes')
        // Integrity before destruction.
        ->and($restore)->toContain('verify_payload')
        ->and($restore)->toContain('inspect_manifest')
        ->and($restore)->toContain('take_safety_backup')
        // Restored into the running database, never by deleting its volume.
        ->and($restore)->toContain('pg_restore')
        ->and($restore)->toContain('--exit-on-error');
});

it('removes the decrypted copy however it exits', function (): void {
    $restore = deploymentScript('restore.sh');

    // The unpacked payload is the whole production database in the clear.
    expect($restore)->toContain('trap cleanup EXIT INT TERM')
        ->and($restore)->toContain('rm -rf -- "${WORK_DIR:?}"');
});

it('reports failures without printing configuration', function (): void {
    $stack = deploymentScript('scripts/lib/stack.sh');

    expect($stack)->toContain('diagnostics')
        ->and($stack)->toContain('compose ps')
        ->and($stack)->toContain('logs --tail')
        // Diagnostics get pasted into support threads. Log output is filtered
        // on the way out, and .env is never read into it.
        ->and($stack)->toContain('scrub_secrets')
        ->and($stack)->not->toContain('cat "${ENV_FILE}"');
});

it('touches only its own nginx site', function (): void {
    $nginx = deploymentScript('scripts/lib/hostnginx.sh');

    // This may well be a host that already serves something else.
    expect($nginx)->toContain('cloudbot-manager')
        ->and($nginx)->not->toContain('rm -rf /etc/nginx')
        ->and($nginx)->not->toContain('sites-enabled/default')
        ->and($nginx)->not->toContain('> /etc/nginx/nginx.conf')
        // Validated before reload, so a broken file never takes the site down.
        ->and($nginx)->toContain('nginx -t');

    $validate = strpos($nginx, 'nginx -t');
    $reload = strpos($nginx, 'systemctl reload nginx');
    expect($validate)->toBeLessThan($reload);
});

it('checks DNS before asking for a certificate', function (): void {
    $nginx = deploymentScript('scripts/lib/hostnginx.sh');

    // A failed authorization counts against a rate limit, so the cheap check
    // happens first.
    expect($nginx)->toContain('check_dns_resolves')
        ->and($nginx)->toContain('--cert-name')
        ->and($nginx)->toContain('verify_renewal')
        // Never teaches an operator that a certificate warning is ignorable.
        ->and($nginx)->not->toContain('--insecure')
        ->and($nginx)->not->toContain('curl -k');
});

it('sets the telegram webhook only over verified https', function (): void {
    $install = deploymentScript('install.sh');

    expect($install)->toContain('verify_public_health')
        ->and($install)->toContain('telegram:webhook set');

    // The URL is only promoted to https once the public endpoint has answered.
    expect(strpos($install, 'verify_public_health "${domain}" 60'))
        ->toBeLessThan(strpos($install, 'env_set "${ENV_FILE}" APP_URL "https://${domain}"'));
});

it('bootstraps through the application, never through raw sql', function (): void {
    $install = deploymentScript('install.sh');

    // Roles, the owner account and provider credentials all go through the
    // application's own commands. Writing them with SQL would bypass hashing,
    // the encrypted cast and the audit trail in one go.
    expect($install)->toContain('app:create-admin')
        ->and($install)->toContain('RolePermissionSeeder')
        ->and($install)->toContain('app:set-provider-credential')
        ->and($install)->not->toContain('INSERT INTO')
        ->and($install)->not->toContain('psql -c "INSERT');
});

it('does not enable the simulator catalog on production', function (): void {
    $install = deploymentScript('install.sh');

    // Selling from the simulator would take a customer's money for a server
    // that does not exist.
    expect($install)->toContain('refusing to install the demo catalog');
});

it('leaves host hardening to the operator', function (string $name): void {
    $source = deploymentScript($name);

    // Firewall, SSH and update policy belong to whoever owns the host. An
    // application installer silently changing them is how a shared machine
    // loses its access rules.
    foreach (['ufw enable', 'ufw allow', 'PermitRootLogin', 'fail2ban', 'unattended-upgrades'] as $forbidden) {
        expect($source)->not->toContain($forbidden, "{$name} modifies {$forbidden}");
    }
})->with(deploymentShellSources());

it('serialises deployment operations', function (string $name): void {
    // Two updates at once would race each other through the migrator.
    expect(deploymentScript($name))->toContain('take_lock');
})->with(['install.sh', 'update.sh', 'restore.sh']);
