<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE PAYSLIP FORMAT DESIGNER GREW A ROLE SCOPE AND FIVE HEADER TOGGLES; THE
 * TABLE NEVER DID.
 *
 * `surface/PayslipFormats.php` and the Designer screen (route `payslip-format`)
 * started reading and writing `scope_role`, `show_logo`, `show_pan`,
 * `show_uan`, `show_designation` and `show_doj` the day they were built, but
 * no migration ever added them to `payslip_formats` — the table still had
 * only what `2026_03_01_001100_create_payslip_formats.php` gave it. On a
 * database that was never re-migrated (the owner does not run migrations
 * himself), every save was refused outright: SqlStore::put() will not drop an
 * unknown column silently, by design (Law 3 — nothing overwritten, nothing
 * quietly dropped). This is that missing migration.
 *
 * Purely additive, same as every other column added this way: `scope_role` is
 * nullable, like its four scope siblings (empty means "any role"). The five
 * `show_*` toggles default to TRUE — the exact value the application code
 * already assumes for any row saved before they existed — so nothing already
 * stored changes appearance the moment this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payslip_formats')) {
            return;
        }

        Schema::table('payslip_formats', function (Blueprint $table): void {
            if (!Schema::hasColumn('payslip_formats', 'scope_role')) {
                $table->string('scope_role', 128)->nullable()->after('scope_team')
                    ->comment('the Role text from Role/Designation Setup, never the access tier '
                        . 'Roles & Permissions enforces');
            }

            if (!Schema::hasColumn('payslip_formats', 'show_logo')) {
                $table->boolean('show_logo')->default(true)->after('show_employer')
                    ->comment('gates the company\'s own companies.logo_path on the printed slip');
            }

            if (!Schema::hasColumn('payslip_formats', 'show_pan')) {
                $table->boolean('show_pan')->default(true)->after('show_logo');
            }

            if (!Schema::hasColumn('payslip_formats', 'show_uan')) {
                $table->boolean('show_uan')->default(true)->after('show_pan');
            }

            if (!Schema::hasColumn('payslip_formats', 'show_designation')) {
                $table->boolean('show_designation')->default(true)->after('show_uan');
            }

            if (!Schema::hasColumn('payslip_formats', 'show_doj')) {
                $table->boolean('show_doj')->default(true)->after('show_designation');
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
