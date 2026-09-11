<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work statuses (Arbeitsstatus) belong to one member; only the fixed "Heute"
 * (user_id null, is_locked) is shared by the organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropUnique('task_statuses_org_name_unique');
            $table->foreignUuid('user_id')->nullable()->after('organization_id')->constrained('users')->cascadeOnDelete();
            $table->index(['organization_id', 'user_id'], 'task_statuses_org_user_idx');
        });

        // Statuses created before this change go to the organization owner so nothing disappears.
        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            $owner = DB::table('organization_user')->where('organization_id', $orgId)->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->value('user_id');
            if ($owner) {
                DB::table('task_statuses')->where('organization_id', $orgId)->where('is_locked', false)->whereNull('user_id')->update(['user_id' => $owner]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('task_statuses', function (Blueprint $table) {
            $table->dropIndex('task_statuses_org_user_idx');
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['organization_id', 'name'], 'task_statuses_org_name_unique');
        });
    }
};
