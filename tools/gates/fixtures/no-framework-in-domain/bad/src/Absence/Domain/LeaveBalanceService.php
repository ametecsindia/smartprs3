<?php
declare(strict_types=1);

namespace SmartPRS\Absence\Domain;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** VIOLATING FIXTURE — SmartPRS 2 has no domain layer; this is why. */
final class LeaveBalanceService
{
    public function balance(Request $request): array
    {
        $rows = DB::table('leave_ledgers')
            ->where('employee_id', $request->input('employee_id'))
            ->get();

        return [$rows, Model::class];
    }
}
