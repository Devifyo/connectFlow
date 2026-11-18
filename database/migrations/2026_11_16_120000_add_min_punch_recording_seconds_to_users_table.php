<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Minimum length (seconds) a face punch recording must reach before the
            // confirmation modal is allowed to close. 0 = no minimum.
            $table->unsignedSmallInteger('min_punch_recording_seconds')->default(0)->after('min_hours_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('min_punch_recording_seconds');
        });
    }
};
