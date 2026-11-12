<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASSETS — ISSUE & RETURN BECOMES THE ONE SCREEN (10 Sep): the separate
 * Assets register menu item is retired and its facts move into the Issue &
 * Return screen, which needed three columns the register never declared.
 *
 * `asset_name` — the item's own name/description, alongside `asset_type`
 * (its category, e.g. "Laptop") and `asset_id` (its code, e.g. "AST-014");
 * this build never had a name distinct from the category.
 *
 * `purpose` — why it was issued, free text, stamped at issue time.
 *
 * `damage_resolution` — the outcome once `condition` is 'damaged': waived
 * off, cost recovered, replaced, written off (config/assets.json declares
 * the words). Nullable, additive, no data moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assets')) {
            Schema::table('assets', function (Blueprint $t): void {
                foreach (['asset_name' => 'string', 'purpose' => 'string', 'damage_resolution' => 'string'] as $column => $type) {
                    if (!Schema::hasColumn('assets', $column)) {
                        $t->{$type}($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
