<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPRAISAL RATINGS ON THE INCREMENT REGISTER (owner, 8 Oct): the rating
 * import on Increment / Appraisal and its export (previous CTC, rating, % per
 * rating, amount increased, CTC after appraisal) need to know, per increment,
 * which rating it came from, which Performance Appraisal criteria (key) and
 * which review period. Nullable; a hand-typed increment leaves them blank.
 */
return new class extends Migration
{
    private const COLUMNS = ['rating' => 64, 'appraisal_key' => 32, 'appraisal_box_id' => 32, 'review_period' => 16];

    public function up(): void
    {
        if (!Schema::hasTable('increments')) {
            return;
        }

        Schema::table('increments', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column => $length) {
                if (!Schema::hasColumn('increments', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: nothing is overwritten.
    }
};
