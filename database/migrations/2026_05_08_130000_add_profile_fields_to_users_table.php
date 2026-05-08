<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('address')->nullable()->after('email');
            $table->string('profile_picture')->nullable()->after('address');
            $table->string('higher_education')->nullable()->after('profile_picture');
            $table->date('date_of_birth')->nullable()->after('higher_education');
            $table->string('phone_country_code', 5)->nullable()->after('date_of_birth');
            $table->string('phone_number', 20)->nullable()->after('phone_country_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['address', 'profile_picture', 'higher_education', 'date_of_birth', 'phone_country_code', 'phone_number']);
        });
    }
};
