<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's Telegram identity.
 *
 * `telegram_user_id` is the identity and is unique. The username is display
 * metadata only: people change it, and it can be reassigned to someone else,
 * so it must never be used to look an account up or to authorise anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();

            // Restricted rather than cascading: an account with financial
            // history is never hard-deleted, and this makes an attempt fail
            // loudly instead of quietly removing the identity behind it.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Telegram ids exceed 32 bits, so both are BIGINT.
            $table->bigInteger('telegram_user_id')->unique();
            $table->bigInteger('telegram_chat_id');

            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // Set when Telegram reports the user blocked the bot, so delivery
            // stops instead of retrying forever.
            $table->timestamp('bot_blocked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};
