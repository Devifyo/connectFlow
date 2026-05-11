<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id');
            $table->timestamps();

            $table->primary(['user_id', 'position_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('position_id')->references('id')->on('positions')->onDelete('cascade');
        });

        // Migrate existing position_id data to pivot table
        $rows = DB::table('users')->whereNotNull('position_id')->get(['id', 'position_id']);
        foreach ($rows as $row) {
            DB::table('position_user')->insert([
                'user_id' => $row->id,
                'position_id' => $row->position_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
            $table->dropColumn('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('position_id')->nullable()->after('designation');
            $table->foreign('position_id')->references('id')->on('positions')->onDelete('set null');
        });

        $pivots = DB::table('position_user')->get();
        foreach ($pivots as $pivot) {
            DB::table('users')->where('id', $pivot->user_id)->update(['position_id' => $pivot->position_id]);
        }

        Schema::dropIfExists('position_user');
    }
};
