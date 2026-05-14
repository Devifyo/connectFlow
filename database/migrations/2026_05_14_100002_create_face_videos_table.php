<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['enrollment', 'punch_in', 'punch_out']);
            $table->string('file_path');
            $table->unsignedBigInteger('time_log_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_videos');
    }
};
