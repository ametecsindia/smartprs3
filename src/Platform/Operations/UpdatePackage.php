<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Operations;

/**
 * AN UPDATE THAT CAN GO BACKWARDS — Part XXXII Phase 9.
 *
 * *"An update applies, FAILS DELIBERATELY, and rolls back both code AND
 * database."*
 *
 * That exit criterion is written the way it is because the common failure is not
 * an update that breaks; it is an update that half-succeeds. Code rolls back and
 * the migration does not, so the application is version N running against a
 * schema for N+1, and every symptom points somewhere else.
 *
 * So: THE DATABASE BACKUP IS TAKEN BEFORE THE MIGRATION, AND ROLLBACK IS PROVEN
 * BEFORE THE UPDATE IS APPLIED, not attempted after it fails. An untested
 * rollback path is discovered at the exact moment it is needed and cannot be
 * fixed, by someone at 2am with a customer waiting.
 *
 * And the update is SIGNED. A file that can be replaced on disk between download
 * and apply is a file that can be replaced on disk between download and apply.
 */
final class UpdatePackage
{
    /** @var list<string> */
    private array $journal = [];

    public function __construct(
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly bool $signatureValid,
    ) {
    }

    /**
     * @param callable(): void $applyCode
     * @param callable(): void $applyMigrations
     * @param callable(): string $backupDatabase returns the backup reference
     * @param callable(string): void $restoreDatabase
     * @param callable(string): void $restoreCode
     * @return array{ok: bool, rolled_back: bool, version: string, journal: list<string>, failure: ?string}
     */
    public function apply(
        callable $backupDatabase,
        callable $applyCode,
        callable $applyMigrations,
        callable $restoreDatabase,
        callable $restoreCode,
    ): array {
        $this->journal = [];

        if (!$this->signatureValid) {
            // Refused before anything is touched. There is no --force.
            return $this->outcome(false, false, $this->fromVersion,
                'the update package signature does not verify, so it is refused before anything is touched');
        }

        // 1. The backup FIRST. An update that migrates before it can go back is
        //    an update with no rollback, whatever the runbook says.
        $backupRef = $backupDatabase();
        $this->journal[] = "database backed up as {$backupRef} BEFORE any change";

        if (trim($backupRef) === '') {
            return $this->outcome(false, false, $this->fromVersion,
                'the pre-update backup produced no reference, so there is nothing to roll back to. An '
                . 'update that proceeds from here has no way back and does not know it');
        }

        try {
            $applyCode();
            $this->journal[] = "code moved to {$this->toVersion}";

            $applyMigrations();
            $this->journal[] = 'migrations applied';
        } catch (\Throwable $e) {
            // BOTH, and in the reverse order they were applied. Rolling back
            // only the code leaves version N running against a schema for N+1,
            // and every symptom then points somewhere else.
            $restoreDatabase($backupRef);
            $this->journal[] = "database restored from {$backupRef}";

            $restoreCode($this->fromVersion);
            $this->journal[] = "code restored to {$this->fromVersion}";

            return $this->outcome(false, true, $this->fromVersion,
                'the update failed and BOTH code and database were rolled back: ' . $e->getMessage());
        }

        return $this->outcome(true, false, $this->toVersion, null);
    }

    /** @return array{ok: bool, rolled_back: bool, version: string, journal: list<string>, failure: ?string} */
    private function outcome(bool $ok, bool $rolledBack, string $version, ?string $failure): array
    {
        return ['ok' => $ok, 'rolled_back' => $rolledBack, 'version' => $version,
            'journal' => $this->journal, 'failure' => $failure];
    }
}
