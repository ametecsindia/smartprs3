<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LETTER ACKNOWLEDGEMENTS (owner, 23 Oct 2026): an employee's "I acknowledge" on a letter that needs one
 * (NDA / Confidentiality undertaking and any template marked so). One row per letter acknowledged, kept as it
 * was signed — never edited or deleted. The NDA Sign-off report reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letter_acks')) {
            return;
        }

        Schema::create('letter_acks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->unsignedBigInteger('letter_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('letter_type', 64)->index();
            $t->dateTime('acknowledged_at')->nullable();
            $t->string('acknowledged_by')->nullable();
            $t->string('ip', 64)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a signature is never removed.
    }
};
