<?php

declare(strict_types=1);

use App\Telegram\Enums\TelegramAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bot learned to sell and to manage servers, so its vocabulary grew.
 *
 * The constraint is rebuilt rather than dropped. It is what guarantees the
 * action column holds a value this system chose and never one a stranger sent,
 * and that guarantee is worth keeping through every phase that adds a verb —
 * a column left unconstrained "for now" is one nobody constrains again.
 *
 * Rebuilt from the enum, so the database and the code cannot disagree about
 * what an action is.
 */
return new class extends Migration
{
    /** The vocabulary before this phase, for a clean rollback. */
    private const PREVIOUS = [
        'start', 'menu.buy_server', 'menu.my_servers', 'menu.wallet',
        'menu.invoices', 'menu.profile', 'menu.help', 'menu.main', 'unknown',
    ];

    public function up(): void
    {
        $this->replaceWith(TelegramAction::values());
    }

    public function down(): void
    {
        // Rows carrying a verb the old vocabulary never had would fail the old
        // constraint. They are history, so they are relabelled as unrecognised
        // rather than deleted: an update that arrived is a fact, and what it
        // asked for is the part this rollback cannot represent.
        DB::table('telegram_updates')
            ->whereNotIn('action', self::PREVIOUS)
            ->update(['action' => 'unknown']);

        $this->replaceWith(self::PREVIOUS);
    }

    /**
     * @param  list<string>  $values
     */
    private function replaceWith(array $values): void
    {
        if (! Schema::hasTable('telegram_updates')) {
            return;
        }

        $list = "'".implode("','", $values)."'";

        DB::statement('ALTER TABLE telegram_updates DROP CONSTRAINT IF EXISTS telegram_updates_action_check');
        DB::statement("ALTER TABLE telegram_updates ADD CONSTRAINT telegram_updates_action_check CHECK (action IN ({$list}))");
    }
};
