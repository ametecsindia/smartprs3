<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERFORMANCE & REWARDS and LEARNING & KNOWLEDGE at SmartPRS 2 parity.
 *
 * Two PRS2 registers were never ported (awards, policies); the ported tables
 * lack the columns their PRS2 screens carry (a helpdesk ticket's description
 * and HR reply, a test attempt's answers and marks, a review's self / manager /
 * final ratings, which code-of-conduct version an employee acknowledged). All
 * nullable; every new table carries the tenant FK like the ported ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('awards')) {
            Schema::create('awards', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->unsignedBigInteger('employer_company_id')->nullable(); // which employer WITHIN the tenant, never tenancy
                $t->unsignedBigInteger('employee_id'); // NOT NULL: an award is somebody's
                $t->string('award')->nullable();
                $t->date('date')->nullable();
                $t->string('note')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        if (!Schema::hasTable('policies')) {
            Schema::create('policies', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete(); // NOT NULL, real FK, RESTRICT
                $t->string('title')->nullable();
                $t->string('category', 64)->nullable();
                $t->string('version', 32)->nullable();
                $t->string('owner')->nullable();
                $t->date('effective_date')->nullable();
                $t->date('board_approved_on')->nullable();
                $t->date('review_due')->nullable();
                $t->boolean('ack_required')->nullable();
                $t->string('reference')->nullable();
                $t->text('summary')->nullable();
                $t->string('status', 64)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }

        $added = [
            'helpdesk' => ['description' => 'text', 'hr_comment' => 'text', 'resolved_at' => 'datetime'],
            'test_attempts' => ['answers' => 'text', 'total_marks' => 'int', 'marks_scored' => 'int', 'submitted_at' => 'datetime'],
            'code_of_conduct_ack' => ['version' => 'string', 'acknowledged_by' => 'string', 'acknowledged_at' => 'datetime'],
            'performance_reviews' => ['reviewer_id' => 'id', 'self_rating' => 'int', 'manager_rating' => 'int',
                'final_rating' => 'int', 'self_comment' => 'text', 'manager_comment' => 'text',
                'submitted_at' => 'datetime', 'finalised_at' => 'datetime', 'finalised_by' => 'string'],
            'training_programs' => ['description' => 'text'],
        ];

        foreach ($added as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                foreach ($columns as $column => $type) {
                    if (!Schema::hasColumn($table, $column)) {
                        match ($type) {
                            'int' => $t->integer($column)->nullable(),
                            'id' => $t->unsignedBigInteger($column)->nullable(),
                            'text' => $t->text($column)->nullable(),
                            'datetime' => $t->dateTime($column)->nullable(),
                            default => $t->string($column)->nullable(),
                        };
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Additive only: nothing is dropped (law 3).
    }
};
