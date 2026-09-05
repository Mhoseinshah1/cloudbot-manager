<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own catalog, synchronised from its API.
 *
 * These rows mirror what a provider offers. They are not customer-facing
 * products and carry no selling price: a product with a Toman price and a
 * margin is a separate, later concept, built on top of these.
 *
 * Identity is (provider, provider-native id) throughout, because the provider's
 * own id is the only stable handle across a re-sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();

            $table->string('provider_location_id');
            $table->string('name');
            $table->string('country_code', 2);
            $table->string('city');

            // enabled is ours: an operator choosing not to sell here.
            // available is the provider's: it has no capacity right now.
            $table->boolean('enabled')->default(true);
            $table->boolean('available')->default(true);

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_location_id']);
        });

        Schema::create('provider_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();

            $table->string('provider_plan_id');
            $table->string('name');

            $table->unsignedSmallInteger('vcpu');
            $table->unsignedInteger('ram_mb');
            $table->unsignedInteger('disk_gb');
            $table->unsignedInteger('bandwidth_gb')->nullable();

            // NUMERIC, not a float: this is money, even before conversion.
            $table->decimal('provider_price_monthly', 20, 6);
            $table->decimal('provider_price_hourly', 20, 6)->nullable();
            $table->string('provider_currency', 3);

            $table->boolean('enabled')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_plan_id']);
        });

        Schema::create('provider_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();

            $table->string('provider_image_id');
            $table->string('name');
            $table->string('os_family', 50);
            $table->string('version', 50);
            $table->string('architecture', 20);

            $table->boolean('deprecated')->default(false);
            $table->boolean('enabled')->default(true);

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_image_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_images');
        Schema::dropIfExists('provider_plans');
        Schema::dropIfExists('provider_locations');
    }
};
