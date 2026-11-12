<?php

declare(strict_types=1);

/**
 * WHERE A CUSTOMER'S OWN VERSION OF A MODULE LIVES.
 *
 * `config/modules.json` is what SmartPRS 3 ships. This table is what a customer
 * changed about it — a field renamed into their words, a dropdown given the
 * values they actually use, a column added to the list, a field they never fill
 * in hidden. It is the difference between "change configuration, not code" as a
 * slogan and as something an administrator can do on a Tuesday afternoon.
 *
 * IT IS VERSIONED AND APPEND-ONLY, like every other record of a decision here.
 * A customisation is a change to what a screen MEANS, so "who widened the salary
 * field, and what did it say before?" is a question that gets asked — usually
 * once something has gone wrong, which is exactly when an overwritten row has
 * nothing to say. Each save writes a new version; the highest version wins.
 *
 * IT IS TENANT-SCOPED, structurally. One customer's labels on another customer's
 * screens would be a cosmetic leak that tells them the other exists.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('module_customisations')) {
            Schema::create('module_customisations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $t->string('module_key', 64);
                $t->unsignedInteger('version');
                $t->longText('payload')->comment('the overrides as JSON — only the keys that differ from the shipped declaration');
                $t->string('changed_by_id', 64)->nullable();
                $t->string('changed_by', 128)->nullable();
                $t->string('note', 255)->nullable()->comment('why, in the words of whoever changed it');
                $t->timestamps();
                $t->unique(['tenant_id', 'module_key', 'version'], 'module_customisations_version_unique');
                $t->index(['tenant_id', 'module_key'], 'module_customisations_module_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_customisations');
    }
};
