<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * USER MANAGEMENT AS SMARTPRS 2 HAD IT: an account can be DISABLED (on exit) and
 * enabled again, and the moment is recorded. `users.status` carries the role in
 * the ported schema, so disabling is its own nullable timestamp rather than a
 * status value that would have erased the role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dateTime('disabled_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
