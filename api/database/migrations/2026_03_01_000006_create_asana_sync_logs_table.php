<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asana_sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->string('asana_gid');
            $table->enum('status', ['success', 'failed', 'skipped']);
            $table->jsonb('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at');

            $table->index(['entity_type', 'entity_id']);
            $table->index('asana_gid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asana_sync_logs');
    }
};
