<?php

declare(strict_types=1);

use App\Enums\TelegramUpdateStatus;
use App\Telegram\Enums\TelegramAction;
use App\Telegram\Enums\TelegramChatType;
use App\Telegram\Enums\TelegramUpdateType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every update Telegram has delivered, once.
 *
 * This table exists for one reason: Telegram retries. It re-delivers an update
 * whenever a webhook is slow, times out, or answers anything but 200, and it
 * does so with the same `update_id`. Without a record of what has already
 * arrived, a customer's single tap becomes two purchases.
 *
 * So `update_id` is UNIQUE, and that constraint — not a check in PHP — is the
 * deduplication authority. An `exists()` followed by an `insert()` has a window
 * between them, and two concurrent retries fit inside it comfortably.
 *
 * What is stored is deliberately not the update. Telegram's payload is
 * arbitrary text from a stranger, and keeping it would put unbounded untrusted
 * content into the database, the logs and every screen that ever renders a row.
 * Instead the boundary resolves each update to a closed vocabulary — a type, a
 * chat type, an action this system recognises — and stores that. A message
 * nobody has a handler for is recorded as having been unrecognised, and its
 * text is discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();

            // Telegram's own identifier, and the deduplication authority.
            // BIGINT because it is a monotonically growing counter per bot.
            $table->bigInteger('update_id')->unique();

            $table->string('type', 20);
            $table->string('chat_type', 20);

            // Identity, and the only thing that identifies a customer. Both
            // BIGINT: Telegram ids exceed 32 bits.
            $table->bigInteger('telegram_user_id')->nullable();
            $table->bigInteger('telegram_chat_id')->nullable();

            $table->bigInteger('message_id')->nullable();

            // Telegram's opaque handle for a pressed button, needed only long
            // enough to stop the client spinner.
            $table->string('callback_query_id', 64)->nullable();

            // What this system understood the update to mean, from a closed
            // list. Never what the customer actually typed.
            $table->string('action', 40);

            $table->string('status', 20)->default(TelegramUpdateStatus::Received->value);

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            // A normalized, scrubbed reason a handler failed. Never a raw
            // exception message: those quote back what was sent, and what was
            // sent to Telegram includes the bot token.
            $table->string('failure_reason', 200)->nullable();

            // Whitelisted facts only, assembled by name at the boundary.
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            // The sweep for work that was recorded but never finished.
            $table->index(['status', 'received_at']);
            $table->index('telegram_user_id');
        });

        $this->checkIn('telegram_updates', 'status', TelegramUpdateStatus::values());
        $this->checkIn('telegram_updates', 'type', TelegramUpdateType::values());
        $this->checkIn('telegram_updates', 'chat_type', TelegramChatType::values());
        $this->checkIn('telegram_updates', 'action', TelegramAction::values());

        // A processed update has a time it was processed, and an unprocessed
        // one does not. Stated once here so the two can never disagree.
        DB::statement(<<<SQL
            ALTER TABLE telegram_updates ADD CONSTRAINT telegram_updates_processed_at_matches_status
            CHECK (
                (status = '{$this->processed()}' AND processed_at IS NOT NULL)
                OR (status <> '{$this->processed()}' AND processed_at IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }

    private function processed(): string
    {
        return TelegramUpdateStatus::Processed->value;
    }

    /**
     * @param  list<string>  $values
     */
    private function checkIn(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
        $null = $nullable ? "{$column} IS NULL OR " : '';

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$null}{$column} IN ({$list}))");
    }
};
