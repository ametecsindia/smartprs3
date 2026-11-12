<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CLEAN FIXTURE — tenancy is structural; the platform table is allow-listed. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unique(['slug']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code');
            $table->date('period_start');
            $table->unique(['tenant_id', 'employee_code', 'period_start']);
        });
    }
};
