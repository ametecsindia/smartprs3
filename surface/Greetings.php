<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * BIRTHDAY AND WORK-ANNIVERSARY GREETINGS: WHO IS DUE, FROM DOB AND DOJ.
 *
 * SmartPRS 2's GreetingsService ran once a day at a chosen hour and mailed
 * everybody whose birthday or anniversary fell on that date. There is no
 * scheduler and no transport in this build, so the screen shows who is due today
 * and this week, and a click queues the greeting through Messaging like any other
 * message. The wording is config/communication.json and is rendered by the letter
 * engine, so a greeting with a blank where the name belongs is refused, not sent.
 *
 * ponytail: a 29 February birthday is due on 28 February in a year without one.
 * Upgrade path if anybody minds: a per-tenant choice in the config.
 */
final class Greetings
{
    public function __construct(private readonly Messaging $messaging, private readonly Letters $letters)
    {
    }

    /** @return array<string, mixed> the greetings block of the config */
    public function config(): array
    {
        $g = $this->messaging->config()['greetings'] ?? null;

        if (!is_array($g) || !is_array($g['birthday'] ?? null) || !is_array($g['anniversary'] ?? null)) {
            throw new \RuntimeException('config/communication.json declares no greetings (birthday, anniversary).');
        }

        return $g;
    }

    /**
     * Everybody due between $today and $today + $daysAhead, nearest first.
     *
     * @param list<array<string, mixed>> $employees
     * @return list<array{employee: array<string, mixed>, kind: string, on: string, in_days: int, years: int}>
     */
    public function due(array $employees, string $today, ?int $daysAhead = null): array
    {
        $cfg = $this->config();
        $daysAhead ??= (int) ($cfg['week_days_ahead'] ?? 7);
        $start = new \DateTimeImmutable($today);
        $due = [];

        foreach ($employees as $e) {
            foreach (['birthday' => 'dob', 'anniversary' => 'doj'] as $kind => $column) {
                if (!($cfg[$kind]['enabled'] ?? false)) {
                    continue;
                }

                $raw = trim((string) ($e[$column] ?? ''));

                if ($raw === '' || $raw === '0000-00-00' || preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
                    continue;
                }

                $origin = new \DateTimeImmutable(substr($raw, 0, 10));
                $next = self::nextOccurrence($origin, $start);
                $inDays = (int) $start->diff($next)->days;

                if ($inDays > $daysAhead) {
                    continue;
                }

                $years = (int) $next->format('Y') - (int) $origin->format('Y');

                if ($kind === 'anniversary' && $years < (int) ($cfg['anniversary']['min_years'] ?? 1)) {
                    continue;
                }

                $due[] = ['employee' => $e, 'kind' => $kind, 'on' => $next->format('Y-m-d'), 'in_days' => $inDays, 'years' => $years];
            }
        }

        usort($due, static fn (array $a, array $b): int
            => [$a['in_days'], Employment::code($a['employee']), $a['kind']] <=> [$b['in_days'], Employment::code($b['employee']), $b['kind']]);

        return $due;
    }

    /** The first anniversary of $origin on or after $from. */
    public static function nextOccurrence(\DateTimeImmutable $origin, \DateTimeImmutable $from): \DateTimeImmutable
    {
        $md = $origin->format('m-d');

        foreach ([(int) $from->format('Y'), (int) $from->format('Y') + 1] as $year) {
            $candidate = $md === '02-29' && !checkdate(2, 29, $year)
                ? new \DateTimeImmutable(sprintf('%d-02-28', $year))
                : new \DateTimeImmutable(sprintf('%d-%s', $year, $md));

            if ($candidate >= $from) {
                return $candidate;
            }
        }

        throw new \LogicException('An anniversary recurs every year; one of two consecutive years must hold it.');
    }

    /**
     * The greeting text for one due entry, rendered by the letter engine.
     *
     * @param array{employee: array<string, mixed>, kind: string, years: int} $entry
     * @return array{ok: bool, subject: string, body: string, why: string, channel: string}
     */
    public function render(array $entry, string $company): array
    {
        $tpl = $this->config()[$entry['kind']];
        $context = ['company' => $company, 'years' => (string) $entry['years']];
        $subject = $this->letters->render(['body' => (string) $tpl['subject']], $entry['employee'], $context);
        $body = $this->letters->render(['body' => (string) $tpl['message']], $entry['employee'], $context);
        $ok = $subject['ok'] && $body['ok'];

        return ['ok' => $ok, 'subject' => $subject['body'], 'body' => $body['body'],
            'why' => $ok ? 'Every placeholder resolved.' : ($subject['ok'] ? $body['why'] : $subject['why']),
            'channel' => (string) ($tpl['channel'] ?? 'email')];
    }

    /**
     * Was this greeting already queued for this person on this date? Stops a
     * second click sending a second "happy birthday".
     */
    public function alreadyQueued(string $employeeId, string $kind, string $on): bool
    {
        foreach ($this->messaging->log('greeting') as $row) {
            if ((string) ($row['employee_id'] ?? '') === $employeeId
                && (string) ($row['target'] ?? '') === $kind . ' ' . $on) {
                return true;
            }
        }

        return false;
    }
}
