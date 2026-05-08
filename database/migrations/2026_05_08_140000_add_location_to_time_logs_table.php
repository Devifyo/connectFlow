<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->decimal('punch_in_lat', 10, 7)->nullable()->after('date');
            $table->decimal('punch_in_lng', 10, 7)->nullable()->after('punch_in_lat');
            $table->string('punch_in_address', 500)->nullable()->after('punch_in_lng');
            $table->decimal('punch_out_lat', 10, 7)->nullable()->after('punch_in_address');
            $table->decimal('punch_out_lng', 10, 7)->nullable()->after('punch_out_lat');
            $table->string('punch_out_address', 500)->nullable()->after('punch_out_lng');
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropColumn(['punch_in_lat', 'punch_in_lng', 'punch_in_address', 'punch_out_lat', 'punch_out_lng', 'punch_out_address']);
        });
    }
};
