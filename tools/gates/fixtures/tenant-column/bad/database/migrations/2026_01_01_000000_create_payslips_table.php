<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** VIOLATING FIXTURE — SmartPRS 2's tenancy scope filters a column like this. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code');
            $table->date('period_start');
            $table->unique(['employee_code', 'period_start']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->string('component_code');
            $table->unique(['component_code']);
        });
    }
};
