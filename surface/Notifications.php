<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Platform\Notification\Redactor;

/**
 * WHAT THE PRODUCT WOULD TELL SOMEBODY — queued, never pretended sent.
 *
 * `Platform\Notification\Outbox` is a finished class with a careful contract:
 * queued, sent, delivered, and delivered ONLY on a provider acknowledgement,
 * because marking delivered when an API call returns 200 is how a payroll team
 * believes four hundred payslip notifications went out on the 1st when the
 * gateway silently dropped every one. Nothing had ever queued a message into it.
 * A leave request was approved or rejected and the employee found out by opening
 * the screen and looking.
 *
 * NOTHING HERE TRANSMITS ANYTHING, and that is a decision rather than an
 * omission. No SMTP server, no SMS gateway and no push provider is configured in
 * this build, and inventing one would be worse than the gap: `mail()` against an
 * unconfigured host returns true on plenty of systems, which would mark messages
 * SENT that nobody ever received — precisely the failure `Outbox` exists to make
 * impossible. Every message stays QUEUED, `config/notifications.json` says so in
 * its own file, and the screen says so on every row.
 *
 * REDACTED ON THE WAY IN, through the platform's own `Redactor`. A message log is
 * the least-guarded copy of everything the system ever sent — read by support,
 * exported for debugging, retained long after the contents stopped mattering to
 * anyone except an attacker.
 *
 * IDEMPOTENT BY DEDUPE KEY. Deciding a leave request twice, or a screen that
 * re-renders, must not queue a second copy: an employee receiving the same
 * rejection four times is the version of this failure that reaches the founder.
 */
final class Notifications
{
    private const COLLECTION = 'notification_log';

