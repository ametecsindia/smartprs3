<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Organisation;

/**
 * `access.reporting_cycle_detected` (R-XXII-044). A walk of the reporting graph
 * reached an employment it had already visited.
 *
 * A cycle is a data defect, never a licence to widen scope. The walk stops and
 * the caller denies: truncating quietly would hand a manager the reports of
 * whoever is above them in the loop, which is a privilege escalation dressed as
 * a graph bug. SmartPRS 2 has no protection here at all — its approval routing
 * follows `employees.reporting_manager_id` with no visited set and no depth
 * bound, so a two-employee loop hangs the request.
 *
 * Not an extension of Access\ScopeResolutionFailed, which is final and belongs
 * to the decision path. The scope compiler converts one into the other, so the
 * decider's single catch still denies (R-XXII-095), while a caller walking the
 * tree for an org chart sees the defect for what it is.
 */
final class ReportingCycleDetected extends \DomainException
{
}
