<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks due today are moved into the "Heute" status once per day and flagged
 * until the user completes them, keeps them in Heute explicitly or moves the
 * deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->date('today_moved_on')->nullable()->after('status_id');
            $table->date('today_acknowledged_on')->nullable()->after('today_moved_on');
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn(['today_moved_on', 'today_acknowledged_on']);
        });
    }
};
