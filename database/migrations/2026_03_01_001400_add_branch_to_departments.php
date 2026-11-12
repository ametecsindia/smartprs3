<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DEPARTMENT BELONGS TO A BRANCH AS WELL AS TO A COMPANY.
 *
 * SmartPRS 2's Departments screen reads Company › Branch › Department › Head.
 * The ported table carried the company link and the head but no branch, so the
 * middle of that hierarchy could not be recorded. Nullable: a department that
 * spans branches is still a department.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('departments') && !Schema::hasColumn('departments', 'branch_id')) {
            Schema::table('departments', function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_id')->nullable()
                    ->comment('the branch this department sits in; null when it spans branches');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
