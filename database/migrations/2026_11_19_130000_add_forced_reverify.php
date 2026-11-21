<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            // Random moment (45-60 min after punch-in) at which the session is closed even if the
            // member is active, so they have to punch in (and verify their face) again.
            $table->timestamp('forced_out_at')->nullable();
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('activity_reverify_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', fn (Blueprint $t) => $t->dropColumn('forced_out_at'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('activity_reverify_enabled'));
    }
};
