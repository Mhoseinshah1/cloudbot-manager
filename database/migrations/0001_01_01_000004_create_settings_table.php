<?php

declare(strict_types=1);

use App\Enums\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business controls that change without a deployment.
 *
 * Kill switches, thresholds and policy values belong here rather than in .env:
 * an operator must be able to stop sales during an incident without a release.
 * Infrastructure configuration stays in .env.
 *
 * This phase creates the storage only. The values it will hold arrive with the
 * features that read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            $table->string('key')->unique();

            // Text plus a declared type: uniform storage, unambiguous reads.
            $table->text('value')->nullable();
            $table->string('type', 20)->default(SettingType::String->value);

            // Who last changed it. Null on delete rather than cascade: losing
            // the administrator must not delete the setting they configured.
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        $values = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            SettingType::values(),
        ));

        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_type_check CHECK (type IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
