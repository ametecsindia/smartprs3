<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMPANY'S NOTICE PERIOD (owner, 22 Oct 2026: "In Organization Configuration - Company add another period -
 * Notice Period (# days)"). A resignation's last working day = its start date + these days, unless the employee's
 * own Notice Period (Days) is set; blank falls back to config/exits.json default_notice_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || Schema::hasColumn('companies', 'notice_period_days')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedInteger('notice_period_days')->nullable()->comment('Notice period in days for resignations');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
