<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('harvest_id')->nullable()->unique()->after('asana_user_gid');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('harvest_id')->nullable()->unique()->after('notes');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('harvest_id')->nullable()->unique()->after('asana_project_gid');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('harvest_id')->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn ($t) => $t->dropColumn('harvest_id'));
        Schema::table('clients', fn ($t) => $t->dropColumn('harvest_id'));
        Schema::table('projects', fn ($t) => $t->dropColumn('harvest_id'));
        Schema::table('time_entries', fn ($t) => $t->dropColumn('harvest_id'));
    }
};
