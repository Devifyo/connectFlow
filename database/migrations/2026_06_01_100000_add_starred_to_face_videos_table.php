<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_videos', function (Blueprint $table) {
            $table->boolean('starred')->default(false)->after('verified');
        });
    }

    public function down(): void
    {
        Schema::table('face_videos', function (Blueprint $table) {
            $table->dropColumn('starred');
        });
    }
};
