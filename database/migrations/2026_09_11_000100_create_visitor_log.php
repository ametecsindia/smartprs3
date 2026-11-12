<?php

declare(strict_types=1);

/**
 * VISITOR / CLIENT / GUEST LOG (11 Sep) — the owner asked for an actual entry
 * form (name, mobile, phone, address, purpose of visit) behind the Visitor ID
 * card, not just a static "Visitor Name" template, plus room for more card
 * TYPES than Visitor (Client, Guest, ...). Real, repeated, growing data — one
 * row inserted per visit — so it is a real table, not another version of a
 * Customisation blob (that store is for occasional CONFIGURATION changes, a
 * handful of versions; a check-in log would mean rewriting the whole growing
 * history as one bigger JSON payload on every single visit).
 *
 * `type_id` is the external card type's own id (the built-in Visitor
 * sentinel, or one minted for an owner-added type under `id-card-external-
 * types`); `type_label` is that type's label AT THE TIME of this visit,
 * copied in rather than looked up, so renaming or removing a type later never
 * rewrites what a past visitor's card said.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('visitor_log')) {
            Schema::create('visitor_log', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('type_id', 64)->nullable();
                $t->string('type_label', 80)->nullable();
                $t->string('name')->nullable();
                $t->string('mobile', 32)->nullable();
                $t->string('phone', 32)->nullable();
                $t->string('address')->nullable();
                $t->string('purpose')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
