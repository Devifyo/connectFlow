<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('can_create_groups')->default(false)->after('is_active');
        });

        // CEO / CTO can create groups out of the box; admins can change it per position.
        DB::table('positions')->whereIn('title', ['CEO', 'CTO'])->update(['can_create_groups' => true]);

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type')->default('direct')->after('tenant_id');
            $table->string('title')->nullable()->after('type');
            $table->foreignId('created_by')->nullable()->after('title')->constrained('users')->nullOnDelete();
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->string('role')->default('member')->after('user_id');
            $table->boolean('can_send')->default(true)->after('role');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->json('mentions')->nullable()->after('type');
            $table->timestamp('pinned_at')->nullable()->after('mentions');
            $table->foreignId('pinned_by')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropColumn(['mentions', 'pinned_at']);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['role', 'can_send']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'title']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('can_create_groups');
        });
    }
};
