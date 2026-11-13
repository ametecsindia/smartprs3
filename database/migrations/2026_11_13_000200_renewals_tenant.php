<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SELF-SERVICE RENEWAL (owner, 13 Nov 2026 — SmartPRS 2 TenantBillingController): a renewal belongs to its workspace,
 * so a subscriber only ever sees and completes its own (Law 6 — tenancy in the database).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('renewals') && !Schema::hasColumn('renewals', 'tenant_id')) {
            Schema::table('renewals', static function (Blueprint $t): void {
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
