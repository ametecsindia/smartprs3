<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * SALARY TYPE IS SALARY, SALARY + COMMISSION OR COMMISSION (owner, 3 Nov 2026: "Change it at the source so that the
 * dropdowns are updated everywhere"). The employee field now offers exactly these three; this renames what was saved
 * as "Salary & Commission" so every record matches its dropdown. A scheme's Salary Type follows: "All" becomes blank
 * (= all salary types); an old pair ("Commission + Salary & Commission") is left as it is and keeps paying both
 * (Compensation::salaryTypesOf) until it is re-saved with one of the three.
 *
 * Plain UPDATE statements pushed onto Schema::$statements: tools/db/migrate.php runs the collected SQL and has no
 * DB facade (a DB:: call here stopped every migration run, 5 Nov 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'salary_type')) {
            Schema::$statements[] = "UPDATE `employees` SET `salary_type` = 'Salary + Commission'"
                . " WHERE `salary_type` IN ('Salary & Commission', 'Salary and Commission')";
        }

        if (Schema::hasTable('incentive_schemes') && Schema::hasColumn('incentive_schemes', 'eligible_salary_types')) {
            Schema::$statements[] = "UPDATE `incentive_schemes` SET `eligible_salary_types` = 'Salary + Commission'"
                . " WHERE `eligible_salary_types` = 'Salary & Commission'";
            Schema::$statements[] = "UPDATE `incentive_schemes` SET `eligible_salary_types` = NULL"
                . " WHERE `eligible_salary_types` = 'All'";
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: "Salary & Commission" is no longer a Salary Type anywhere in the application.
    }
};
