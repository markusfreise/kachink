<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Projektstatus": a second, independent pipeline for the tasks of one
 * project (e.g. Leads, Beauftragt in Akquise). Defined per project, can be
 * cloned from another project. Shown as a kanban board inside the project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 7)->default('#6B7280');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'name'], 'project_statuses_project_name_unique');
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignUuid('project_status_id')->nullable()->after('status_id')->constrained('project_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_status_id');
        });
        Schema::dropIfExists('project_statuses');
    }
};
