<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMUNICATION: A MESSAGE IS QUEUED PER RECIPIENT, AND A NOTICE HAS A VALIDITY.
 *
 * SmartPRS 2 logged one row per broadcast with a recipient COUNT. Here a send
 * writes one row per recipient and channel, each with its own status, so the
 * log can answer "did this person get it" rather than "how many did we mean".
 * No transport is configured in this build: every row is queued, never sent,
 * and the screen says so. Greetings share the table under kind = 'greeting'.
 *
 * `notices` is new: SmartPRS 2 kept notices in a generic register with a
 * posted-on date only; a notice here has a validity window so the dashboard can
 * show the current ones and nothing that has lapsed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_log')) {
            Schema::table('messages_log', function (Blueprint $t): void {
                foreach ([
                    'kind' => static fn () => $t->string('kind', 32)->nullable()->comment('message | greeting'),
                    'employee_id' => static fn () => $t->unsignedBigInteger('employee_id')->nullable(),
                    'channel' => static fn () => $t->string('channel', 32)->nullable()->comment('email | whatsapp | sms'),
                    'to_address' => static fn () => $t->string('to_address')->nullable(),
                    'subject' => static fn () => $t->string('subject')->nullable(),
                    'status' => static fn () => $t->string('status', 32)->nullable()->comment('queued | sent | failed'),
                    'batch_id' => static fn () => $t->string('batch_id', 40)->nullable()->comment('one send = one batch'),
                    'sent_by_label' => static fn () => $t->string('sent_by_label', 128)->nullable(),
                ] as $column => $add) {
                    if (!Schema::hasColumn('messages_log', $column)) {
                        $add();
                    }
                }
            });
        }

        if (!Schema::hasTable('notices')) {
            Schema::create('notices', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->string('title'); // NOT NULL: a notice with no title is not one
                $t->text('body')->nullable();
                $t->date('posted_on')->nullable();
                $t->date('valid_from')->nullable();
                $t->date('valid_to')->nullable()->comment('blank = no expiry');
                $t->string('status', 64)->nullable()->comment('published | draft');
                $t->string('posted_by', 128)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
