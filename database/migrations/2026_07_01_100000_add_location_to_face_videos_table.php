<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_videos', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('verified');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('address', 500)->nullable()->after('longitude');
            $table->string('ip_address', 45)->nullable()->after('address');
            $table->string('device', 500)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('face_videos', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'address', 'ip_address', 'device']);
        });
    }
};
