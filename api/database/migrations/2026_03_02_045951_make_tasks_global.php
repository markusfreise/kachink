<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Drop project FK — tasks are now workspace-global
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');

            // Harvest import support
            $table->string('harvest_id')->nullable()->unique()->after('asana_task_gid');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('harvest_id');
            $table->foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }
};
