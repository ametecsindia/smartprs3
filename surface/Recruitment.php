<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * REQUISITION → PIPELINE → HIRE, on the ported tables.
 *
 * SmartPRS 2's `RecruitmentController` (rev 60, 65 KB, 32 routes): raise a
 * manpower requisition, an administrator approves it, candidates are added and
 * linked to it, moved through stages, interviewed, and hired — which creates the
 * employee and fills the requisition. Requisitions live in `job_openings`,
 * candidates in `recruitment`, interviews in `interviews`; all three are ported.
 *
 * WHAT IS DIFFERENT, and why each costs SmartPRS 2 something real.
 *
 * 1. THE STAGES ARE DECLARED (config/recruitment.json), not constants. A tenant
 *    who screens in two rounds edits a list; in SmartPRS 2 it is a release.
 *
 * 2. A CANDIDATE MOVES ONE STEP AT A TIME, forward or back, and `hired` is not a
 *    stage anybody moves to — it is the result of `hire()`. SmartPRS 2 lets
 *    `moveStage` set `hired` directly, which is a hired candidate with no
 *    employee, no filled count and no joining date.
 *
 * 3. A HIRE NEVER LOOKS UP THE EMPLOYEE BY NAME. SmartPRS 2 checks
 *    `employees.name = candidate.name` to avoid a duplicate, which links a new
 *    joiner to a namesake who left in 2023. Here a hire is refused when the
 *    candidate is already hired, and refused when the code given is taken.
 *
 * 4. NOTHING IS DELETED. SmartPRS 2 deletes candidates, interviews and
 *    requisitions; a deleted candidate is a hire nobody can trace. A candidate is
 *    rejected with a reason, a requisition is closed, an interview is cancelled.
 *
 * 5. THE APPROVER IS A PERMISSION (recruitment.requisition.approve is not in the
 *    catalogue; `recruitment.pipeline.publish` is the approving act), never a
 *    `canApprove()` that returns TRUE on exception — SmartPRS 2's does, "availability
 *    over strictness", which is Law 5 inverted.
 *
 * Job-portal imports (12 Oct): import() reads a portal's own download — its
 * column names (config import_columns), its salary and experience text, and
 * skips people already here. Job posts and portal postings: JobPostings.
 */
final class Recruitment
{
    public const REQUISITIONS = 'job_openings';
    public const CANDIDATES = 'recruitment';
    public const INTERVIEWS = 'interviews';

    public const OPEN = 'open';
    public const CLOSED = 'closed';

