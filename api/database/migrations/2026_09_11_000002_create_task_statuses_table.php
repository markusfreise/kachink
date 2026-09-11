<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Free-form task statuses per organization ("Heute", "Warten auf Kunde", ...).
 * "Heute" is a fixed default that every organization gets and cannot delete.
 * Independent of completed_at, which stays the done flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 7)->default('#6B7280');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'name'], 'task_statuses_org_name_unique');
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignUuid('status_id')->nullable()->after('priority')->constrained('task_statuses')->nullOnDelete();
        });

        $now = now();
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            DB::table('task_statuses')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $orgId,
                'name' => 'Heute',
                'color' => '#D23F3F',
                'position' => 0,
                'is_locked' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });
        Schema::dropIfExists('task_statuses');
    }
};
