<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN ASSET SITS AT A BRANCH (owner, 29 Sep): "In the Asset Stock entry, add
 * columns related to Company, Branch". `employer_company_id` came with the
 * port; the branch is new. Nullable — stock entered before this has no branch
 * until somebody sets it, and guessing one would put a laptop in the wrong
 * office's count.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('assets') || Schema::hasColumn('assets', 'branch_id')) {
            return;
        }

        Schema::table('assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable()->comment('Branch the item is held at (branches.id); NULL = not set');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
