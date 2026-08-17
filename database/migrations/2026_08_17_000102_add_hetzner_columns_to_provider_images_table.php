<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_images', function (Blueprint $table) {
            $table->string('type')->nullable()->after('architecture'); // system, snapshot, backup
            $table->string('status')->nullable()->after('type');       // available, creating, unavailable
            $table->string('deprecated')->nullable()->after('status'); // deprecation date or null
        });
    }

    public function down(): void
    {
        Schema::table('provider_images', function (Blueprint $table) {
            $table->dropColumn(['type', 'status', 'deprecated']);
        });
    }
};
