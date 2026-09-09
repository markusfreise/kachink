<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $withHarvest = ['web', 'menubar', 'manual', 'api', 'harvest'];

    private array $withoutHarvest = ['web', 'menubar', 'manual', 'api'];

    public function up(): void
    {
        $this->setSourceValues($this->withHarvest);
    }

    public function down(): void
    {
        $this->setSourceValues($this->withoutHarvest);
    }

    /**
     * Laravel's enum() maps to a native ENUM on MySQL but to a
     * varchar + CHECK constraint on PostgreSQL. SQLite cannot alter
     * constraints in place and is only used for tests, so it is a no-op.
     */
    private function setSourceValues(array $values): void
    {
        $driver = DB::getDriverName();
        $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE time_entries MODIFY COLUMN source ENUM({$list}) NOT NULL DEFAULT 'web'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE time_entries DROP CONSTRAINT IF EXISTS time_entries_source_check');
            DB::statement("ALTER TABLE time_entries ADD CONSTRAINT time_entries_source_check CHECK (source::text = ANY (ARRAY[{$list}]::text[]))");
        }
    }
};
