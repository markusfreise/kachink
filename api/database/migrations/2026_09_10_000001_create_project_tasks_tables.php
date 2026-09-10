<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task management: project tasks with nested subtasks, tags, attachments,
 * comments and a change history. Distinct from `tasks`, which are the global
 * work types a time entry is booked on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // immediate | urgent | soon | easy (validated in the request, string for SQLite/Postgres parity)
            $table->string('priority', 16)->default('soon');
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->date('deadline')->nullable();
            $table->date('reminder_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'project_id'], 'project_tasks_org_project_idx');
            $table->index(['organization_id', 'assignee_id'], 'project_tasks_org_assignee_idx');
            $table->index(['organization_id', 'reminder_at'], 'project_tasks_org_reminder_idx');
            $table->index('parent_id', 'project_tasks_parent_idx');
        });

        // Self-reference after the primary key exists; Postgres rejects it inside the create statement.
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('project_tasks')->cascadeOnDelete();
        });

        Schema::create('project_task_tag', function (Blueprint $table) {
            $table->foreignUuid('project_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_task_id', 'tag_id']);
        });

        Schema::create('project_task_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['project_task_id', 'created_at'], 'project_task_comments_task_created_idx');
        });

        Schema::create('project_task_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('project_task_id', 'project_task_attachments_task_idx');
        });

        Schema::create('project_task_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // created | updated | completed | reopened | comment_added | comment_deleted | attachment_added | attachment_removed
            $table->string('action', 32);
            // { field: { from: ..., to: ... } } for updates, free-form for the rest
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['project_task_id', 'created_at'], 'project_task_histories_task_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_histories');
        Schema::dropIfExists('project_task_attachments');
        Schema::dropIfExists('project_task_comments');
        Schema::dropIfExists('project_task_tag');
        Schema::dropIfExists('project_tasks');
    }
};
