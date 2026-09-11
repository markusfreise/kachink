<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly rate hierarchy: an organization default ("Standard"), a rate per
 * member (organization_user pivot), per client and per project. The project
 * decides with rate_mode which one applies to its entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('slug');
        });

        Schema::table('organization_user', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('role');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('color');
        });

        Schema::table('projects', function (Blueprint $table) {
            // standard | user | client | project
            $table->string('rate_mode', 16)->default('standard')->after('hourly_rate');
        });

        // Projects that already carry a rate keep using it.
        DB::table('projects')->whereNotNull('hourly_rate')->update(['rate_mode' => 'project']);
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('rate_mode'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('hourly_rate'));
        Schema::table('organization_user', fn (Blueprint $table) => $table->dropColumn('hourly_rate'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('hourly_rate'));
    }
};
