<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A REVIEW RATED ON THE TENANT'S OWN SCALE (21 Sep 2026, owner). Performance
 * Appraisal lets each company / branch / department … choose how its people
 * are rated — rating labels (S, M, A, R, T), exact numbers, or any number
 * inside slab ranges (4.6) — and the review must keep the rating exactly as
 * given. The integer columns (self_rating, manager_rating, final_rating) stay
 * and are still filled whenever the rating is a whole number, so nothing that
 * reads them changes; these hold the rating as given, and which appraisal
 * scale it was given on.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'self_rating_value' => 64,
        'manager_rating_value' => 64,
        'final_rating_value' => 64,
        'rating_format' => 16,
        'appraisal_key' => 32,
        'appraisal_box_id' => 32,
    ];

    public function up(): void
    {
        if (!Schema::hasTable('performance_reviews')) {
            return;
        }

        Schema::table('performance_reviews', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column => $length) {
                if (!Schema::hasColumn('performance_reviews', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: a column somebody has filled is not undone by a rollback.
    }
};
