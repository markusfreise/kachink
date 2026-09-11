<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asana import: token per organization, Asana projects map to clients, Asana
 * tasks become tasks or projects. GIDs make repeated runs idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('asana_token')->nullable()->after('hourly_rate');
            $table->string('asana_workspace_gid')->nullable()->after('asana_token');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->string('asana_project_gid')->nullable()->after('harvest_id')->index();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('asana_task_gid')->nullable()->after('asana_project_gid')->index();
        });
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->string('asana_task_gid')->nullable()->after('position')->index();
        });
        Schema::table('project_task_comments', function (Blueprint $table) {
            $table->string('asana_story_gid')->nullable()->after('body')->index();
        });
    }

    public function down(): void
    {
        Schema::table('project_task_comments', fn (Blueprint $t) => $t->dropColumn('asana_story_gid'));
        Schema::table('project_tasks', fn (Blueprint $t) => $t->dropColumn('asana_task_gid'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('asana_task_gid'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('asana_project_gid'));
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['asana_token', 'asana_workspace_gid']));
    }
};
