<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPANY'S STATUTORY IDENTITY (owner, 14 Oct): the TAN and the person responsible for TDS — printed
 * on Form 130 (old Form 16), the quarterly return (Form 138, old 24Q) and challans — and the PF
 * establishment code and ESIC employer code its remittances are filed under. Set per company under
 * Organization Configuration → Companies. Nullable: every company on file fills them in when it can.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        foreach ([
            'tan' => 10, 'tds_person_name' => 120, 'tds_person_father' => 120, 'tds_person_designation' => 120,
            'pf_establishment_code' => 32, 'esic_employer_code' => 32,
        ] as $column => $length) {
            if (!Schema::hasColumn('companies', $column)) {
                Schema::table('companies', function (Blueprint $table) use ($column, $length): void {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
