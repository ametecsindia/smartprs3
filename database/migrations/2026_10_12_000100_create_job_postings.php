<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JOB POSTS (owner, 12 Oct): one per approved requisition — the text every job
 * portal asks for, and the list of portals it went live on (`channels`, JSON:
 * portal, portal's job ID, URL, posted on, expires on, live/closed). Until this
 * runs, the screen keeps posts in the file store and says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_postings')) {
            return;
        }

        Schema::create('job_postings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->unsignedBigInteger('job_id')->index();
            $t->string('title');
            $t->unsignedInteger('openings')->default(1);
            $t->string('engagement', 32)->nullable();
            $t->text('description')->nullable();
            $t->text('skills')->nullable();
            $t->decimal('experience_min', 18, 4)->nullable();
            $t->decimal('experience_max', 18, 4)->nullable();
            $t->decimal('ctc_min', 18, 4)->nullable();
            $t->decimal('ctc_max', 18, 4)->nullable();
            $t->string('show_salary', 3)->default('yes');
            $t->string('location')->nullable();
            $t->string('education')->nullable();
            $t->string('notice_period')->nullable();
            $t->string('industry')->nullable();
            $t->string('functional_area')->nullable();
            $t->string('contact_email')->nullable();
            $t->text('walk_in')->nullable();
            $t->string('status', 16)->default('draft');
            $t->text('channels')->nullable();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
