<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A HIRING DRIVE IS A DAY, A VENUE AND A LIST OF WHO IS EXPECTED.
 *
 * SmartPRS 2's HiringDriveController: a drive is raised against a requisition
 * (or a free title) with a date, time window, venue and coordinator; candidates
 * from the pipeline are invited to it; on the day each is marked attended, scored
 * and given an outcome; the sheet exports. The two ported tables are used as they
 * are — `hiring_drives`, `hiring_drive_candidates`.
 *
 * WHAT IS NOT PORTED: SmartPRS 2 deletes a drive with its candidates. Here a
 * drive is `cancelled` and its rows stay — a walk-in list is evidence of who was
 * called for what, and evidence is not deleted because the day was called off.
 */
final class HiringDrives
{
    public const DRIVES = 'hiring_drives';
    public const CANDIDATES = 'hiring_drive_candidates';

    public const STATUSES = ['planned', 'open', 'completed', 'cancelled'];
    public const OUTCOMES = ['pending', 'selected', 'hold', 'rejected', 'absent'];

    public function __construct(private readonly RowStore $store)
    {
    }

    /** @return list<array<string, mixed>> newest date first */
    public function all(): array
    {
        $rows = $this->store->all(self::DRIVES);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['drive_date'] ?? ''), (string) ($a['drive_date'] ?? '')));

        return array_values($rows);
    }

    public function find(string $id): ?array
    {
        return $this->store->find(self::DRIVES, $id);
    }

    /** @return array{ok: bool, why: string, drive?: array<string, mixed>} */
    public function save(array $input, string $by, ?array $requisition = null): array
    {
        $title = trim((string) ($input['title'] ?? '')) ?: (string) ($requisition['title'] ?? '');

        if ($title === '') {
            return ['ok' => false, 'why' => 'A drive needs a title or a requisition.'];
        }

        $date = trim((string) ($input['drive_date'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($d === false || $d->format('Y-m-d') !== $date) {
            return ['ok' => false, 'why' => "'{$date}' is not a drive date (Y-m-d)."];
        }

        $to = trim((string) ($input['date_to'] ?? ''));

        if ($to !== '' && $to < $date) {
            return ['ok' => false, 'why' => 'A drive cannot end before it starts.'];
        }

        $row = $this->store->put(self::DRIVES, array_filter([
            'id' => trim((string) ($input['id'] ?? '')) ?: null,
            'job_id' => $requisition === null ? null : (string) $requisition['id'],
            'title' => $title,
            'position' => trim((string) ($input['position'] ?? '')) ?: (string) ($requisition['title'] ?? ''),
            'company' => trim((string) ($input['company'] ?? '')) ?: null,
            'type' => trim((string) ($input['type'] ?? '')) ?: 'walk_in',
            'status' => in_array((string) ($input['status'] ?? ''), self::STATUSES, true) ? (string) $input['status'] : 'planned',
            'drive_date' => $date,
            'date_to' => $to ?: null,
            'time_from' => trim((string) ($input['time_from'] ?? '')) ?: null,
            'time_to' => trim((string) ($input['time_to'] ?? '')) ?: null,
            'venue' => trim((string) ($input['venue'] ?? '')) ?: null,
            'location_link' => trim((string) ($input['location_link'] ?? '')) ?: null,
            'coordinator_name' => trim((string) ($input['coordinator_name'] ?? '')) ?: null,
            'coordinator_phone' => trim((string) ($input['coordinator_phone'] ?? '')) ?: null,
            'instructions' => trim((string) ($input['instructions'] ?? '')) ?: null,
            'created_by' => $by,
        ], static fn ($v): bool => $v !== null));

        return ['ok' => true, 'drive' => $row, 'why' => "Drive '{$title}' on {$date} saved."];
    }

    public function setStatus(string $id, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'why' => "'{$status}' is not a drive status."];
        }

        if ($this->find($id) === null) {
            return ['ok' => false, 'why' => 'No such drive.'];
        }

        $this->store->put(self::DRIVES, ['id' => $id, 'status' => $status]);

        return ['ok' => true, 'why' => "Drive marked {$status}."];
    }

    /** @return list<array<string, mixed>> */
    public function candidates(string $driveId): array
    {
        return array_values(array_filter($this->store->all(self::CANDIDATES),
            static fn (array $r): bool => (string) ($r['drive_id'] ?? '') === $driveId));
    }

    /** Invite pipeline candidates (by recruitment row) to a drive; already-listed ones are skipped. */
    public function invite(string $driveId, array $candidates): array
    {
        if ($this->find($driveId) === null) {
            return ['ok' => false, 'why' => 'No such drive.', 'added' => 0];
        }

        $listed = array_map(static fn (array $r): string => (string) ($r['candidate_id'] ?? ''), $this->candidates($driveId));
        $added = 0;

        foreach ($candidates as $c) {
            if (in_array((string) $c['id'], $listed, true)) {
                continue;
            }

            $this->store->put(self::CANDIDATES, ['drive_id' => $driveId, 'candidate_id' => (string) $c['id'],
                'name' => (string) $c['name'], 'mobile' => (string) ($c['mobile'] ?? ''), 'attended' => 0]);
            $added++;
        }

        return ['ok' => true, 'added' => $added, 'why' => "{$added} candidate(s) added to the drive."];
    }

    /** Record what happened with the row's invitation, e.g. 'emailed' (owner, 22 Oct 2026). */
    public function setResponse(string $rowId, string $response): void
    {
        $this->store->put(self::CANDIDATES, ['id' => $rowId, 'response' => mb_substr($response, 0, 30)]);
    }

    /** On the day: attended, score, recommendation, outcome, notes. */
    public function mark(string $rowId, array $input): array
    {
        $row = $this->store->find(self::CANDIDATES, $rowId);

        if ($row === null) {
            return ['ok' => false, 'why' => 'No such drive candidate.'];
        }

        $outcome = (string) ($input['outcome'] ?? '');

        if (!in_array($outcome, self::OUTCOMES, true)) {
            return ['ok' => false, 'why' => "'{$outcome}' is not an outcome."];
        }

        $score = trim((string) ($input['score'] ?? ''));

        if ($score !== '' && (!ctype_digit($score) || (int) $score > 100)) {
            return ['ok' => false, 'why' => 'A score is 0 to 100.'];
        }

        $attended = !empty($input['attended']);
        $this->store->put(self::CANDIDATES, [
            'id' => $rowId,
            'attended' => $attended ? 1 : 0,
            'attended_at' => $attended ? ((string) ($row['attended_at'] ?? '') ?: date('Y-m-d H:i:s')) : null,
            'score' => $score === '' ? null : (int) $score,
            'recommendation' => trim((string) ($input['recommendation'] ?? '')) ?: null,
            'outcome' => $outcome,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ]);

        return ['ok' => true, 'why' => 'Updated ' . $row['name'] . '.', 'row' => array_merge($row, ['attended' => $attended ? 1 : 0, 'outcome' => $outcome,
            'notes' => trim((string) ($input['notes'] ?? ''))])];
    }

    public function csv(string $driveId): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, ['Name', 'Mobile', 'Attended', 'Attended at', 'Score', 'Recommendation', 'Outcome', 'Notes']);

        foreach ($this->candidates($driveId) as $r) {
            fputcsv($out, [(string) $r['name'], (string) ($r['mobile'] ?? ''), (int) ($r['attended'] ?? 0) === 1 ? 'yes' : 'no',
                (string) ($r['attended_at'] ?? ''), (string) ($r['score'] ?? ''), (string) ($r['recommendation'] ?? ''),
                (string) ($r['outcome'] ?? ''), (string) ($r['notes'] ?? '')]);
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }
}
