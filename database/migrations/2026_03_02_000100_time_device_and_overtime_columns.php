<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIME & ATTENDANCE PARITY COLUMNS.
 *
 * SmartPRS 2's device registry (DEVICE_FIELDS, shared by Biometric Devices and
 * ZKTeco Devices) records make, model, verification type, connection method,
 * LAN address and a free note; its Biometric Device Setup records a serial,
 * a sync frequency, a branch and a generic-API mapping; its Overtime Register
 * is scoped to a company. The ported tables knew none of these. All nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = static function (string $table, array $columns): void {
            if (!Schema::hasTable($table)) {
                return;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $columns): void {
                foreach ($columns as $column => $type) {
                    if (!Schema::hasColumn($table, $column)) {
                        match ($type) {
                            'int' => $t->integer($column)->nullable(),
                            'id' => $t->unsignedBigInteger($column)->nullable(),
                            'text' => $t->text($column)->nullable(),
                            default => $t->string($column)->nullable(),
                        };
                    }
                }
            });
        };

        $add('devices', [
            'brand' => 'string', 'model' => 'string', 'device_type' => 'string',
            'connect_mode' => 'string', 'ip_address' => 'string', 'port' => 'int',
            'notes' => 'text',
        ]);
        $add('biometric_configs', [
            'branch_id' => 'id', 'serial_number' => 'string', 'sync_interval_min' => 'int',
            'api_config' => 'text',
        ]);
        $add('overtime', ['employer_company_id' => 'id']);
    }

    public function down(): void
    {
        // Deliberately not dropped.
    }
};
