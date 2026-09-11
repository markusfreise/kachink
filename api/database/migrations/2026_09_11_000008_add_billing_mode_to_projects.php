<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing mode per project replaces the billable checkbox:
 *   none   Ohne Abrechnung
 *   fixed  Fester Preis, budget and billed amount in EUR
 *   hourly Nach Aufwand, budget in hours
 * is_billable stays as a derived flag for time entries and reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('billing_mode', 16)->default('hourly')->after('is_billable');
            $table->decimal('budget_amount', 12, 2)->nullable()->after('budget_hours');
            $table->decimal('billed_amount', 12, 2)->default(0)->after('budget_amount');
        });
        DB::table('projects')->where('is_billable', false)->update(['billing_mode' => 'none']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'budget_amount', 'billed_amount']);
        });
    }
};