    /**
     * A message for EVERY LOGIN HOLDING A ROLE rather than for one employee
     * code — "role:HR Admin". Probation reminders go to the Admin and HR Admin
     * roles (owner, 28 Sep), and the administrator often has no employee
     * record, so a code-only recipient would have reached nobody. The old
     * `hr` recipient did exactly that: every probation reminder ever queued
     * was addressed to a code no one holds.
     */
    public const ROLE_PREFIX = 'role:';

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
    ) {
    }

    /**
     * Queue one message. Returns false when this exact message is already there.
     *
     * @param array<string, string> $values substituted into the declared template
     */
    public function queue(string $event, string $recipient, string $dedupeKey,
        array $values = []): bool
    {
        $declared = $this->events()[$event] ?? null;

        // A FAMILY OF EVENTS SHARES ITS PARENT'S TEMPLATE: `request.raised.module.advances`
        // is worded by `request.raised` unless it is declared itself. Still declared —
        // only the nearest declared ancestor, never an invented subject line.
        for ($parent = $event; !is_array($declared) && str_contains($parent, '.');) {
            $parent = substr($parent, 0, (int) strrpos($parent, '.'));
            $declared = $this->events()[$parent] ?? null;
        }

        if (!is_array($declared)) {
            // NAMED, NOT SILENT. An event nobody declared is a message nobody
            // gets, and the alternative — inventing a subject line from the
            // event code — sends a customer something no one wrote.
            throw new \InvalidArgumentException(
                "config/notifications.json declares no event '{$event}', so there is no message to "
                . 'send. Declare it there rather than composing one here.'
            );
        }

        foreach ($this->store->all(self::COLLECTION) as $existing) {
            if ((string) ($existing['dedupe_key'] ?? '') === $dedupeKey) {
                return false;
            }
        }

        $substitute = static function (string $template) use ($values): string {
            foreach ($values as $name => $value) {
                $template = str_replace('{' . $name . '}', $value, $template);
            }

            return $template;
        };

        $this->store->put(self::COLLECTION, [
            'kind' => $event,
            'title' => $substitute((string) ($declared['title'] ?? $event)),
            // Redacted BEFORE it is stored, not before it is displayed. A
            // redaction applied on the way out is a redaction that does not
            // apply to the database, the backup or the diagnostic export.
            'body' => Redactor::redact($substitute((string) ($declared['body'] ?? ''))),
            'channels' => implode(',', (array) ($declared['channels'] ?? [])),
            // QUEUED, and it will stay queued. Nothing in this build sends.
            'email_status' => Outbox::QUEUED,
            'dedupe_key' => $dedupeKey,
            'meta' => json_encode(['recipient' => $recipient], JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Mark one message read by the person it is for. Returns false when the
     * message is not theirs (or does not exist) — nothing is marked.
     */
    public function markRead(string $id, string $recipient, bool $seesAll, string $now, array $alsoFor = []): bool
    {
        $row = $this->store->find(self::COLLECTION, $id);

        if ($row === null) {
            return false;
        }

        $meta = json_decode((string) ($row['meta'] ?? ''), true);
        $for = is_array($meta) ? (string) ($meta['recipient'] ?? '') : '';
        $mine = array_values(array_filter([$recipient, ...array_map('strval', $alsoFor)], static fn (string $r): bool => $r !== ''));

        if (!$seesAll && ($for === '' || !in_array($for, $mine, true))) {
            return false;
        }

        if (trim((string) ($row['read_at'] ?? '')) === '') {
            $this->store->put(self::COLLECTION, ['id' => $row['id'], 'read_at' => $now]);
        }

        return true;
    }

    /** The screen a message points at: its own url, else config/notification-routes.json by kind prefix (longest first). */
    public static function routeFor(array $message, array $routes): string
    {
        $own = trim((string) ($message['url'] ?? ''));

        if ($own !== '') {
            return $own;
        }

        $kind = (string) ($message['kind'] ?? '');
        $keys = array_keys($routes);
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($keys as $prefix) {
            if (str_starts_with($kind, (string) $prefix)) {
                return '?r=' . (string) $routes[$prefix];
            }
        }

        return '';
    }

    /**
     * Everything queued, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function queued(int $limit = 50): array
    {
        $rows = array_values(array_filter(
            $this->store->all(self::COLLECTION),
            static fn (array $r): bool => (string) ($r['email_status'] ?? '') === Outbox::QUEUED,
        ));

        usort($rows, static fn (array $a, array $b): int
            => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /**
     * Everything queued FOR ONE RECIPIENT, newest first.
     *
     * The queue used to be shown whole to whoever opened the inbox, which put a
     * colleague's absence notice — their code, their date — on a viewer's screen.
     * The scope sweep found it the first time a message existed that was about
     * somebody other than the reader. A recipient is the employee code the
     * message was queued for, and a message with no recipient is nobody's.
     *
     * @return list<array<string, mixed>>
     */
    public function queuedFor(string $recipient, int $limit = 50, array $alsoFor = []): array
    {
        $mine = array_values(array_filter([$recipient, ...array_map('strval', $alsoFor)], static fn (string $r): bool => $r !== ''));

        if ($mine === []) {
            return [];
        }

        return array_slice(array_values(array_filter($this->queued(PHP_INT_MAX),
            static function (array $r) use ($mine): bool {
                $meta = json_decode((string) ($r['meta'] ?? ''), true);

                return is_array($meta) && in_array((string) ($meta['recipient'] ?? ''), $mine, true);
            })), 0, $limit);
    }

    /** Unread messages for a viewer (their code, plus their role's), for the top-bar bell. */
    public function unreadFor(string $recipient, array $alsoFor = []): int
    {
        return count(array_filter($this->queuedFor($recipient, PHP_INT_MAX, $alsoFor),
            static fn (array $m): bool => trim((string) ($m['read_at'] ?? '')) === ''));
    }

    /**
     * Stored bodies that still carry something the redactor should have removed.
     *
     * Asked of the STORE rather than of an in-memory outbox, because the store is
     * what a backup and a support export actually contain. A redaction proved
     * only against objects in memory is a redaction proved against the one copy
     * nobody can read anyway.
     *
     * @return list<string> the ids of leaking rows
     */
    public function leaking(): array
    {
        $leaking = [];

        foreach ($this->store->all(self::COLLECTION) as $row) {
            if (Redactor::leaks((string) ($row['body'] ?? ''))) {
                $leaking[] = (string) ($row['id'] ?? '?');
            }
        }

        return $leaking;
    }

    /** True when a transport is declared. It is not, and the screen says so. */
    public function transportConfigured(): bool
    {
        $transport = $this->config()['transport'] ?? [];

        return is_array($transport) && ($transport['configured'] ?? false) === true;
    }

    public function transportNote(): string
    {
        $transport = $this->config()['transport'] ?? [];

        return is_array($transport) ? (string) ($transport['note'] ?? '') : '';
    }

    /** @return array<string, mixed> */
    public function events(): array
    {
        $events = $this->config()['events'] ?? [];

        return is_array($events) ? $events : [];
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'config/notifications.json is missing or unreadable. Refused rather than treated as '
                . '"no events", which would silently stop telling everybody everything.'
            );
        }

        // DROP-IN EVENTS: config/notifications.d/<section>.json holds {"events": {...}};
        // a section ships the messages it sends beside its screens instead of
        // editing the shared file. A key declared twice: the drop-in wins.
        $dir = dirname($this->file) . '/' . pathinfo($this->file, PATHINFO_FILENAME) . '.d';

        foreach (glob($dir . '/*.json') ?: [] as $dropIn) {
            $more = json_decode((string) file_get_contents($dropIn), true);

            if (!is_array($more) || !is_array($more['events'] ?? null)) {
                throw new \RuntimeException("The notification drop-in {$dropIn} is not {\"events\": {...}}.");
            }

            $decoded['events'] = array_merge((array) ($decoded['events'] ?? []), $more['events']);
        }

        return $this->config = $decoded;
    }
}
