<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPANY CARRIES ITS OWN PROBATION POLICY.
 *
 * Owner, 23 Sep: "The probation settings to be part of Company table" and
 * "Include this Probation along with the Companies itself as we have other
 * settings of the companies including time zone, etc." — so these sit beside
 * `timezone` and `state` as ordinary company columns, editable through the
 * existing Companies module like every other field on that record, with their
 * labels and help declared in config/modules.json (config, not code, Law 1).
 *
 * EVERY COLUMN IS NULLABLE, AND NULL IS NOT ZERO. A null means "this company
 * has not set its own; follow the tenant default" — which is a live link, not a
 * copy: change the default and this company moves with it. A zero in
 * `probation_notify_hr` means somebody deliberately turned HR's reminder off.
 * Defaulting these to anything would have erased that distinction on every
 * company already on file, which is the whole reason the policy is inherited
 * rather than stamped in at creation.
 *
 * `probation_months` IS THE MARKER. The Companies screen saves all six together,
 * so a row with months set has its own policy and a row without follows the
 * default; Surface\Probation::config() reads it exactly that way. One marker
 * rather than six independent overrides, because six would let a company end up
 * with its own reminder days and the tenant's months — a combination nobody
 * chose and nobody could see.
 *
 * Reminder milestones are a short comma-separated list of whole days
 * ("30, 15, 7, 0"), kept as typed rather than as a JSON array: it is read back
 * into the same box it was typed into, and a JSON column would make that box
 * lie about its own format.
 */
return new class extends Migration
{
    /** column => the comment that says what it is for */
    private const COLUMNS = [
        'probation_months' => 'Probation length in months for this company; NULL follows the tenant default',
        'probation_days' => 'Extra days on top of the months; NULL with months NULL follows the default',
        'probation_reminder_days' => 'Reminder milestones in days before the end, comma separated, e.g. "30, 15, 7, 0"',
        'probation_notify_hr' => 'Tell HR at each milestone (0/1); NULL follows the default',
        'probation_notify_manager' => 'Tell the reporting manager at each milestone (0/1); NULL follows the default',
        'probation_congratulate' => 'Congratulate the employee on confirmation (0/1); NULL follows the default',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column => $comment) {
                if (Schema::hasColumn('companies', $column)) {
                    continue;
                }

                if ($column === 'probation_reminder_days') {
                    $table->string($column, 120)->nullable()->comment($comment);

                    continue;
                }

                if (str_starts_with($column, 'probation_notify') || $column === 'probation_congratulate') {
                    $table->boolean($column)->nullable()->comment($comment);

                    continue;
                }

                // MONTHS AND DAYS: small whole numbers, unsigned because a
                // negative probation is not a shorter one, it is a defect.
                //
                // unsignedTinyInteger, NOT unsignedSmallInteger: this project's
                // migration vocabulary is a DECLARED list (tests/lib/
                // laravel_schema_stub.php) and anything outside it is refused by
                // name rather than quietly accepted — which is how the first
                // version of this file failed on `composer db:migrate`. TINYINT
                // UNSIGNED tops out at 255 against a form that accepts 1–36
                // months and 0–30 days, so the smaller type is not a squeeze,
                // and widening the vocabulary for a column that fits the
                // existing one would be extending a guard to avoid reading it.
                $table->unsignedTinyInteger($column)->nullable()->comment($comment);
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
