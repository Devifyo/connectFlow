<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('time_log_id')->index();
            $table->date('date')->index();
            // random = planned check, idle = brought forward because no activity was detected,
            // overtime = topped up after the planned checks ran out
            $table->string('reason', 20)->default('random');
            $table->timestamp('due_at')->index();
            $table->timestamp('shown_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            // pending | passed | missed | cancelled
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });

        Schema::table('time_logs', function (Blueprint $table) {
            $table->boolean('activity_checks')->default(false);
            $table->string('auto_punch_out_reason', 50)->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('activity_checks_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_checks');
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropColumn(['activity_checks', 'auto_punch_out_reason']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('activity_checks_enabled');
        });
    }
};
