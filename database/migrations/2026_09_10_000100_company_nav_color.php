<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * SUPERSEDED, NEVER RUN. This shipped for one day (10 Sep) as a per-company
 * `nav_color` column — the left navigation pane's own background, distinct
 * from the accent colour. The owner then asked for the left pane colour to be
 * a single, application-wide setting instead (Organization Configuration →
 * Companies → "Company Branding"), which lives in `module_customisations`
 * under the key `app:branding`, not a `companies` column — so this migration
 * has nothing left to add.
 *
 * Left in place as a documented no-op rather than deleted: a migration a
 * customer's `migrations` table may already list as pending should not
 * silently disappear from the filesystem between one deploy and the next.
 * If this has already run somewhere, `down()` staying empty means a stray
 * `companies.nav_color` column is left behind rather than dropped out from
 * under data nobody asked to lose — it is simply unused from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to do. See the class note above.
    }

    public function down(): void
    {
        // Nothing to do.
    }
};
