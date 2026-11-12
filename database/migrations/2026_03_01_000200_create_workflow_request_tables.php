<?php

declare(strict_types=1);

/**
 * REQUESTS AND THEIR DECISIONS.
 *
 * SmartPRS 2 keeps approvals inside each feature's own table — a leave row
 * carries who approved it — so "what is waiting on me?" cannot be answered
 * without querying every feature separately, and a chain longer than one step
 * has nowhere to live.
 *
 * Here a request is its own row pointing AT a subject, and every decision is
 * appended rather than overwriting the last. That is what makes "who approved
 * this, and had anyone rejected it first?" answerable — the question asked when
 * something has gone wrong.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workflow_requests')) {
            Schema::create('workflow_requests', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('workflow', 64);
                $t->string('subject_table', 64);
                $t->string('subject_id', 64)->nullable();
                $t->decimal('amount', 18, 4)->default(0)
                    ->comment('what the chain routes on — days for leave, rupees for money');
                $t->string('note', 500)->nullable();
                $t->string('state', 32)->comment('open|approved|rejected');
                $t->string('at_step', 64)->nullable()->comment('null once closed');
                $t->string('raised_by_id', 64);
                $t->string('raised_by', 255);
                $t->dateTime('raised_at')->nullable();
                $t->dateTime('closed_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'state', 'at_step'], 'workflow_requests_inbox_idx');
            });
        }

        if (!Schema::hasTable('workflow_decisions')) {
            Schema::create('workflow_decisions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('request_id', 64);
                $t->string('step', 64);
                $t->string('step_label', 255);
                $t->string('outcome', 32)->comment('approved|rejected');
                $t->string('comment', 1000)->nullable();
                $t->string('decided_by_id', 64);
                $t->string('decided_by', 255);
                $t->dateTime('decided_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'request_id'], 'workflow_decisions_request_idx');
            });
        }
    }
};
