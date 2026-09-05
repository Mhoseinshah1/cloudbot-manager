<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simulated remote state for FakeProvider.
 *
 * These tables stand in for another company's infrastructure, not for anything
 * this system owns. They are named fake_provider_* so that nobody mistakes them
 * for the local `servers` and `server_actions` tables that a later phase adds:
 * those record what we sold and owe, these pretend to be someone else's API.
 *
 * The state lives in PostgreSQL rather than in a static array or a singleton
 * because a real provider's state survives our process restarting, and because
 * only a database can enforce the idempotency constraint below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_provider_servers', function (Blueprint $table): void {
            $table->id();

            // A ULID, like a real provider's opaque id. Never a counter: a
            // process-local sequence would repeat ids after a restart and
            // collide across workers.
            $table->string('provider_server_id', 40)->unique();

            // The heart of the create contract. Unique, so two concurrent
            // attempts carrying one token cannot become two servers however
            // the application behaves; the loser of the race reads the winner's
            // row and returns it.
            $table->string('provisioning_token')->nullable()->unique();

            $table->string('name');
            $table->string('provider_plan_id');
            $table->string('provider_location_id');
            $table->string('provider_image_id');

            $table->string('status', 20);
            $table->string('power_state', 20);

            // Synthetic and deterministic; no real address is implied.
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 45)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('fake_provider_actions', function (Blueprint $table): void {
            $table->id();

            $table->string('provider_action_id', 40)->unique();
            $table->string('command', 30);
            $table->string('status', 20);

            // Not a foreign key: a real provider's action history outlives the
            // server it refers to, and deleting a server must not erase the
            // record of the delete.
            $table->string('provider_server_id', 40)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('provider_server_id');
            $table->index('command');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_provider_actions');
        Schema::dropIfExists('fake_provider_servers');
    }
};
