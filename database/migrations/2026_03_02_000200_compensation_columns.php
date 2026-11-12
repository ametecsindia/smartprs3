<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMPENSATION & CLAIMS PARITY COLUMNS.
 *
 * SmartPRS 2's scheme form records how a scheme pays (percent / fixed / open,
 * a rate, a TDS rate, a target gate), whom it applies to (company / branch /
 * department / team), a validity window and per-person caps; its commission
 * entry records the purpose, the collection evidence, gross and TDS beside the
 * net; a loan records the month its EMIs start; an increment may carry a new
 * designation. The ported tables knew none of these. All nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = static function (string $table, array $columns): void {
            if (!Schema::hasTable($table)) {
                return;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                foreach ($columns as $column => $type) {
                    if (!Schema::hasColumn($table, $column)) {
                        match ($type) {
                            'int' => $t->integer($column)->nullable(),
                            'id' => $t->unsignedBigInteger($column)->nullable(),
                            'text' => $t->text($column)->nullable(),
                            'decimal' => $t->decimal($column, 18, 4)->nullable(),
                            'date' => $t->date($column)->nullable(),
                            default => $t->string($column)->nullable(),
                        };
                    }
                }
            });
        };

        $add('incentive_schemes', [
            'purpose' => 'string', 'rate_type' => 'string', 'rate_value' => 'decimal',
            'tds_rate' => 'decimal', 'threshold' => 'decimal', 'payout_method' => 'string',
            'valid_from' => 'date', 'valid_till' => 'date', 'max_claims' => 'int',
            'max_amount' => 'decimal', 'employer_company_id' => 'id', 'branch_id' => 'id',
            'department_id' => 'id', 'team_id' => 'id', 'notes' => 'text',
        ]);
        $add('commissions', [
            'claim_type' => 'string', 'purpose' => 'string', 'scheme_id' => 'id',
            'customer_name' => 'string', 'account_ref' => 'string', 'collection_type' => 'string',
            'collection_mode' => 'string', 'gross_amount' => 'decimal', 'tds_rate' => 'decimal',
            'tds_amount' => 'decimal', 'payout_date' => 'date', 'payout_method' => 'string',
        ]);
        $add('loans', ['start_month' => 'string']);
        $add('increments', ['new_designation' => 'string']);
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
