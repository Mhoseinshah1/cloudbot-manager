<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_locations', function (Blueprint $table) {
            $table->string('network_zone')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('provider_locations', function (Blueprint $table) {
            $table->dropColumn('network_zone');
        });
    }
};
