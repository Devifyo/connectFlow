<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Newest group message already covered by an "unseen messages" email, so we never email it twice.
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_notified_message_id')->nullable()->after('can_send');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('last_notified_message_id');
        });
    }
};
