<?php

declare(strict_types=1);

/**
 * THE TEXT THAT WENT OUT.
 *
 * SmartPRS 2's `letters` table records that a letter of a given type was issued
 * on a given date and where its PDF was put. It does not record what the letter
 * SAID. That is the whole content of the document an employee keeps for twenty
 * years and shows to a bank and to their next employer, and it exists only as a
 * file on a disk that a re-deploy, a migration or a tidy-up can remove.
 *
 * Worse, the wording is recoverable only by re-rendering from today's template —
 * so an experience certificate signed in 2021 would come back in the words the
 * template uses now. That is an overwritten record wearing a disguise.
 *
 * `body` is the letter as issued. `issued_by` is who issued it, because the
 * question asked about a disputed letter is who signed it out.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letters') && !Schema::hasColumn('letters', 'body')) {
            Schema::table('letters', function (Blueprint $t): void {
                $t->longText('body')->nullable()
                    ->comment('the letter as it was issued — not re-rendered from the template later');
                $t->string('issued_by', 128)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped. Dropping `body` destroys the only copy of
        // every letter ever issued, and a rollback is not a reason to do that.
    }
};
