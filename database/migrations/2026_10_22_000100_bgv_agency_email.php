<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE AGENCY'S EMAIL ON THE VERIFICATION (owner, 22 Oct 2026: "beside Agency name, add another box for
 * Agency email ID"). Nullable — older rows take it when next saved or sent to an agency.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bgv') || Schema::hasColumn('bgv', 'agency_email')) {
            return;
        }

        Schema::table('bgv', function (Blueprint $table): void {
            $table->string('agency_email', 500)->nullable()->comment('Verification agency email(s), comma-separated');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
