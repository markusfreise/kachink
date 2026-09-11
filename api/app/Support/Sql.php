<?php

namespace App\Support;

/** Small driver differences kept in one place. */
final class Sql
{
    /** Case-insensitive LIKE: ILIKE on Postgres, plain LIKE elsewhere (SQLite ignores case for ASCII anyway). */
    public static function like(): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
