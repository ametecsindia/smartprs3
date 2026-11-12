<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE REST OF SMARTPRS 2'S SAAS PLATFORM MENU (owner, 16 Oct 2026: "build everything as available in
 * SmartPRS2"). All platform-level — no tenant_id on any of them.
 *
 * - `platform_settings`: one JSON value per key — the platform mailbox (SMTP), the Interakt key, the
 *   demo passkey window, the landing-page edits. (SmartPRS 2: `app_settings` row with tenant 0.)
 * - `wa_templates`: the platform's WhatsApp template registry (create → submit in Interakt → test).
 * - `wa_log`: every WhatsApp send attempt and its answer.
 * - `demo_requests`: the passkey-gated live-demo register (/demo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $t): void {
                $t->id();
                $t->string('ckey', 64);
                $t->longText('value')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['ckey'], 'platform_settings_ckey_uq');
            });
        }

        if (!Schema::hasTable('wa_templates')) {
            Schema::create('wa_templates', function (Blueprint $t): void {
                $t->id();
                $t->string('purpose', 20)->default('custom');   // welcome|payment|renewal|lead|otp|custom
                $t->string('name', 120);                         // the EXACT Interakt template name
                $t->string('language', 10)->default('en');
                $t->string('category', 20)->default('utility'); // utility|marketing|authentication
                $t->text('body')->nullable();                    // with {{1}}..{{n}}
                $t->text('sample_values')->nullable();           // comma-separated
                $t->integer('var_count')->default(0);
                $t->string('status', 20)->default('draft');     // draft|submitted|approved|rejected
                $t->dateTime('last_test_at')->nullable();
                $t->text('last_error')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('wa_log')) {
            Schema::create('wa_log', function (Blueprint $t): void {
                $t->id();
                $t->string('mobile', 20)->nullable();
                $t->string('template', 120)->nullable();
                $t->text('body_values')->nullable();
                $t->string('kind', 40)->nullable();
                $t->string('status', 20)->default('failed');    // sent | failed
                $t->text('error')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('demo_requests')) {
            Schema::create('demo_requests', function (Blueprint $t): void {
                $t->id();
                $t->string('name', 120);
                $t->string('phone', 20);
                $t->string('email', 160);
                $t->string('company', 160)->nullable();
                $t->string('entry', 10)->default('demo');
                $t->string('pin', 12);
                $t->string('status', 12)->default('active');    // active | expired | revoked
                $t->dateTime('expires_at');
                $t->dateTime('last_login_at')->nullable();
                $t->dateTime('wiped_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->index(['phone'], 'demo_requests_phone_ix');
                $t->index(['pin'], 'demo_requests_pin_ix');
            });
        }
    }

    public function down(): void
    {
        foreach (['demo_requests', 'wa_log', 'wa_templates', 'platform_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
