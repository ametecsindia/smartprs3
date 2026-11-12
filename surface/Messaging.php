<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * SEND MESSAGE: WHO, ON WHICH CHANNEL, AT WHAT ADDRESS — AND A QUEUE, NOT A SEND.
 *
 * SmartPRS 2 posted a broadcast to a controller that mailed, texted or
 * WhatsApped through whatever provider was active and logged one row with a
 * recipient count. This build has no transport configured, so a send writes one
 * row per recipient and channel to messages_log with the queued status from
 * config/communication.json, and that is what the screen says happened. A
 * transport, when one arrives, reads the queue; nothing here pretends to have
 * delivered.
 *
 * A RECIPIENT WITHOUT AN ADDRESS IS NAMED, NOT DROPPED. "Sent to 40" when three
 * of them had no mobile is the report that gets somebody a call from the fourth.
 */
final class Messaging
{
    public const COLLECTION = 'messages_log';

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly RowStore $store, private readonly string $file)
    {
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $decoded = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($decoded) || !is_array($decoded['channels'] ?? null) || !is_string($decoded['queued_status'] ?? null)) {
            throw new \RuntimeException("{$this->file} does not declare the communication channels. Refused rather than guessed: a message queued to a channel nobody declared is one nobody delivers.");
        }

        return $this->config = $decoded;
    }

    /** @return array<string, array<string, string>> channel key => {label, address, fallback?} */
    public function channels(): array
    {
        return $this->config()['channels'];
    }

    /**
     * Where one employee is reached on one channel, or '' when nowhere.
     *
     * @param array<string, mixed> $employee
     */
    public function addressOf(array $employee, string $channel): string
    {
        $rule = $this->channels()[$channel] ?? null;

        if ($rule === null) {
            return '';
        }

        foreach ([(string) ($rule['address'] ?? ''), (string) ($rule['fallback'] ?? '')] as $column) {
            $value = $column === '' ? '' : trim((string) ($employee[$column] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve recipients: one entry per employee and channel that has an
     * address, and the ones that have none, named per channel.
     *
     * @param list<array<string, mixed>> $employees
     * @param list<string> $channels
     * @return array{to: list<array{employee: array<string, mixed>, channel: string, address: string}>, missing: array<string, list<string>>}
     */
    public function resolve(array $employees, array $channels): array
    {
        $to = [];
        $missing = [];

        foreach ($channels as $channel) {
            if (!isset($this->channels()[$channel])) {
                throw new \InvalidArgumentException("'{$channel}' is not a channel config/communication.json declares.");
            }

            foreach ($employees as $employee) {
                $address = $this->addressOf($employee, $channel);

                if ($address === '') {
                    $missing[$channel][] = Employment::code($employee);

                    continue;
                }

                $to[] = ['employee' => $employee, 'channel' => $channel, 'address' => $address];
            }
        }

        return ['to' => $to, 'missing' => $missing];
    }

    /**
     * Queue one row per resolved recipient. Returns the batch id and the count.
     *
     * @param list<array{employee: array<string, mixed>, channel: string, address: string}> $to
     * @return array{batch: string, queued: int}
     */
    public function queue(array $to, string $target, string $subject, string $message, string $byId, string $byLabel, string $kind = 'message'): array
    {
        $batch = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $queued = 0;

        foreach ($to as $r) {
            $this->store->put(self::COLLECTION, [
                'kind' => $kind,
                'batch_id' => $batch,
                'target' => $target,
                'recipients' => 1,
                'employee_id' => (string) ($r['employee']['id'] ?? ''),
                'channel' => $r['channel'],
                'channels' => $r['channel'],
                'to_address' => $r['address'],
                'subject' => $subject,
                'message' => $message,
                'status' => (string) $this->config()['queued_status'],
                'sent_by' => ctype_digit($byId) ? $byId : null,
                'sent_by_label' => $byLabel,
                'sent_at' => $now,
            ]);
            $queued++;
        }

        return ['batch' => $batch, 'queued' => $queued];
    }

    /** @return list<array<string, mixed>> newest first, optionally one kind only */
    public function log(?string $kind = null): array
    {
        try {
            $rows = $this->store->all(self::COLLECTION);
        } catch (\Throwable) {
            return []; // a table this installation does not have yet is an empty log
        }

        $rows = array_values(array_filter($rows, static fn (array $r): bool
            => $kind === null || (string) ($r['kind'] ?? 'message') === $kind));
        usort($rows, static fn (array $a, array $b): int
            => strcmp((string) ($b['sent_at'] ?? ''), (string) ($a['sent_at'] ?? '')));

        return $rows;
    }
}
