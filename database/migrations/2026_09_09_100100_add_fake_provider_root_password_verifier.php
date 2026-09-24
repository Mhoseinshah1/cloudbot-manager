<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-way verifier for the password the simulated provider currently holds.
 *
 * Not the password. This column exists so FakeProvider can answer the one
 * question a real provider can answer — "does this credential work?" — without
 * anything being able to read a credential back out of it.
 *
 * The distinction is not academic and being a simulator does not soften it.
 * This is a table in the application's own migration set, created by the same
 * `migrate` that builds production; a plaintext credential column here is a
 * plaintext credential column, and ADR-003 rules out a second durable secret
 * store in exactly those words. An encrypted column would be no better: it
 * would be reversible, which is what "escrow" means.
 *
 * A digest is enough because rotation is all that is ever needed. Recovery never
 * recovers an old password — it issues a new one — so nothing downstream has any
 * use for the previous plaintext, and a verifier that cannot produce it is a
 * more honest model of a provider than one that can.
 *
 * SHA-256, unsalted, is the right primitive for this specific input: the
 * simulator's credentials are 128 bits of `random_bytes`, so there is no
 * guessing, dictionary or rainbow-table exposure to defend against, and a
 * deliberately slow KDF would cost every test in the suite for no security this
 * value needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fake_provider_servers')) {
            return;
        }

        // Defensive: an earlier local iteration of this migration created a
        // plaintext column. Nothing has been published, but a developer who ran
        // it should not be left holding one.
        if (Schema::hasColumn('fake_provider_servers', 'root_password')) {
            Schema::table('fake_provider_servers', function (Blueprint $table): void {
                $table->dropColumn('root_password');
            });
        }

        if (Schema::hasColumn('fake_provider_servers', 'root_password_verifier')) {
            return;
        }

        Schema::table('fake_provider_servers', function (Blueprint $table): void {
            $table->string('root_password_verifier', 64)->nullable()->after('provider_image_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fake_provider_servers')
            || ! Schema::hasColumn('fake_provider_servers', 'root_password_verifier')) {
            return;
        }

        Schema::table('fake_provider_servers', function (Blueprint $table): void {
            $table->dropColumn('root_password_verifier');
        });
    }
};
