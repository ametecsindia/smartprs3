<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spine Law 6, S16.
 *
 * PLATFORM-SCOPED SET (1 of 2 in the whole foundation): `tenants` has no
 * tenant_id because it *is* the tenant. Everything else in this migration and
 * every business table thereafter carries tenant_id NOT NULL.
 *
 * `tenants.status_code` is a plain code column with no foreign key: status
 * vocabularies are tenant-scoped data (see the status migration) and a tenant's
 * own lifecycle is owned by the platform, not by its own configuration. It is
 * still not an ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->binary('uuid', length: 16, fixed: true)->comment('UUIDv7, the only public identifier (V.7)');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('status_code', 64)->comment('platform lifecycle: provisioning|active|suspended|closed');
            $table->string('business_timezone', 64)->default('Asia/Kolkata');
            $table->char('currency_code', 3)->default('INR');
            $table->string('locale', 16)->default('en_IN');
            $table->date('onboarded_on')->nullable();
            $table->dateTime('provisioned_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['uuid'], 'tenants_uuid_unique');
            $table->unique(['code'], 'tenants_code_unique');
            $table->index(['status_code'], 'tenants_status_code_index');
        });

        Schema::create('installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->binary('uuid', length: 16, fixed: true);
            $table->string('code', 64);
            $table->string('deployment_kind_code', 32)->comment('saas|private_cloud|on_premise — data, never an ENUM');
            $table->string('edition_code', 32);
            $table->string('app_version', 32)->nullable();
            $table->char('licence_key_hash', 64)->nullable()->comment('hashed: it is compared, never decrypted (S13)');
            $table->unsignedInteger('licensed_seats')->nullable();
            $table->string('public_key_id', 64)->nullable()->comment('the Ametecs key this install verifies packs with (S12)');
            $table->date('activated_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->dateTime('scheduler_heartbeat_at')->nullable();
            $table->dateTime('queue_heartbeat_at')->nullable();
            $table->dateTime('last_health_check_at')->nullable();
            $table->dateTime('last_restore_test_at')->nullable()->comment('S18: visible in the UI, or the backup is a rumour');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['tenant_id', 'uuid'], 'installations_tenant_uuid_unique');
            $table->unique(['tenant_id', 'code'], 'installations_tenant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
        Schema::dropIfExists('tenants');
    }
};
