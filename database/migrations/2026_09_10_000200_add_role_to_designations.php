<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DESIGNATION CARRIES A ROLE AS WELL AS A TITLE.
 *
 * "Designation" (Collections Executive, Team Leader) is the official job
 * title; "Role" is the functional role it carries (Individual Contributor,
 * People Manager, Field, Support) — a second, separate word HR asked for on
 * the same screen. Nullable and editable through the existing Designations
 * module, so every designation already on file can have its Role filled in
 * without a second import.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('designations') && !Schema::hasColumn('designations', 'role')) {
            Schema::table('designations', function (Blueprint $table): void {
                $table->string('role')->nullable()
                    ->comment('the functional role this designation carries, alongside its title');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
