<?php
declare(strict_types=1);

namespace SmartPRS\People\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** VIOLATING FIXTURE — this is how SmartPRS 2 grew ~60 columns at runtime. */
final class CustomFieldService
{
    public function add(string $table, string $column): void
    {
        Schema::table($table, function ($t) use ($column) {
            $t->string($column)->nullable();
        });

        DB::statement("ALTER TABLE {$table} ADD COLUMN {$column}_at DATETIME NULL");
    }

    public function drop(string $table): void
    {
        Schema::dropIfExists($table);
    }
}
