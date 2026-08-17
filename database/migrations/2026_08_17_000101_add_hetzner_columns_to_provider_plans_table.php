<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_plans', function (Blueprint $table) {
            $table->string('cpu_type')->nullable()->after('bandwidth_gb');
            $table->string('architecture')->nullable()->after('cpu_type');
            $table->string('storage_type')->nullable()->after('architecture');
            $table->boolean('deprecated')->default(false)->after('storage_type');
        });
    }

    public function down(): void
    {
        Schema::table('provider_plans', function (Blueprint $table) {
            $table->dropColumn(['cpu_type', 'architecture', 'storage_type', 'deprecated']);
        });
    }
};
