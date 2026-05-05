<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id')->nullable()->unique()->after('designation');
            $table->date('joining_date')->nullable()->after('employee_id');
            $table->decimal('salary', 10, 2)->nullable()->after('joining_date');
            $table->decimal('min_hours_per_day', 4, 2)->default(8.00)->after('salary');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employee_id', 'joining_date', 'salary', 'min_hours_per_day']);
        });
    }
};
