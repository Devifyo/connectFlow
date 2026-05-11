<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        // Set initial sort_order based on current id order per tenant
        $positions = DB::table('positions')->orderBy('tenant_id')->orderBy('id')->get();
        $tenantOrder = [];
        foreach ($positions as $pos) {
            $tenantOrder[$pos->tenant_id] = ($tenantOrder[$pos->tenant_id] ?? 0) + 1;
            DB::table('positions')->where('id', $pos->id)->update(['sort_order' => $tenantOrder[$pos->tenant_id]]);
        }
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
