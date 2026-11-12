<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A WHATSAPP CAMPAIGN IS A TEMPLATE, A LIST OF PEOPLE, AND ONE ROW PER MESSAGE.
 *
 * SmartPRS 2's RecruitMessagingController (rev 94): compose a message with
 * placeholders, pick the pipeline candidates (or upload a name,mobile file),
 * send in bulk, then track delivery and each person's response. The ported
 * tables — `recruitment_campaigns`, `recruitment_messages` — are used as they are.
 *
 * NOTHING IS TRANSMITTED. There is no WhatsApp provider declared in this build,
 * so every message is created `queued` and stays queued; `sent` is a count of
 * provider acknowledgements and there is no provider. That is said on the screen
 * in the same words, because a campaign that shows "sent 40" over a gateway that
 * was never called is the claim this product keeps having to remove. The rest of
 * the feature — the list, the rendering, the response tracking, the export a
 * recruiter hands to whoever does send them — works today.
 *
 * Placeholders: {name} {mobile} {position} {company} {date} {venue} {time}.
 */
final class RecruitCampaigns
{
    public const CAMPAIGNS = 'recruitment_campaigns';
    public const MESSAGES = 'recruitment_messages';

    public const QUEUED = 'queued';
    public const PLACEHOLDERS = ['name', 'mobile', 'position', 'company', 'date', 'venue', 'time'];
    public const RESPONSES = ['', 'interested', 'not_interested', 'will_attend', 'attended', 'no_reply'];

    public function __construct(private readonly RowStore $store)
    {
    }

    /** @return list<array<string, mixed>> newest first */
    public function all(): array
    {
        $rows = $this->store->all(self::CAMPAIGNS);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_values($rows);
    }

    public function find(string $id): ?array
    {
        return $this->store->find(self::CAMPAIGNS, $id);
    }

    /** @return list<array<string, mixed>> */
    public function messages(string $campaignId): array
    {
        return array_values(array_filter($this->store->all(self::MESSAGES),
            static fn (array $r): bool => (string) ($r['campaign_id'] ?? '') === $campaignId));
    }

    /**
     * Create the campaign and one queued message per recipient.
     *
     * @param list<array{name: string, mobile: string, candidate_id?: string, position?: string}> $recipients
     * @param array<string, string> $vars the campaign-level placeholder values (company, date, venue, time)
     * @return array{ok: bool, why: string, campaign?: array<string, mixed>, created?: int, skipped?: int}
     */
    public function create(string $title, string $template, string $purpose, array $recipients, array $vars, string $by, ?string $jobId = null): array
    {
        $title = trim($title);
        $template = trim($template);

        if ($title === '' || $template === '') {
            return ['ok' => false, 'why' => 'A campaign needs a title and a message.'];
        }

        preg_match_all('/\{([a-z_]+)\}/', $template, $m);
        $unknown = array_diff(array_unique($m[1]), self::PLACEHOLDERS);

        if ($unknown !== []) {
            return ['ok' => false, 'why' => 'Unknown placeholder(s): {' . implode('} {', $unknown) . '}. Available: {' . implode('} {', self::PLACEHOLDERS) . '}.'];
        }

        $valid = [];
        $skipped = 0;
        $seen = [];

        foreach ($recipients as $r) {
            $mobile = preg_replace('/[^\d+]/', '', (string) ($r['mobile'] ?? '')) ?? '';

            if (strlen(preg_replace('/\D/', '', $mobile) ?? '') < 10 || isset($seen[$mobile])) {
                $skipped++;

                continue;
            }

            $seen[$mobile] = true;
            $valid[] = $r + ['mobile' => $mobile];
        }

        if ($valid === []) {
            return ['ok' => false, 'why' => 'Nobody to message: no recipient had a usable mobile number.'];
        }

        $campaign = $this->store->put(self::CAMPAIGNS, [
            'job_id' => $jobId, 'title' => $title, 'template' => $template, 'purpose' => trim($purpose) ?: null,
            'vars_json' => json_encode($vars), 'total' => count($valid), 'sent' => 0, 'failed' => 0, 'created_by' => $by,
        ]);

        foreach ($valid as $r) {
            $this->store->put(self::MESSAGES, [
                'campaign_id' => (string) $campaign['id'],
                'candidate_id' => (string) ($r['candidate_id'] ?? '') ?: null,
                'name' => (string) $r['name'],
                'mobile' => (string) $r['mobile'],
                'template' => self::render($template, ['name' => (string) $r['name'], 'mobile' => (string) $r['mobile'],
                    'position' => (string) ($r['position'] ?? '')] + $vars),
                'status' => self::QUEUED,
            ]);
        }

        return ['ok' => true, 'campaign' => $campaign, 'created' => count($valid), 'skipped' => $skipped,
            'why' => count($valid) . ' message(s) queued for "' . $title . '"' . ($skipped > 0 ? ", {$skipped} skipped (no usable mobile or duplicate)" : '')
                . '. Nothing has been transmitted: no WhatsApp provider is declared.'];
    }

    /** Record what the person said back — typed by the recruiter, since no channel reports it. */
    public function respond(string $messageId, string $response): array
    {
        if (!in_array($response, self::RESPONSES, true)) {
            return ['ok' => false, 'why' => "'{$response}' is not a response."];
        }

        if ($this->store->find(self::MESSAGES, $messageId) === null) {
            return ['ok' => false, 'why' => 'No such message.'];
        }

        $this->store->put(self::MESSAGES, ['id' => $messageId, 'response' => $response ?: null,
            'responded_at' => $response === '' ? null : date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => 'Response recorded.'];
    }

    /** Parse a name,mobile file (header optional). @return list<array{name: string, mobile: string}> */
    public static function recipientsFromCsv(string $csv): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) ?: [] as $line) {
            $cells = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv($line));

            if (count($cells) < 2 || strtolower($cells[0]) === 'name') {
                continue;
            }

            $out[] = ['name' => $cells[0], 'mobile' => $cells[1], 'position' => $cells[2] ?? ''];
        }

        return $out;
    }

    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => (string) ($vars[$m[1]] ?? ''), $template) ?? $template;
    }

    public function csv(string $campaignId): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, ['Name', 'Mobile', 'Message', 'Status', 'Response', 'Responded at']);

        foreach ($this->messages($campaignId) as $r) {
            fputcsv($out, [(string) $r['name'], (string) $r['mobile'], (string) $r['template'], (string) $r['status'],
                (string) ($r['response'] ?? ''), (string) ($r['responded_at'] ?? '')]);
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }
}
