<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('selected_location_id')
                ->nullable()
                ->after('coupon_id')
                ->constrained('provider_locations')
                ->nullOnDelete();

            $table->foreignId('selected_image_id')
                ->nullable()
                ->after('selected_location_id')
                ->constrained('provider_images')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_image_id');
            $table->dropConstrainedForeignId('selected_location_id');
        });
    }
};
