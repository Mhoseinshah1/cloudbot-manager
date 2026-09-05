<?php

declare(strict_types=1);

use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and administrators share one table.
 *
 * A customer created from Telegram has no email and no password, so both are
 * nullable. Being an administrator is not a column here: it is having a
 * privileged role, which lives in the permission tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();

            $table->string('name')->nullable();

            // Nullable because Telegram customers have no email, but unique so
            // that an address used for admin sign-in identifies exactly one
            // account. PostgreSQL permits many NULLs under a unique index.
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();

            $table->string('status', 20)->default(UserStatus::Active->value);
            $table->string('created_via', 20);

            $table->string('locale', 10);
            $table->string('timezone', 64);
            $table->string('phone', 32)->nullable();

            // Customer money is whole Toman in a BIGINT. Never a float: binary
            // floating point cannot represent these amounts exactly, and this
            // column is the operational balance behind the ledger.
            $table->bigInteger('wallet_balance_toman')->default(0);
            $table->timestamp('wallet_locked_at')->nullable();

            // TOTP material. Encrypted by the model's casts, never in plain text.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
            $table->index('created_via');
        });

        // Enforced in the database as well as the enums, so that a bad value
        // cannot arrive through a raw query, an import or a future migration.
        $this->addCheck('users', 'users_status_check', 'status', UserStatus::values());
        $this->addCheck('users', 'users_created_via_check', 'created_via', UserCreatedVia::values());

        // The wallet may never silently go negative. The ledger in a later
        // phase is the authority on balance changes; this is the floor beneath
        // it that no code path can cross.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_wallet_balance_non_negative CHECK (wallet_balance_toman >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }

    /**
     * @param  list<string>  $allowed
     */
    private function addCheck(string $table, string $name, string $column, array $allowed): void
    {
        $values = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $allowed,
        ));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN ({$values}))");
    }
};
