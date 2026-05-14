<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->string('punch_in_ip', 45)->nullable()->after('punch_in_address');
            $table->string('punch_in_device', 500)->nullable()->after('punch_in_ip');
            $table->string('punch_out_ip', 45)->nullable()->after('punch_out_address');
            $table->string('punch_out_device', 500)->nullable()->after('punch_out_ip');
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropColumn(['punch_in_ip', 'punch_in_device', 'punch_out_ip', 'punch_out_device']);
        });
    }
};
