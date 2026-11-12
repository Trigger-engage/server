<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The tests run on SQLite, which accepts identifiers of any length. MySQL
 * stops at 64 characters, and a migration whose generated index name runs
 * past that fails on a MySQL host halfway through, leaving tables behind.
 * Laravel builds index names from the table and columns, so long table
 * names (engage_…) get there quickly: name such indexes by hand.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    public const MYSQL_MAX = 64;

    public function test_every_index_name_fits_mysql(): void
    {
        $tooLong = collect(DB::select("select name, tbl_name from sqlite_master where type = 'index' and name not like 'sqlite_%'"))
            ->filter(fn (object $index) => strlen($index->name) > self::MYSQL_MAX)
            ->map(fn (object $index) => $index->tbl_name.': '.$index->name.' ('.strlen($index->name).')')
            ->values()
            ->all();

        $this->assertSame([], $tooLong, 'Index names MySQL would refuse. Name them by hand in the migration.');
    }

    public function test_every_table_name_fits_mysql(): void
    {
        $tooLong = collect(DB::select("select name from sqlite_master where type = 'table'"))
            ->filter(fn (object $table) => strlen($table->name) > self::MYSQL_MAX)
            ->pluck('name')
            ->all();

        $this->assertSame([], $tooLong);
    }
}
