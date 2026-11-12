<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EMPLOYEES-TAB AUDIT (10 Sep): the columns SmartPRS 2's screens write that the
 * ported tables lacked. documents.deleted_at — a document is retired, never
 * erased (Law 3). offroll_agents — PRS2's KYC modal: email and its
 * verification, bank details, UPI, and the six document slots (photo, ID proof,
 * PAN, DRA certificate, police clearance, agreement) as file paths, plus the
 * public live-earnings token. All nullable; nothing existing changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = [
            'documents' => ['deleted_at' => 'datetime'],
            'offroll_agents' => ['email' => 'string', 'email_verified_at' => 'datetime', 'mobile_verified' => 'bool', 'bank_details' => 'string', 'upi' => 'string',
                'doc_photo' => 'string', 'doc_id_proof' => 'string', 'doc_pan' => 'string', 'doc_dra' => 'string', 'doc_pcc' => 'string', 'doc_agreement' => 'string',
                'earnings_token' => 'string'],
        ];

        foreach ($add as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column => $kind) {
                    if (Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    match ($kind) {
                        'datetime' => $table->dateTime($column)->nullable(),
                        'bool' => $table->boolean($column)->nullable(),
                        default => $table->string($column, 191)->nullable(),
                    };
                }
            });
        }
    }

    public function down(): void
    {
        // Columns are kept: a rollback that drops a column drops what people typed into it.
    }
};
