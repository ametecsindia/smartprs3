<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A REQUEST RAISED IS A REQUEST SOMEBODY IS TOLD ABOUT (owner, 8 Oct: "The
 * notifications to be updated in the bell icon as well as in the notifications
 * tab in details for any requests made by any employee — that should be
 * visible in the people to whom the other reports including HR Admin, company
 * admin, branch admins, etc.").
 *
 * WHO: everybody above the requester in the reporting line (every level), the
 * Branch Admins whose branches include the requester's branch, and every role
 * that reads the whole company (HR Admin, Company Admin, administrator — the
 * tenant's own Roles & Permissions reach decides which). Never the requester.
 *
 * One message per recipient, deduped per request AND recipient, so a re-post
 * tells nobody twice and nobody is skipped because a colleague was told first.
 */
final class RequestNotices
{
    public const EVENT = 'request.raised';

    /**
     * @param \Closure(string): string $reachOf role name => Scope::LINE|BRANCH|COMPANY
     * @param list<string> $roles every role this install declares
     * @param \Closure(string): list<string> $adminBranchesOf employee code => extra branch ids
     */
    public function __construct(
        private readonly RowStore $store,
        private readonly Notifications $notifications,
        private readonly \Closure $reachOf,
        private readonly array $roles,
        private readonly \Closure $adminBranchesOf,
    ) {
    }

    /**
     * Every recipient for a request raised by this employee: employee codes and
     * `role:<name>` addresses.
     *
     * @param array<string, mixed> $employee
     * @return list<string>
     */
    public function recipients(array $employee): array
    {
        $employees = [];

        foreach ($this->store->all('employees') as $e) {
            $employees[(string) ($e['id'] ?? '')] = $e;
        }

        $self = Employment::code($employee);
        $out = [];

        // THE WHOLE LINE UP, every level, stopping on a loop.
        $seen = [];
        $managerId = trim((string) ($employee['reporting_manager_id'] ?? ''));

        while ($managerId !== '' && !isset($seen[$managerId]) && isset($employees[$managerId])) {
            $seen[$managerId] = true;
            $out[] = Employment::code($employees[$managerId]);
            $managerId = trim((string) ($employees[$managerId]['reporting_manager_id'] ?? ''));
        }

        // BRANCH ADMINS OF THE REQUESTER'S BRANCH — their own branch or one they look after.
        $branch = trim((string) ($employee['branch_id'] ?? ''));

        if ($branch !== '') {
            foreach ($employees as $e) {
                $role = trim((string) ($e['role'] ?? ''));

                if ($role === '' || ($this->reachOf)($role) !== Scope::BRANCH
                    || strtolower((string) ($e['status'] ?? 'active')) === 'exited') {
                    continue;
                }

                $theirs = array_merge([trim((string) ($e['branch_id'] ?? ''))], ($this->adminBranchesOf)(Employment::code($e)));

                if (in_array($branch, $theirs, true)) {
                    $out[] = Employment::code($e);
                }
            }
        }

        // EVERY ROLE THAT READS THE WHOLE COMPANY.
        foreach ($this->roles as $role) {
            if (($this->reachOf)($role) === Scope::COMPANY) {
                $out[] = Notifications::ROLE_PREFIX . $role;
            }
        }

        return array_values(array_unique(array_filter($out, static fn (string $r): bool => $r !== '' && $r !== $self)));
    }

    /**
     * Tell everybody who should know. Returns how many messages were queued.
     *
     * @param array<string, mixed> $employee the requester's row
     * @param string $what what was raised, in words ("Leave request", "Salary advance")
     * @param string $kind the event kind suffix, routed by config/notification-routes.json
     * @param string $reference unique to this request (the row id)
     * @param string $details the request itself, in one line
     */
    public function notify(array $employee, string $what, string $kind, string $reference, string $details): int
    {
        $code = Employment::code($employee);

        if ($code === '') {
            return 0;
        }

        $queued = 0;

        foreach ($this->recipients($employee) as $to) {
            $queued += $this->notifications->queue(self::EVENT . '.' . $kind, $to,
                self::EVENT . ':' . $kind . ':' . $reference . ':' . $code . ':' . $to, [
                    'code' => $code,
                    'name' => trim((string) ($employee['name'] ?? '')),
                    'what' => $what,
                    'details' => $details,
                ]) ? 1 : 0;
        }

        return $queued;
    }
}
