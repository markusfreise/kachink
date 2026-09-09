<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every scoped query starts with `organization_id = ?`. PostgreSQL does not
 * index foreign keys automatically, so add composite indexes that match the
 * access patterns of the timer and the reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->index(['organization_id', 'started_at'], 'time_entries_org_started_idx');
            $table->index(['organization_id', 'user_id', 'started_at'], 'time_entries_org_user_started_idx');
            $table->index(['organization_id', 'project_id', 'started_at'], 'time_entries_org_project_started_idx');
        });

        foreach (['clients', 'projects', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->index(['organization_id', 'is_active'], "{$table}_org_active_idx");
            });
        }

        Schema::table('tags', function (Blueprint $table) {
            $table->index(['organization_id'], 'tags_org_idx');
        });

        // Tag names are unique per organization, not globally.
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['organization_id', 'name'], 'tags_org_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique('tags_org_name_unique');
            $table->unique(['name']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex('tags_org_idx');
        });

        foreach (['clients', 'projects', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_org_active_idx");
            });
        }

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_org_started_idx');
            $table->dropIndex('time_entries_org_user_started_idx');
            $table->dropIndex('time_entries_org_project_started_idx');
        });
    }
};
