<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('maintenance_enabled')->default(false)->after('face_recognition_enabled');
            $table->text('maintenance_reason')->nullable()->after('maintenance_enabled');
            $table->timestamp('maintenance_started_at')->nullable()->after('maintenance_reason');
            $table->unsignedBigInteger('maintenance_by')->nullable()->after('maintenance_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['maintenance_enabled', 'maintenance_reason', 'maintenance_started_at', 'maintenance_by']);
        });
    }
};
