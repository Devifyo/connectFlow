<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_videos', function (Blueprint $table) {
            $table->boolean('verified')->default(true)->after('time_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('face_videos', function (Blueprint $table) {
            $table->dropColumn('verified');
        });
    }
};
