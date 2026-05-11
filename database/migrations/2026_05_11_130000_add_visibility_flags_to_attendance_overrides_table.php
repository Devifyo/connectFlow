<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_overrides', function (Blueprint $table) {
            $table->boolean('show_status_to_member')->default(false)->after('note');
            $table->boolean('show_note_to_member')->default(false)->after('show_status_to_member');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_overrides', function (Blueprint $table) {
            $table->dropColumn(['show_status_to_member', 'show_note_to_member']);
        });
    }
};
