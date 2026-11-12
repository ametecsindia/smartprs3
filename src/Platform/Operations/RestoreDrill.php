<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Operations;

/**
 * A BACKUP NOBODY HAS RESTORED IS A BELIEF — Part XXXII Phase 9.
 *
 * *"A restore drill succeeds and its date is visible in the UI."*
 *
 * Both halves. A drill that succeeded and is not visible is a fact one person
 * knows; the date on the screen is what lets an operator answer "when was this
 * last proven restorable?" without a support call — R-XXXI-093's own acceptance
 * test.
 *
 * A DRILL THAT ONLY RESTORES HAS NOT DRILLED. Restoring a corrupt backup
 * "succeeds" — the files land, the process exits zero — so the drill verifies
 * something it knows about the data afterwards. Without that step this class
 * would prove the restore command runs, which nobody doubted.
 */
final class RestoreDrill
{
    /** @var list<array{at: \DateTimeImmutable, ok: bool, backup_ref: string, note: string}> */
    private array $drills = [];

    /**
     * @param callable(string): bool $restore
     * @param callable(): array{tables: int, rows: int} $verify what the restored copy actually contains
     * @param array{tables: int, rows: int} $expected
     */
    public function run(
        string $backupRef,
        \DateTimeImmutable $at,
        callable $restore,
        callable $verify,
        array $expected,
    ): array {
        try {
            if (!$restore($backupRef)) {
                return $this->record($at, false, $backupRef, 'the restore itself failed');
            }
        } catch (\Throwable $e) {
            return $this->record($at, false, $backupRef, 'the restore threw: ' . $e->getMessage());
        }

        // The half that makes it a drill. A corrupt backup restores perfectly:
        // the files land and the process exits zero.
        $found = $verify();

        if ($found['tables'] !== $expected['tables'] || $found['rows'] !== $expected['rows']) {
            return $this->record($at, false, $backupRef, sprintf(
                'the restore completed and the restored copy holds %d table(s) and %d row(s), expected '
                . '%d and %d. A restore that lands the files and exits zero has proven the command runs, '
                . 'which nobody doubted',
                $found['tables'], $found['rows'], $expected['tables'], $expected['rows'],
            ));
        }

        return $this->record($at, true, $backupRef, sprintf(
            'restored and verified: %d tables, %d rows', $found['tables'], $found['rows']));
    }

    public function lastSuccessful(): ?\DateTimeImmutable
    {
        foreach (array_reverse($this->drills) as $drill) {
            if ($drill['ok']) {
                return $drill['at'];
            }
        }

        return null;
    }

    /** The sentence the console shows — and it says "never" out loud rather than showing a blank. */
    public function consoleValue(\DateTimeImmutable $asOf): string
    {
        $last = $this->lastSuccessful();

        if ($last === null) {
            return 'NEVER PROVEN RESTORABLE — no successful drill has ever run. A backup nobody has '
                . 'restored is a belief';
        }

        return sprintf('last proven restorable %s (%d day(s) ago)', $last->format('Y-m-d'),
            (int) $last->diff($asOf)->days);
    }

    /** @return list<array{at: \DateTimeImmutable, ok: bool, backup_ref: string, note: string}> */
    public function history(): array
    {
        return $this->drills;
    }

    private function record(\DateTimeImmutable $at, bool $ok, string $ref, string $note): array
    {
        $this->drills[] = ['at' => $at, 'ok' => $ok, 'backup_ref' => $ref, 'note' => $note];

        return ['ok' => $ok, 'note' => $note];
    }
}
