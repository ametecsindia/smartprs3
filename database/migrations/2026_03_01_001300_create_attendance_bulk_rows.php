<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A MONTH OF ATTENDANCE TYPED INTO A SPREADSHEET, WAITING FOR SOMEBODY TO APPROVE IT.
 *
 * SmartPRS 2 kept these rows in `attendance_pending`, a table it CREATED AT
 * REQUEST TIME from inside the controller (rev 82) — which is why no reading of
 * its migrations ever found it, and why the ported `attendance_pending` here has
 * a different shape (it is the device-push queue). This is its own table because
 * a bulk row is not a punch: it is a CLAIM about a day — code, date, in, out —
 * that becomes two punches only when somebody with `attendance.bulk.decide`
 * approves the batch. Keeping claims and punches in one table is how a rejected
 * upload gets paid.
 *
 * NOTHING HERE IS DELETED. A row an uploader removes before approval is marked
 * `withdrawn`; a row the parser could not read is kept with its `error` so the
 * uploader can see which line and why. The unique index makes re-uploading the
 * same file a second batch rather than a silent overwrite of the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_bulk_rows')) {
            return;
        }

        Schema::create('attendance_bulk_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('batch', 40)->comment('one upload; approved or rejected as a whole');
            $table->unsignedInteger('line_no')->comment('the line of the uploaded file, so an error names it');

            $table->string('emp_code', 64)->nullable()
                ->comment('the employment RESOLVED from the file; null when nobody matched');
            $table->string('given_code', 128)->nullable()->comment('what the file said, verbatim');
            $table->string('given_name', 255)->nullable()->comment('what the file said, verbatim');
            $table->date('log_date')->nullable();
            $table->string('in_time', 5)->nullable()->comment('HH:MM in the tenant business timezone');
            $table->string('out_time', 5)->nullable();

            $table->string('status', 64)->comment('pending, error, approved, rejected, withdrawn');
            $table->string('error', 300)->nullable();
            $table->string('uploaded_by', 255)->nullable();
            $table->string('decided_by', 255)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'batch', 'line_no'], 'attendance_bulk_rows_line');
            $table->index(['tenant_id', 'status'], 'attendance_bulk_rows_status');
        });
    }

    public function down(): void
    {
        // Deliberately not dropped: an approved batch is the evidence behind the
        // punches it wrote, and a rollback is not a reason to lose it.
    }
};
