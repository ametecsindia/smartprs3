<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STATUTORY COMPLIANCE SIGN-OFF PER COMPANY (owner, 22 Oct 2026: "this application will be used by different
 * companies, they will have the authorised signatory or owner for the compliance"). Organization Configuration →
 * Companies → Statutory compliance sign-off; System Health's compliance check reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        foreach (['compliance_owner' => 'string', 'compliance_designation' => 'string', 'compliance_email' => 'string', 'compliance_verified_on' => 'date'] as $column => $type) {
            if (!Schema::hasColumn('companies', $column)) {
                Schema::table('companies', function (Blueprint $table) use ($column, $type): void {
                    $type === 'date' ? $table->date($column)->nullable() : $table->string($column, 190)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
