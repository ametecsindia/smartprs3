<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMPLAINT RESPONSES (owner, 13 Oct): every HR / Admin reply to an employee's
 * complaint, one row per reply with the status it set — appended, never
 * overwritten. Until this runs, the replies are kept in the file store and the
 * Complaints screen says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('complaint_responses')) {
            return;
        }

        Schema::create('complaint_responses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->unsignedBigInteger('complaint_id')->index();
            $t->string('status', 64)->nullable();
            $t->text('response')->nullable();
            $t->string('responded_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
