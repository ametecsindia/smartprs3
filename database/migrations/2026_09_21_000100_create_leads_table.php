<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEMO-REQUEST LEADS FROM THE PUBLIC LANDING PAGE (public/landing.php).
 *
 * Platform-level, not tenant data — a visitor submitting this form is not a
 * tenant yet, so this table is read/written through an UNSCOPED store
 * (`new SqlStore($pdo)` without `forTenant()`), the same pattern
 * tools/seed-local-admin.php already uses for `tenants`/`users` before a
 * tenant exists. Nothing here is scoped by Law 6 because there is no tenant
 * to scope it to.
 *
 * Read by the future Super Admin "Leads" screen (replicating SmartPRS2's
 * admin/leads.blade.php + LeadController) — not built yet; this migration
 * only lays the table down so the landing page's form has somewhere to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leads')) {
            return;
        }

        Schema::create('leads', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('company', 160);
            $t->string('designation', 120)->nullable();
            $t->string('city', 120);
            $t->string('mobile', 20);
            $t->string('email', 160);
            $t->string('employees_range', 40)->nullable();
            $t->text('challenges')->nullable();
            $t->string('source', 40)->default('landing');
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
