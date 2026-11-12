<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CLEAN FIXTURE — DDL is legal here, and only here. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('entity');
            $table->string('field_key');
            $table->unique(['tenant_id', 'entity', 'field_key']);
        });
    }
};
