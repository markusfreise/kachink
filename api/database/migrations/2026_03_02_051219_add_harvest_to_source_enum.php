<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE time_entries MODIFY COLUMN source ENUM('web', 'menubar', 'manual', 'api', 'harvest') NOT NULL DEFAULT 'web'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE time_entries MODIFY COLUMN source ENUM('web', 'menubar', 'manual', 'api') NOT NULL DEFAULT 'web'");
    }
};
