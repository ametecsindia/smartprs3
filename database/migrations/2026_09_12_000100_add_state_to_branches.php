<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A BRANCH CARRIES A STATE AS WELL AS A CITY.
 *
 * City alone cannot say which state's Professional Tax slab applies, and
 * that decision belongs in Organization Configuration — a live field an
 * admin sets once per branch — never a city-name guess baked into code
 * (see ComponentRules::PT_SLABS, which reads this column and refuses rather
 * than guesses when it is blank or not yet wired for that state). Nullable
 * and editable through the existing Branches screen, so every branch
 * already on file can have its State filled in without a second import.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'state')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->string('state')->nullable()
                    ->comment('the Indian state this branch sits in, for state-specific statutory rules (Professional Tax)');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
