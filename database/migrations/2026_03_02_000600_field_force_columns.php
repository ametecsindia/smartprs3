<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIELD FORCE registers at SmartPRS 2 parity.
 *
 * Off-roll agents belong to a branch as well as a company (the deployment is
 * per branch), and an agent's bank authorisation runs from a date, not only to
 * one. Both columns were absent from the ported tables. All nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('offroll_agents') && !Schema::hasColumn('offroll_agents', 'branch_id')) {
            Schema::table('offroll_agents', function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_id')->nullable()->comment('deployed at; blank = company-wide');
            });
        }

        if (Schema::hasTable('agent_authorizations') && !Schema::hasColumn('agent_authorizations', 'valid_from')) {
            Schema::table('agent_authorizations', function (Blueprint $table): void {
                $table->date('valid_from')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
