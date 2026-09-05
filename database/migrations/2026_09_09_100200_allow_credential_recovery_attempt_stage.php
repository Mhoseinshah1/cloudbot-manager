<?php

declare(strict_types=1);

use App\Enums\ProvisioningStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stage for the attempt that rotates a lost pre-delivery credential.
 *
 * `provisioning_attempts.stage` is constrained to the stages the enum names,
 * deliberately — a stage nobody can read is a forensic record nobody can trust.
 * Recovering a credential is a new one, and it needs to be countable on its own.
 *
 * That separateness is the whole reason for a stage rather than a reuse. The
 * create budget on `orders.attempts` decides how many machines an order may ask
 * for. Credential recovery asks for no machine; counting it there would let a
 * few failed password resets retire an order whose server is running perfectly
 * well, and refunding or parking it would be the wrong answer to a machine that
 * exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_attempts')) {
            return;
        }

        $this->constrain(ProvisioningStage::values());
    }

    public function down(): void
    {
        if (! Schema::hasTable('provisioning_attempts')) {
            return;
        }

        $this->constrain(array_values(array_filter(
            ProvisioningStage::values(),
            static fn (string $stage): bool => $stage !== ProvisioningStage::CredentialRecovery->value,
        )));
    }

    /**
     * @param  list<string>  $stages
     */
    private function constrain(array $stages): void
    {
        $list = implode(', ', array_map(static fn (string $stage): string => "'".$stage."'", $stages));

        DB::statement('ALTER TABLE provisioning_attempts DROP CONSTRAINT IF EXISTS provisioning_attempts_stage_check');
        DB::statement("ALTER TABLE provisioning_attempts ADD CONSTRAINT provisioning_attempts_stage_check CHECK (stage IN ({$list}))");
    }
};
