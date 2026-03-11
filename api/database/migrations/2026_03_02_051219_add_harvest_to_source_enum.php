<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE time_entries DROP CONSTRAINT time_entries_source_check");
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_source_check CHECK (source IN ('web', 'menubar', 'manual', 'api', 'harvest'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE time_entries DROP CONSTRAINT time_entries_source_check");
        DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_source_check CHECK (source IN ('web', 'menubar', 'manual', 'api'))");
    }
};
