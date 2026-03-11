<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_running')->default(false);
            $table->enum('source', ['web', 'menubar', 'manual', 'api'])->default('web');
            $table->string('asana_task_gid')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['project_id', 'started_at']);
            $table->index(['user_id', 'is_running']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
