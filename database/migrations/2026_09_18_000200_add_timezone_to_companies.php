<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPANY CARRIES ITS OWN TIME ZONE.
 *
 * An IANA identifier (e.g. `Asia/Kolkata`), set once per company under
 * Organization Configuration → Companies — nullable and editable through
 * that existing module, same as `state` before it, so every company already
 * on file can have it filled in without a second import. The full option
 * list is declared in config/modules.json (PHP's own DateTimeZone::listIdentifiers()),
 * config, not code, per Law 1.
 *
 * Recorded for now, not yet read by any attendance, payroll or scheduling
 * calculation — those still run on the server's own clock (UTC; see
 * HANDOFF_2026-09-16_to_2026-09-17.md, "environment facts"). Wiring a
 * calculation to this column is a separate, later change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'timezone')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->string('timezone')->nullable()
                    ->comment('IANA time zone identifier, e.g. Asia/Kolkata — not yet read by any calculation');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