    private ?array $declared = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        // Company Admin / HR Admin approve finally at any step (config/approval-authority.json).
        private readonly ?ApprovalAuthority $authority = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $d = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($d) || !is_array($d['stages'] ?? null) || count($d['stages']) < 2
            || !is_array($d['terminal'] ?? null) || !is_array($d['hire_from'] ?? null)) {
            throw new \RuntimeException("{$this->file} does not declare a hiring pipeline (stages, "
                . 'terminal, hire_from). Nothing moves until it does.');
        }

        return $this->declared = $d;
    }

    /** @return list<string> */
    public function stages(): array
    {
        return array_values(array_map('strval', $this->declared()['stages']));
    }

    // ------------------------------------------------------------ requisitions

    /** @return list<array<string, mixed>> newest first */
    public function requisitions(): array
    {
        $rows = $this->store->all(self::REQUISITIONS);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    public function requisition(string $id): ?array
    {
        return $this->store->find(self::REQUISITIONS, $id);
    }

    /**
     * Raise one. It starts PENDING and is not recruitable until approved.
     *
     * @return array{ok: bool, why: string, requisition?: array<string, mixed>}
     */
    public function raise(array $input, string $by): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $openings = (int) ($input['openings'] ?? 0);

        if ($title === '') {
            return ['ok' => false, 'why' => 'A requisition needs a role title.'];
        }

        if ($openings < 1) {
            return ['ok' => false, 'why' => 'A requisition is for at least one opening.'];
        }

        // THE ROLE IS A DESIGNATION FROM ORGANIZATION CONFIGURATION (owner, 12 Oct): no typed
        // titles, no near-duplicates. Stored with the configured spelling. An installation with
        // no designations set up yet keeps the typed title.
        $designationId = $this->idByName('designations', $title);

        if ($designationId === null && $this->hasRows('designations')) {
            return ['ok' => false, 'why' => "'{$title}' is not a designation in Organization Configuration. "
                . 'Pick one from the list, or add it under Organization Configuration → Designations first.'];
        }

        $title = $designationId === null ? $title : (string) $this->nameById('designations', $designationId);

        $d = $this->declared()['requisition'];

        foreach (['priority', 'engagement'] as $field) {
            $value = (string) ($input[$field] ?? '');

            if ($value !== '' && !in_array($value, (array) ($d[$field] ?? []), true)) {
                return ['ok' => false, 'why' => "'{$value}' is not a declared {$field}."];
            }
        }

        foreach (['ctc_min', 'ctc_max'] as $money) {
            $value = trim((string) ($input[$money] ?? ''));

            if ($value !== '' && preg_match('/^\d+(\.\d{1,4})?$/', $value) !== 1) {
                return ['ok' => false, 'why' => "'{$value}' is not an amount. Digits and up to four decimals."];
            }
        }

        $target = trim((string) ($input['target_date'] ?? ''));

        if ($target !== '' && !self::isDate($target)) {
            return ['ok' => false, 'why' => "'{$target}' is not a date (Y-m-d)."];
        }

        $row = $this->store->put(self::REQUISITIONS, [
            'req_code' => $this->nextCode(),
            'title' => $title,
            'employer_company_id' => $this->idByName('companies', (string) ($input['company'] ?? '')),
            'department' => trim((string) ($input['department'] ?? '')) ?: null,
            'location' => trim((string) ($input['location'] ?? '')) ?: null,
            'openings' => $openings,
            'filled' => 0,
            'ctc_min' => trim((string) ($input['ctc_min'] ?? '')) ?: null,
            'ctc_max' => trim((string) ($input['ctc_max'] ?? '')) ?: null,
            'engagement' => (string) ($input['engagement'] ?? '') ?: null,
            'priority' => (string) ($input['priority'] ?? '') ?: null,
            'target_date' => $target ?: null,
            'justification' => trim((string) ($input['justification'] ?? '')) ?: null,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'requested_by' => $by,
            'approval_status' => (string) ($this->approvalSteps()[0]['status'] ?? 'pending'),
            'status' => self::OPEN,
        ]);

        return ['ok' => true, 'requisition' => $row, 'why' => "Raised {$row['req_code']}. It waits for "
            . ($this->waitingOn($row) ?? 'approval') . '; candidates cannot be linked to it until it is approved.'];
    }

    /**
     * THE APPROVAL CHAIN (owner, 12 Oct): config/recruitment.json →
     * requisition.approval_steps, in order — the department's Director or the
     * branch's Branch Admin, then HR Admin. Each step is a status the
     * requisition sits in and the roles that may decide it; a role mapped to
     * "department" / "branch" decides only a requisition for ITS department /
     * branch (a requisition naming none is open to every holder of the role).
     *
     * @return list<array{status: string, label: string, roles: array<string, string>}>
     */
    public function approvalSteps(): array
    {
        return array_values(array_map(static fn (array $st): array => [
            'status' => (string) $st['status'],
            'label' => (string) $st['label'],
            'roles' => array_change_key_case(array_map('strval', (array) ($st['roles'] ?? [])), CASE_LOWER),
        ], (array) ($this->declared()['requisition']['approval_steps'] ?? [['status' => 'pending', 'label' => 'Approval', 'roles' => []]])));
    }

    /** @param array<string, mixed> $req @return array{status: string, label: string, roles: array<string, string>}|null the step it waits on */
    private function currentStep(array $req): ?array
    {
        foreach ($this->approvalSteps() as $st) {
            if ($st['status'] === (string) ($req['approval_status'] ?? '')) {
                return $st;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $req Who a pending requisition waits on, or null once decided. */
    public function waitingOn(array $req): ?string
    {
        return $this->currentStep($req)['label'] ?? null;
    }

    /**
     * May this person decide the step the requisition waits on?
     *
     * @param array<string, mixed> $req
     * @param array{role?: string, department?: string, branch?: string} $decider their login role and org
     */
    public function mayDecide(array $req, array $decider): bool
    {
        $step = $this->currentStep($req);
        $role = strtolower(trim((string) ($decider['role'] ?? '')));

        if ($step === null) {
            return false;
        }

        if ($this->authority?->isFinal($role) ?? $role === 'administrator') {
            return true;
        }

        if (!array_key_exists($role, $step['roles'])) {
            return false;
        }

        $match = $step['roles'][$role];
        $field = $match === 'branch' ? 'location' : $match;
        $wanted = trim((string) ($req[$field] ?? ''));

        return $match === '' || $wanted === '' || strcasecmp($wanted, trim((string) ($decider[$match] ?? ''))) === 0;
    }

    /**
     * @param array{role?: string, department?: string, branch?: string} $decider
     * @return array{ok: bool, why: string}
     */
    public function decideRequisition(string $id, bool $approve, string $by, string $reason = '',
        array $decider = ['role' => 'administrator']): array
    {
        $req = $this->requisition($id);

        if ($req === null) {
            return ['ok' => false, 'why' => 'No such requisition.'];
        }

        $step = $this->currentStep($req);

        if ($step === null) {
            return ['ok' => false, 'why' => "{$req['req_code']} is already {$req['approval_status']}."];
        }

        if (!$this->mayDecide($req, $decider)) {
            return ['ok' => false, 'why' => "{$req['req_code']} waits on {$step['label']}"
                . ($req['department'] ?? null ? " for {$req['department']}" : '') . ($req['location'] ?? null ? " at {$req['location']}" : '')
                . '. Your role does not decide that step.'];
        }

        if (!$approve && trim($reason) === '') {
            return ['ok' => false, 'why' => 'A rejection needs a reason — it is what the requester reads.'];
        }

        $final = $this->authority?->isFinal((string) ($decider['role'] ?? '')) ?? true;
        $steps = $this->approvalSteps();
        $at = (int) array_search($step, $steps, true);
        $next = $final ? null : ($steps[$at + 1] ?? null);
        // EVERY APPROVER ON RECORD, in order: approved_by is the chain, approved_at the last word.
        $req['approved_by'] = trim(((string) ($req['approved_by'] ?? '') !== '' ? $req['approved_by'] . ' · ' : '')
            . $step['label'] . ': ' . $by . ($final && $next === null && $at < count($steps) - 1 ? ' (final approver)' : ''));
        $req['approved_at'] = date('Y-m-d H:i:s');

        if (!$approve) {
            $req['approval_status'] = 'rejected';
            $req['reject_reason'] = trim($reason);
            $req['status'] = self::CLOSED;
            $this->store->put(self::REQUISITIONS, $req);

            return ['ok' => true, 'why' => "{$req['req_code']} rejected at {$step['label']} and closed."];
        }

        $req['approval_status'] = $next === null ? 'approved' : $next['status'];
        $req['reject_reason'] = null;
        $this->store->put(self::REQUISITIONS, $req);

        return ['ok' => true, 'why' => $next === null
            ? "{$req['req_code']} approved" . ($at < count($steps) - 1 ? ' — a final approver\'s decision, so the remaining steps are not waited for' : '') . '. It is open for candidates.'
            : "{$req['req_code']} approved at {$step['label']}. It now waits on {$next['label']}."];
    }

    /** @return array{ok: bool, why: string} */
    public function closeRequisition(string $id, string $by): array
    {
        $req = $this->requisition($id);

        if ($req === null) {
            return ['ok' => false, 'why' => 'No such requisition.'];
        }

        if ((string) $req['status'] === self::CLOSED) {
            return ['ok' => false, 'why' => "{$req['req_code']} is already closed."];
        }

        $req['status'] = self::CLOSED;
        $this->store->put(self::REQUISITIONS, $req);

        return ['ok' => true, 'why' => "{$req['req_code']} closed. It is kept, with its candidates — a "
            . 'requisition is never deleted, because a hire points at it.'];
    }

    private function nextCode(): string
    {
        $prefix = (string) ($this->declared()['requisition']['code_prefix'] ?? 'REQ');
        $year = date('Y');
        $max = 0;

        foreach ($this->store->all(self::REQUISITIONS) as $r) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-' . $year . '-(\d+)$/', (string) ($r['req_code'] ?? ''), $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%s-%03d', $prefix, $year, $max + 1);
    }

    // -------------------------------------------------------------- candidates

    /** @return list<array<string, mixed>> */
    public function candidates(?string $requisitionId = null): array
    {
        $rows = $this->store->all(self::CANDIDATES);

        if ($requisitionId !== null) {
            $rows = array_values(array_filter($rows,
                static fn (array $c): bool => (string) ($c['job_id'] ?? '') === $requisitionId));
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    public function candidate(string $id): ?array
    {
        return $this->store->find(self::CANDIDATES, $id);
    }

    /** @return array{ok: bool, why: string, candidate?: array<string, mixed>} */
    public function addCandidate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'why' => 'A candidate needs a name.'];
        }

        $jobId = trim((string) ($input['job_id'] ?? ''));
        $position = trim((string) ($input['position'] ?? ''));

        if ($jobId !== '') {
            $req = $this->requisition($jobId);

            if ($req === null) {
                return ['ok' => false, 'why' => 'That requisition does not exist.'];
            }

            if ((string) $req['approval_status'] !== 'approved' || (string) $req['status'] !== self::OPEN) {
                return ['ok' => false, 'why' => "{$req['req_code']} is not approved and open, so nobody can be "
                    . 'linked to it. A candidate against an unapproved requisition is a hire nobody sanctioned.'];
            }

            $position = $position ?: (string) $req['title'];
        }

        $source = (string) ($input['source'] ?? '');

        if ($source !== '' && !in_array($source, (array) ($this->declared()['sources'] ?? []), true)) {
            return ['ok' => false, 'why' => "'{$source}' is not a declared source."];
        }

        foreach (['current_ctc', 'expected_ctc', 'experience_years'] as $number) {
            $value = trim((string) ($input[$number] ?? ''));

            if ($value !== '' && preg_match('/^\d+(\.\d{1,4})?$/', $value) !== 1) {
                return ['ok' => false, 'why' => "'{$value}' is not a number for {$number}."];
            }
        }

        $row = $this->store->put(self::CANDIDATES, [
            'name' => $name,
            'mobile' => trim((string) ($input['mobile'] ?? '')) ?: null,
            'email' => trim((string) ($input['email'] ?? '')) ?: null,
            'position' => $position ?: null,
            'job_id' => $jobId !== '' ? $jobId : null,
            'pool' => $jobId === '' ? 1 : 0,
            'source' => $source ?: null,
            'location' => trim((string) ($input['location'] ?? '')) ?: null,
            'current_company' => trim((string) ($input['current_company'] ?? '')) ?: null,
            'current_designation' => trim((string) ($input['current_designation'] ?? '')) ?: null,
            'current_ctc' => trim((string) ($input['current_ctc'] ?? '')) ?: null,
            'expected_ctc' => trim((string) ($input['expected_ctc'] ?? '')) ?: null,
            'experience_years' => trim((string) ($input['experience_years'] ?? '')) ?: null,
            'skills' => trim((string) ($input['skills'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            'resume_url' => trim((string) ($input['resume_url'] ?? '')) ?: null,
            'applied_on' => date('Y-m-d'),
            'stage' => $this->stages()[0],
        ]);

        return ['ok' => true, 'candidate' => $row, 'why' => "Added {$name}" . ($jobId === '' ? ' to the talent pool.'
            : ' against ' . (string) ($this->requisition($jobId)['req_code'] ?? $jobId) . '.')];
    }


    /** The import template's columns, in order — the same columns the Talent Pool shows. */
    public const IMPORT_COLUMNS = ['Name', 'Email', 'Mobile', 'Location', 'Experience (Years)', 'Current Company',
        'Current Designation', 'Current CTC', 'Expected CTC', 'Notice Period', 'Skills', 'Resume URL', 'Source', 'Position', 'Requisition'];

    /** Candidates in the pool: not linked to any requisition and not ended. */
    public function pool(): array
    {
        $terminal = array_map('strval', (array) ($this->declared()['terminal'] ?? []));

        return array_values(array_filter($this->candidates(), static fn (array $c): bool
            => (string) ($c['job_id'] ?? '') === '' && !in_array((string) ($c['stage'] ?? ''), $terminal, true)));
    }

    /** The CSV a recruiter downloads, fills and imports. */
    public function template(): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, self::IMPORT_COLUMNS);
        fputcsv($out, ['A Sample Candidate', 'sample@example.com', '+919999999999', 'Mumbai', '6', 'A Recovery Agency', 'Senior Collections Officer',
            '550000', '700000', '30 days', 'Field collection, negotiation', '', 'naukri', 'Collections Officer', '']);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * IMPORT A JOB PORTAL'S DOWNLOAD AS IT IS (owner, 12 Oct): Naukri, Indeed,
     * foundit, Shine, apna each name their columns differently, so the header row
     * is matched against config/recruitment.json → import_columns, and portal text
     * ("INR 5.5 Lacs", "2 Year(s) 6 Month(s)") is read into numbers. Every row
     * still goes through addCandidate(), so an import never creates what the form
     * refuses. The same person already in Recruitment (same email, or same last
     * ten digits of mobile) is a DUPLICATE and skipped — people apply through two
     * portals for one job.
     *
     * $defaults: 'source' (the portal chosen in the dialog) and 'job_id' (the
     * requisition), used where the file's own columns are blank.
     *
     * @param array{source?: string, job_id?: string} $defaults
     * @return array{imported: int, duplicates: int, notes: list<string>, understood: list<string>, ignored: list<string>}
     */
    public function import(string $csv, array $defaults = []): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim(ltrim($csv, "\xEF\xBB\xBF"))) ?: [];
        $raw = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv((string) array_shift($lines)));
        $header = array_map('strtolower', $raw);
        $col = [];
        $understood = [];

        foreach ((array) ($this->declared()['import_columns'] ?? []) as $field => $aliases) {
            foreach ((array) $aliases as $alias) {
                $i = array_search(strtolower((string) $alias), $header, true);

                if ($i !== false && !in_array($i, $col, true)) {
                    $col[(string) $field] = $i;
                    $understood[] = $raw[$i] . ' → ' . ucfirst(str_replace(['_ctc', '_', 'experience years'], [' CTC', ' ', 'experience (years)'], (string) $field));

                    break;
                }
            }
        }

        $ignored = array_values(array_filter(array_diff_key($raw, array_flip($col)), static fn (string $h): bool => $h !== ''));

        if (!isset($col['name']) && !isset($col['first_name'])) {
            return ['imported' => 0, 'duplicates' => 0, 'understood' => $understood, 'ignored' => $ignored,
                'notes' => ['No Name column was found in the first row (' . implode(', ', array_slice($raw, 0, 8))
                    . '). The first row must be the column names — the portal\'s own download already is.']];
        }

        $byCode = [];
        foreach ($this->requisitions() as $r) { $byCode[strtoupper((string) $r['req_code'])] = (string) $r['id']; }
        $seen = [];
        $seenId = [];
        foreach ($this->candidates() as $c) { foreach (self::identities($c) as $k) { $seen[$k] = (string) $c['name']; $seenId[$k] = (string) $c['id']; } }
        $ids = [];
        $sources = (array) ($this->declared()['sources'] ?? []);
        $imported = 0;
        $duplicates = 0;
        $notes = [];

        foreach ($lines as $i => $line) {
            if (trim(str_replace(',', '', $line)) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $at = static fn (string $field): string => isset($col[$field]) ? trim((string) ($cells[$col[$field]] ?? '')) : '';
            $name = $at('name') !== '' ? $at('name') : trim($at('first_name') . ' ' . $at('last_name'));
            $row = ['name' => $name, 'email' => $at('email'), 'mobile' => $at('mobile')];
            $where = 'Line ' . ($i + 2) . ($name !== '' ? " ({$name})" : '');

            $twin = null;
            foreach (self::identities($row) as $k) { $twin ??= $seen[$k] ?? null; isset($seenId[$k]) && $ids[$seenId[$k]] = true; }

            if ($twin !== null) {
                $duplicates++;
                $notes[] = "{$where}: already in Recruitment as {$twin} — skipped.";

                continue;
            }

            // PORTAL TEXT → NUMBERS; what cannot be read exactly is kept in the notes, never guessed.
            $extra = [];
            $numbers = [];
            foreach (['current_ctc' => 'amount', 'expected_ctc' => 'amount', 'experience_years' => 'years'] as $f => $how) {
                $text = $at($f);
                $numbers[$f] = $text === '' ? '' : self::$how($text);
                $text !== '' && $numbers[$f] === '' && $extra[] = ucfirst(str_replace('_', ' ', $f)) . ': ' . $text;
            }

            foreach (['notice_period' => 'Notice period', 'education' => 'Education', 'preferred_location' => 'Preferred location'] as $f => $label) {
                $at($f) !== '' && $extra[] = $label . ': ' . $at($f);
            }

            $reqCode = strtoupper($at('requisition'));
            $jobId = $byCode[$reqCode] ?? (string) ($defaults['job_id'] ?? '');
            $source = strtolower(str_replace([' ', '.com'], ['_', ''], $at('source')));
            $source = in_array($source, $sources, true) ? $source : (string) ($defaults['source'] ?? 'portal');
            // THE POSITION IS A DESIGNATION (owner, 12 Oct): a portal's free "job title" is kept in the notes.
            $position = $at('position');
            if ($position !== '' && $this->hasRows('designations') && $this->idByName('designations', $position) === null) {
                $extra[] = 'Applied for: ' . $position;
                $position = '';
            }

            $r = $this->addCandidate($row + [
                'location' => $at('location'), 'experience_years' => $numbers['experience_years'],
                'current_company' => $at('current_company'), 'current_designation' => $at('current_designation'),
                'current_ctc' => $numbers['current_ctc'], 'expected_ctc' => $numbers['expected_ctc'],
                'skills' => $at('skills'), 'notes' => implode(' · ', $extra), 'resume_url' => $at('resume_url'),
                'source' => $source, 'position' => $jobId === '' ? $position : '', 'job_id' => $jobId,
            ]);

            if (!$r['ok']) {
                $notes[] = "{$where}: {$r['why']}";

                continue;
            }

            $imported++;
            $ids[(string) $r['candidate']['id']] = true;
            foreach (self::identities($r['candidate']) as $k) { $seen[$k] = $name; $seenId[$k] = (string) $r['candidate']['id']; }
        }

        // `ids`: every candidate this file names — new ones and those already in Recruitment (a drive invites both).
        return ['imported' => $imported, 'duplicates' => $duplicates, 'notes' => $notes, 'understood' => $understood, 'ignored' => $ignored, 'ids' => array_keys($ids)];
    }

    /** @param array<string, mixed> $c @return list<string> email and last ten mobile digits — how one person is recognised across portals */
    private static function identities(array $c): array
    {
        $out = [];
        $email = strtolower(trim((string) ($c['email'] ?? '')));
        $digits = preg_replace('/\D/', '', (string) ($c['mobile'] ?? '')) ?? '';
        $email !== '' && $out[] = 'e:' . $email;
        strlen($digits) >= 10 && $out[] = 'm:' . substr($digits, -10);

        return $out;
    }

    /**
     * An Indian salary as portals write it → rupees, exactly (Law 7: scaled
     * integers, never a float). "INR 5.5 Lacs", "12 Lakhs 50 Thousand", "3.25 LPA",
     * "₹ 4,20,000", "1.2 Cr", "35k". '' when it cannot be read.
     *
     * ponytail: a bare number under 100 ("4.5") is read as lakhs — how portals and
     * recruiters write it; 100–999 is ambiguous and returned ''.
     */
    public static function amount(string $text): string
    {
        $t = strtolower(str_replace([',', '₹', '/-'], '', $text));
        $t = trim((string) preg_replace('/\b(inr|rs\.?|per annum|p\.?a\.?|annum)\b/', ' ', $t));
        $sum = 0;
        $found = false;
        $units = ['crore' => 10_000_000, 'crores' => 10_000_000, 'cr' => 10_000_000, 'lakh' => 100_000, 'lakhs' => 100_000,
            'lakh(s)' => 100_000, 'lac' => 100_000, 'lacs' => 100_000, 'lpa' => 100_000, 'l' => 100_000,
            'thousand' => 1_000, 'thousands' => 1_000, 'k' => 1_000];

        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(crores?|cr|lakh\(s\)|lakhs?|lacs?|lpa|l|thousands?|k)\b/', $t, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as [, $n, $unit]) {
                $sum += self::scaled($n) * $units[$unit];
            }
            $found = true;
        } elseif (preg_match('/^\d+(\.\d+)?$/', $t) === 1) {
            $whole = (int) explode('.', $t)[0];

            if ($whole >= 1000) {
                $sum = self::scaled($t);
            } elseif ($whole < 100) {
                $sum = self::scaled($t) * 100_000;
            } else {
                return '';
            }
            $found = true;
        }

        return $found ? self::unscale($sum) : '';
    }

    /** Portal experience → years, to four decimals. "5 Year(s) 6 Month(s)", "2y 6m", "Fresher", "7+ Years". '' when unreadable. */
    public static function years(string $text): string
    {
        $t = strtolower(trim($text));

        if ($t === '') {
            return '';
        }

        if (preg_match('/fresher|no experience|^0$/', $t) === 1) {
            return '0';
        }

        $y = preg_match('/(\d+(?:\.\d+)?)\s*\+?\s*(?:years?|yrs?|y\b|year\(s\))/', $t, $ym) === 1 ? self::scaled($ym[1]) : 0;
        $mo = preg_match('/(\d+)\s*(?:months?|mos?|m\b|month\(s\))/', $t, $mm) === 1 ? (int) $mm[1] : 0;

        if ($y === 0 && $mo === 0) {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*\+?$/', $t, $only) !== 1) {
                return '';
            }
            $y = self::scaled($only[1]);
        }

        // months → years at 4 decimals, rounded half up, in integers.
        return self::unscale($y + intdiv($mo * 10000 * 2 + 12, 24));
    }

    /** "5.5" → 55000 (four decimals as an integer). */
    private static function scaled(string $n): int
    {
        [$whole, $frac] = array_pad(explode('.', $n, 2), 2, '');

        return (int) $whole * 10000 + (int) str_pad(substr($frac, 0, 4), 4, '0');
    }

    private static function unscale(int $v): string
    {
        $frac = rtrim(str_pad((string) ($v % 10000), 4, '0', STR_PAD_LEFT), '0');

        return intdiv($v, 10000) . ($frac === '' ? '' : '.' . $frac);
    }

    /** Link a pooled candidate to an approved, open requisition. */
    public function assign(string $candidateId, string $requisitionId): array
    {
        $c = $this->candidate($candidateId);
        $req = $this->requisition($requisitionId);

        if ($c === null || $req === null) {
            return ['ok' => false, 'why' => 'No such candidate or requisition.'];
        }

        if ((string) $req['approval_status'] !== 'approved' || (string) $req['status'] !== self::OPEN) {
            return ['ok' => false, 'why' => "{$req['req_code']} is not approved and open."];
        }

        if (in_array((string) $c['stage'], $this->declared()['terminal'], true)) {
            return ['ok' => false, 'why' => "{$c['name']} is {$c['stage']}; a finished candidate is not reassigned."];
        }

        $c['job_id'] = $requisitionId;
        $c['pool'] = 0;
        $c['position'] = (string) ($c['position'] ?? '') ?: (string) $req['title'];
        $this->store->put(self::CANDIDATES, $c);

        return ['ok' => true, 'why' => "{$c['name']} linked to {$req['req_code']}."];
    }

    /**
     * Move a candidate one declared stage forward or back. `hired` is never a
     * destination here; `rejected` needs a reason.
     *
     * @return array{ok: bool, why: string}
     */
    public function moveStage(string $candidateId, string $to, string $reason = ''): array
    {
        $c = $this->candidate($candidateId);

        if ($c === null) {
            return ['ok' => false, 'why' => 'No such candidate.'];
        }

        $stages = $this->stages();
        $terminal = array_map('strval', $this->declared()['terminal']);
        $from = (string) $c['stage'];

        if (!in_array($to, $stages, true)) {
            return ['ok' => false, 'why' => "'{$to}' is not a declared stage."];
        }

        if (in_array($from, $terminal, true)) {
            return ['ok' => false, 'why' => "{$c['name']} is {$from}, which is final. A hire is undone by an exit, "
                . 'not by moving the candidate back.'];
        }

        if ($to === 'hired') {
            return ['ok' => false, 'why' => 'Nobody is moved to hired. Hiring creates the employee, fills the '
                . 'requisition and records the joining date — use hire().'];
        }

        // OFFER REJECTED BY THE CANDIDATE (owner, 22 Oct 2026): only from the offer stage, always with a reason.
        if ($to === 'offer_rejected' && !in_array($from, array_map('strval', (array) ($this->declared()['offer_rejected_from'] ?? ['offer'])), true)) {
            return ['ok' => false, 'why' => "{$c['name']} is at {$from}; an offer can be rejected only once it is made (stage offer)."];
        }

        if ($to === 'rejected' || $to === 'offer_rejected') {
            if (($to === 'offer_rejected' || ($this->declared()['reject_needs_reason'] ?? true)) && trim($reason) === '') {
                return ['ok' => false, 'why' => 'A rejection needs a reason. It is what the candidate is told '
                    . 'and what the next recruiter reads.'];
            }
        } else {
            $active = array_values(array_diff($stages, $terminal));
            $i = array_search($from, $active, true);
            $j = array_search($to, $active, true);

            if ($i === false || $j === false || abs($j - $i) !== 1) {
                return ['ok' => false, 'why' => "From {$from} a candidate moves to " . implode(' or ',
                    array_filter([$active[$i - 1] ?? null, $active[$i + 1] ?? null])) . ", not {$to}. One step "
                    . 'at a time is what makes the pipeline report mean something.'];
            }
        }

        $c['stage'] = $to;

        if ($to === 'rejected' || $to === 'offer_rejected') {
            $c['reject_reason'] = trim($reason);
        }
        $to === 'offer_rejected' && $c['offer_status'] = 'rejected';

        $this->store->put(self::CANDIDATES, $c);

        return ['ok' => true, 'why' => "{$c['name']}: {$from} → {$to}."];
    }

    // -------------------------------------------------------------- interviews

    /** @return list<array<string, mixed>> */
    public function interviews(string $candidateId): array
    {
        $rows = array_values(array_filter($this->store->all(self::INTERVIEWS),
            static fn (array $i): bool => (string) ($i['candidate_id'] ?? '') === $candidateId));
        usort($rows, static fn (array $a, array $b): int => (int) $a['round'] <=> (int) $b['round']);

        return $rows;
    }

    /**
     * WHICH ROUND THE CANDIDATE IS AT (owner, 21 Oct): the latest round's number, label, status and
     * time — "Round 2 · Ops Round · On Hold · 21-10-2026 14:00"; '' with no rounds.
     *
     * @param list<array<string, mixed>> $interviews as interviews() returns them
     */
    public static function roundStatus(array $interviews): string
    {
        $last = end($interviews);

        if ($last === false) {
            return '';
        }

        $label = trim((string) ($last['round_label'] ?? ''));

        return 'Round ' . (int) $last['round'] . ($label === '' || $label === 'Round ' . (int) $last['round'] ? '' : ' · ' . $label)
            . ' · ' . ucwords(str_replace('_', ' ', (string) $last['status']))
            . ' · ' . date('d-m-Y H:i', (int) strtotime((string) $last['scheduled_at']));
    }

    /** @return array{ok: bool, why: string} */
    public function schedule(string $candidateId, array $input): array
    {
        $c = $this->candidate($candidateId);

        if ($c === null) {
            return ['ok' => false, 'why' => 'No such candidate.'];
        }

        $d = $this->declared()['interview'];
        $mode = (string) ($input['mode'] ?? '');
        // A browser's datetime-local control sends `2026-09-10T10:00`; the T is
        // the one separator accepted besides a space, and nothing else is guessed.
        $at = str_replace('T', ' ', trim((string) ($input['scheduled_at'] ?? '')));

        if (!in_array($mode, (array) $d['modes'], true)) {
            return ['ok' => false, 'why' => "'{$mode}' is not a declared interview mode."];
        }

        if ($at === '' || \DateTimeImmutable::createFromFormat('Y-m-d H:i', $at) === false) {
            return ['ok' => false, 'why' => "'{$at}' is not a date and time (Y-m-d H:i)."];
        }

        $round = count($this->interviews($candidateId)) + 1;
        $interview = $this->store->put(self::INTERVIEWS, [
            'candidate_id' => $candidateId,
            'round' => $round,
            'round_label' => trim((string) ($input['round_label'] ?? '')) ?: "Round {$round}",
            'scheduled_at' => $at . ':00',
            'mode' => $mode,
            'panel' => trim((string) ($input['panel'] ?? '')) ?: null,
            'status' => 'scheduled',
        ]);
        // THE STAGE FOLLOWS THE INTERVIEW (owner, 21 Oct): a scheduled round puts the candidate at
        // the declared interview stage everywhere the stage is read (badge, pipeline, funnel, exports).
        $c = $this->advanceForInterview($c);

        return ['ok' => true, 'why' => "Round {$round} scheduled for {$c['name']} at " . date('d-m-Y H:i', (int) strtotime($at)) . '.',
            'interview' => $interview, 'candidate' => $c];
    }

    /**
     * Move a candidate still BEFORE the declared `stage_on_interview` up to it. A candidate already
     * there or beyond (offer), or final (hired / rejected), is left as they are.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function advanceForInterview(array $c): array
    {
        $to = (string) ($this->declared()['stage_on_interview'] ?? '');
        $active = array_values(array_diff($this->stages(), array_map('strval', $this->declared()['terminal'])));
        $i = array_search((string) $c['stage'], $active, true);
        $j = array_search($to, $active, true);

        if ($i === false || $j === false || $i >= $j) {
            return $c;
        }

        $c['stage'] = $to;
        $this->store->put(self::CANDIDATES, $c);

        return $c;
    }

    /** Existing data: every candidate with an interview on record but still before that stage. Returns how many moved. */
    public function syncInterviewStages(): int
    {
        $with = [];

        foreach ($this->store->all(self::INTERVIEWS) as $iv) {
            $with[(string) ($iv['candidate_id'] ?? '')] = true;
        }

        $moved = 0;

        foreach ($this->store->all(self::CANDIDATES) as $c) {
            if (isset($with[(string) $c['id']]) && $this->advanceForInterview($c)['stage'] !== $c['stage']) {
                $moved++;
            }
        }

        return $moved;
    }

    /** @return array{ok: bool, why: string} */
    public function outcome(string $interviewId, array $input): array
    {
        $i = $this->store->find(self::INTERVIEWS, $interviewId);

        if ($i === null) {
            return ['ok' => false, 'why' => 'No such interview.'];
        }

        $r = $this->applyOutcome($i, $input);

        if (!$r['ok']) {
            return $r;
        }

        $this->store->put(self::INTERVIEWS, $r['row']);

        return ['ok' => true, 'why' => "Round {$i['round']} recorded as {$r['row']['status']}."];
    }

    /**
     * EDIT A ROUND (owner, 21 Oct: "Round 2 was on hold, I want to edit it"): label, time, mode, panel
     * and status — with score / recommendation / feedback when completed. A round set back to
     * scheduled moves the candidate up to the interview stage like a new one.
     *
     * @return array{ok: bool, why: string, interview?: array<string, mixed>, candidate?: array<string, mixed>, renotify?: bool}
     */
    public function editInterview(string $interviewId, array $input): array
    {
        $i = $this->store->find(self::INTERVIEWS, $interviewId);

        if ($i === null) {
            return ['ok' => false, 'why' => 'No such interview.'];
        }

        $c = $this->candidate((string) $i['candidate_id']);

        if ($c === null) {
            return ['ok' => false, 'why' => 'No such candidate.'];
        }

        $mode = (string) ($input['mode'] ?? '');
        $at = str_replace('T', ' ', trim((string) ($input['scheduled_at'] ?? '')));

        if (!in_array($mode, (array) $this->declared()['interview']['modes'], true)) {
            return ['ok' => false, 'why' => "'{$mode}' is not a declared interview mode."];
        }

        if (\DateTimeImmutable::createFromFormat('Y-m-d H:i', $at) === false) {
            return ['ok' => false, 'why' => "'{$at}' is not a date and time."];
        }

        $before = [(string) $i['scheduled_at'], (string) ($i['panel'] ?? ''), (string) $i['status']];
        $i['round_label'] = trim((string) ($input['round_label'] ?? '')) ?: (string) $i['round_label'];
        $i['scheduled_at'] = $at . ':00';
        $i['mode'] = $mode;
        $i['panel'] = trim((string) ($input['panel'] ?? '')) ?: null;
        $r = $this->applyOutcome($i, $input);

        if (!$r['ok']) {
            return $r;
        }

        $i = $this->store->put(self::INTERVIEWS, $r['row']);

        if ($i['status'] === 'scheduled') {
            $c = $this->advanceForInterview($c);
        }

        return ['ok' => true, 'why' => "Round {$i['round']} updated: " . date('d-m-Y H:i', (int) strtotime($at)) . ", {$i['status']}.",
            'interview' => $i, 'candidate' => $c,
            'renotify' => $i['status'] === 'scheduled' && $before !== [(string) $i['scheduled_at'], (string) ($i['panel'] ?? ''), 'scheduled']];
    }

    /**
     * Status (and the outcome a completed round needs) onto a round, validated, not stored.
     *
     * @param array<string, mixed> $i
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    private function applyOutcome(array $i, array $input): array
    {
        $d = $this->declared()['interview'];
        $status = (string) ($input['status'] ?? '');

        if (!in_array($status, (array) $d['status'], true)) {
            return ['ok' => false, 'why' => "'{$status}' is not a declared interview status."];
        }

        if ($status === 'completed') {
            $score = (int) ($input['score'] ?? 0);
            $rec = (string) ($input['recommendation'] ?? '');

            if ($score < (int) $d['score_min'] || $score > (int) $d['score_max']) {
                return ['ok' => false, 'why' => "A score is {$d['score_min']} to {$d['score_max']}."];
            }

            if (!in_array($rec, (array) $d['recommendation'], true)) {
                return ['ok' => false, 'why' => "'{$rec}' is not a declared recommendation."];
            }

            $i['score'] = $score;
            $i['recommendation'] = $rec;
            $i['feedback'] = trim((string) ($input['feedback'] ?? '')) ?: null;
        }

        $i['status'] = $status;

        return ['ok' => true, 'why' => '', 'row' => $i];
    }

    // ------------------------------------------------------------------- offer

    /**
     * THE OFFER IS RECORDED BEFORE THE HIRE (owner, 12 Oct): CTC and proposed
     * joining date at the Offer stage, so the offer letter can be printed and the
     * Hire form starts from what was offered. offer_status: sent → accepted (on
     * hire); a declined offer is a rejection with its reason.
     *
     * @return array{ok: bool, why: string}
     */
    public function recordOffer(string $candidateId, array $input): array
    {
        $c = $this->candidate($candidateId);

        if ($c === null) {
            return ['ok' => false, 'why' => 'No such candidate.'];
        }

        if ((string) $c['stage'] !== 'offer') {
            return ['ok' => false, 'why' => "{$c['name']} is at {$c['stage']}. Move them to Offer first."];
        }

        $ctc = trim((string) ($input['offered_ctc'] ?? ''));
        $doj = trim((string) ($input['offer_doj'] ?? ''));

        if (preg_match('/^\d+(\.\d{1,4})?$/', $ctc) !== 1) {
            return ['ok' => false, 'why' => "'{$ctc}' is not an annual CTC. Digits and up to four decimals."];
        }

        if (!self::isDate($doj)) {
            return ['ok' => false, 'why' => "'{$doj}' is not a joining date."];
        }

        $c['offered_ctc'] = $ctc;
        $c['offer_doj'] = $doj;
        $c['offer_status'] = 'sent';
        $c['offer_notes'] = trim((string) ($input['offer_notes'] ?? '')) ?: null;
        $this->store->put(self::CANDIDATES, $c);

        return ['ok' => true, 'why' => "Offer recorded for {$c['name']}: CTC {$ctc}, joining {$doj}. Print the offer letter from the candidate page."];
    }

    /**
     * WHAT EACH SOURCE BROUGHT — per portal / source: candidates, how far they got,
     * and hires. The figure HR uses to decide which portal to keep paying for.
     *
     * @return array<string, array<string, int>> source => [total, <stage> => count ...]
     */
    public function sourceReport(): array
    {
        $out = [];
        $stages = $this->stages();
        foreach ($this->candidates() as $c) {
            $src = (string) ($c['source'] ?? '') ?: 'unknown';
            $out[$src] ??= ['total' => 0] + array_fill_keys($stages, 0);
            $out[$src]['total']++;
            $out[$src][(string) $c['stage']] = ($out[$src][(string) $c['stage']] ?? 0) + 1;
        }

        uasort($out, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $out;
    }

    // -------------------------------------------------------------------- hire

    /**
     * Hire: create the employee, fill the requisition, close it when full.
     *
     * @return array{ok: bool, why: string, emp_code?: string, employee?: array<string, mixed>}
     */
    public function hire(string $candidateId, array $input, string $by): array
    {
        $c = $this->candidate($candidateId);

        if ($c === null) {
            return ['ok' => false, 'why' => 'No such candidate.'];
        }

        if (!in_array((string) $c['stage'], array_map('strval', $this->declared()['hire_from']), true)) {
            return ['ok' => false, 'why' => "{$c['name']} is at {$c['stage']}; a hire is made from "
                . implode('/', $this->declared()['hire_from']) . '. An offer nobody recorded is an offer '
                . 'nobody can find when the CTC is disputed.'];
        }

        $code = trim((string) ($input['emp_code'] ?? ''));
        $doj = trim((string) ($input['doj'] ?? ''));
        $ctc = trim((string) ($input['ctc'] ?? ''));

        if ($code === '') {
            return ['ok' => false, 'why' => 'An employee needs a code. It is typed, never derived from the '
                . 'name — two people with one name is how the wrong person gets paid.'];
        }

        if (!self::isDate($doj)) {
            return ['ok' => false, 'why' => "'{$doj}' is not a joining date (Y-m-d)."];
        }

        // THE FORM ASKS AND STARTS BLANK (owner, 28 Sep), so a hire posted
        // without choosing is refused rather than filed as whatever a default
        // would have been. A caller that never offers the field is unaffected.
        if (array_key_exists('employment_type', $input) && trim((string) $input['employment_type']) === '') {
            return ['ok' => false, 'why' => 'Choose the employee type — Permanent, Probationary, Intern and so on.'];
        }

        if (preg_match('/^\d+(\.\d{1,4})?$/', $ctc) !== 1) {
            return ['ok' => false, 'why' => "'{$ctc}' is not an annual CTC. Digits and up to four decimals."];
        }

        foreach ($this->store->all('employees') as $e) {
            if (Employment::code($e) === $code) {
                return ['ok' => false, 'why' => "The code {$code} is already an employee (" . trim((string) ($e['name'] ?? ''))
                    . '). Not linked: SmartPRS 2 attaches a new joiner to whichever employee shares the name, '
                    . 'which is how a leaver from three years ago comes back on the payroll.'];
            }
        }

        // THE REGISTER IDS TOO: the Directory, the filters and the muster read
        // department_id / designation_id / branch_id / employer_company_id; a hire
        // that wrote only the text landed in no filter.
        // IDS FIRST (28 Sep): the form now posts the unit's id, and the name is
        // read from it — two same-named departments can no longer be confused.
        $department = $this->nameById('departments', (string) ($input['department_id'] ?? ''))
            ?? trim((string) ($input['department'] ?? ''));
        $designation = $this->nameById('designations', (string) ($input['designation_id'] ?? ''))
            ?? (trim((string) ($input['designation'] ?? '')) ?: (string) ($c['position'] ?? ''));
        $branch = $this->nameById('branches', (string) ($input['branch_id'] ?? ''))
            ?? trim((string) ($input['branch'] ?? ''));
        $companyId = trim((string) ($input['employer_company_id'] ?? ''));
        $employee = $this->store->put('employees', array_filter([
            Employment::CODE_COLUMN => $code,
            'name' => (string) $c['name'],
            'mobile' => $c['mobile'] ?? null,
            'email' => $c['email'] ?? null,
            'doj' => $doj,
            'ctc' => $ctc,
            'employer_company_id' => $companyId !== '' ? $companyId : null,
            'department' => $department ?: null,
            'department_id' => trim((string) ($input['department_id'] ?? '')) ?: $this->idByName('departments', $department),
            'designation' => $designation ?: null,
            'designation_id' => trim((string) ($input['designation_id'] ?? '')) ?: $this->idByName('designations', $designation),
            'branch' => $branch ?: null,
            'branch_id' => trim((string) ($input['branch_id'] ?? '')) ?: $this->idByName('branches', $branch),
            'reporting_manager_id' => trim((string) ($input['reporting_manager_id'] ?? '')) ?: null,
            'employment_stage' => trim((string) ($input['employment_stage'] ?? '')) ?: null,
            // EMPLOYEE TYPE (owner, 28 Sep): what the Directory shows, and what
            // puts a probationary hire on the Probation board.
            'employment_type' => trim((string) ($input['employment_type'] ?? '')) ?: null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ], static fn ($v): bool => $v !== null));

        $filledNote = $this->markHired($c, $code, $doj, $ctc, $by);

        return ['ok' => true, 'emp_code' => $code, 'employee' => $employee,
            'why' => "{$c['name']} hired as {$code}, joining {$doj}.{$filledNote}"];
    }

    /**
     * The candidate side of a hire: stage hired, the offer accepted, the requisition filled (closed when
     * full). Used by hire() and by Self-Onboarding's Approve & inject for an invitation made from this
     * candidate (owner, 21 Oct flow) — the employee itself is created by whichever of the two ran.
     *
     * @param array<string, mixed> $c
     * @return string what happened to the requisition, '' for a talent-pool candidate
     */
    public function markHired(array $c, string $code, string $doj, string $ctc, string $by): string
    {
        $c['stage'] = 'hired';
        $c['offered_ctc'] = $ctc !== '' ? $ctc : ($c['offered_ctc'] ?? null);
        $c['offer_doj'] = $doj;
        $c['offer_status'] = 'accepted';
        $c['offer_notes'] = 'Hired as ' . $code . ' by ' . $by;
        $this->store->put(self::CANDIDATES, $c);

        $filledNote = '';
        $jobId = (string) ($c['job_id'] ?? '');

        if ($jobId !== '' && ($req = $this->requisition($jobId)) !== null) {
            $req['filled'] = (int) ($req['filled'] ?? 0) + 1;

            if ((int) $req['filled'] >= (int) $req['openings']) {
                $req['status'] = self::CLOSED;
                $filledNote = " {$req['req_code']} is now filled ({$req['filled']}/{$req['openings']}) and closed.";
            } else {
                $filledNote = " {$req['req_code']}: {$req['filled']} of {$req['openings']} filled.";
            }

            $this->store->put(self::REQUISITIONS, $req);
        }

        return $filledNote;
    }

    /**
     * What the offer and the requisition already say, for the form that creates the employee
     * (Self-Onboarding → Approve & inject): company, branch, department, designation (ids), joining
     * date and CTC. Only what is known; HR still sees and can change every value.
     *
     * @return array<string, string>
     */
    public function prefillFor(string $candidateId): array
    {
        $c = $this->candidate($candidateId);

        if ($c === null) {
            return [];
        }

        $req = $this->requisition((string) ($c['job_id'] ?? ''));

        return array_filter([
            'employer_company_id' => (string) ($req['employer_company_id'] ?? ''),
            'branch_id' => (string) ($this->idByName('branches', (string) ($req['location'] ?? '')) ?? ''),
            'department_id' => (string) ($this->idByName('departments', (string) ($req['department'] ?? '')) ?? ''),
            'designation_id' => (string) ($this->idByName('designations', (string) (($req['title'] ?? '') ?: ($c['position'] ?? ''))) ?? ''),
            'doj' => (string) ($c['offer_doj'] ?? ''),
            'ctc' => (string) ($c['offered_ctc'] ?? ''),
        ], static fn (string $v): bool => $v !== '');
    }

    /** Candidates per stage for one requisition, or for everything. @return array<string, int> */
    /**
     * Move a candidate FORWARD to $to one declared step at a time (rejected / offer_rejected directly, with
     * the reason). A candidate already at or past $to, or in a final stage, stays. Used by Hiring Drives.
     * @return array{ok: bool, why: string}
     */
    public function advanceTo(string $candidateId, string $to, string $reason = ''): array
    {
        $c = $this->candidate($candidateId);
        if ($c === null || $to === '') {
            return ['ok' => true, 'why' => ''];
        }
        if (in_array((string) $c['stage'], array_map('strval', $this->declared()['terminal']), true)) {
            return ['ok' => true, 'why' => ''];
        }
        if ($to === 'rejected' || $to === 'offer_rejected') {
            return $this->moveStage($candidateId, $to, $reason);
        }
        $active = array_values(array_diff($this->stages(), array_map('strval', $this->declared()['terminal'])));
        $why = [];
        while (($i = array_search((string) $c['stage'], $active, true)) !== false && ($j = array_search($to, $active, true)) !== false && $i < $j) {
            $r = $this->moveStage($candidateId, $active[$i + 1]);
            if (!$r['ok']) {
                return $r;
            }
            $why[] = $r['why'];
            $c = $this->candidate($candidateId);
        }

        return ['ok' => true, 'why' => implode(' ', $why)];
    }

    public function funnel(?string $requisitionId = null): array
    {
        $out = array_fill_keys($this->stages(), 0);

        foreach ($this->candidates($requisitionId) as $c) {
            $out[(string) $c['stage']] = ($out[(string) $c['stage']] ?? 0) + 1;
        }

        return $out;
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }

    /** The name of a unit by its id, or null when no id was given or it names nothing. */
    private function nameById(string $collection, string $id): ?string
    {
        if (trim($id) === '') {
            return null;
        }

        try {
            $row = $this->store->find($collection, trim($id));
        } catch (\Throwable) { // gate:allow-swallow no such register on a file store — the name falls back to the posted text
            return null;
        }

        $name = trim((string) ($row['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    private function hasRows(string $collection): bool
    {
        try {
            return $this->store->all($collection) !== [];
        } catch (\Throwable) { // gate:allow-swallow no such register on a file store: nothing set up to choose from
            return false;
        }
    }

    private function idByName(string $collection, string $name): ?string
    {
        if (trim($name) === '') {
            return null;
        }

        try {
            foreach ($this->store->all($collection) as $r) {
                if (strcasecmp(trim((string) ($r['name'] ?? '')), trim($name)) === 0 && (string) ($r['deleted_at'] ?? '') === '') {
                    return (string) $r['id'];
                }
            }
        } catch (\Throwable) { // gate:allow-swallow no such register on a file store — the text column still carries the value
            return null;
        }

        return null;
    }
}
