<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lets specific users skip face registration/verification even when the tenant has it on.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('face_exempt')->default(false)->after('face_enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('face_exempt');
        });
    }
};
