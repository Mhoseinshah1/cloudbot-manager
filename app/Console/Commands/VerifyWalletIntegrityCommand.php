<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks that every wallet balance still equals its ledger.
 *
 * The ledger is the truth and the balance column is a running total kept
 * beside it. If they ever disagree, something wrote a balance outside
 * WalletService, and the difference is real money nobody can account for.
 *
 * Reports only. It never writes a correction: silently moving customer money to
 * make a mismatch disappear would destroy the evidence of how it happened. An
 * operator decides what to do, having seen it.
 */
final class VerifyWalletIntegrityCommand extends Command
{
    protected $signature = 'wallet:verify-integrity
                            {--user= : Check a single user id instead of all}';

    protected $description = 'Verify that every wallet balance matches its ledger.';

    public function handle(): int
    {
        $userId = $this->option('user');

        // A left join, so a customer who has never transacted is checked too:
        // their balance must be zero, and a non-zero one is exactly the kind of
        // mismatch worth catching.
        $query = DB::table('users')
            ->leftJoin('wallet_transactions', 'wallet_transactions.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.wallet_balance_toman')
            ->select([
                'users.id',
                'users.wallet_balance_toman as balance',
                DB::raw('COALESCE(SUM(wallet_transactions.amount_toman), 0) as ledger'),
            ]);

        if ($userId !== null) {
            $query->where('users.id', $userId);
        }

        $mismatches = [];
        $checked = 0;

        foreach ($query->get() as $row) {
            $checked++;

            $balance = (int) $row->balance;
            $ledger = (int) $row->ledger;

            if ($balance !== $ledger) {
                $mismatches[] = [$row->id, $balance, $ledger, $balance - $ledger];
            }
        }

        if ($mismatches === []) {
            $this->info(sprintf('Checked %d wallet(s). Every balance matches its ledger.', $checked));

            return self::SUCCESS;
        }

        // Ids and amounts only. Nothing here identifies a person.
        $this->error(sprintf('Checked %d wallet(s). %d do not match.', $checked, count($mismatches)));
        $this->table(['User', 'Balance (Toman)', 'Ledger (Toman)', 'Difference'], $mismatches);
        $this->line('No balance has been changed. Investigate before correcting anything.');

        return self::FAILURE;
    }
}
