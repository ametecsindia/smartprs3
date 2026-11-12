<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE JOB POST, AND WHERE IT WENT (owner, 12 Oct 2026).
 *
 * An approved requisition gets ONE job post: the description, skills,
 * experience and salary range every portal asks for. HR copies it field by
 * field into Naukri, LinkedIn, Indeed… (the portal pack), or downloads it as a
 * Word file for the careers page and consultants, then records each portal it
 * went live on with that portal's job ID and expiry. Applicants downloaded from a
 * portal come back through Recruitment's Import candidates with that portal as
 * their source, so every posting shows what it brought.
 *
 * Portals are declared in config/recruitment.json → portals. No portal is called
 * from here: the portals' posting APIs are paid partner integrations, and HR
 * posting by hand is what every SmartPRS 2 customer does today.
 *
 * ponytail: the portal postings live on the post as a JSON list (`channels`)
 * rather than a second table — a post has a handful. A table when a report
 * needs to query postings across posts.
 */
final class JobPostings
{
    public const TABLE = 'job_postings';

    /** The fields a post carries beyond what the requisition already holds. */
    public const FIELDS = ['description', 'skills', 'experience_min', 'experience_max', 'ctc_min', 'ctc_max', 'show_salary',
        'location', 'education', 'notice_period', 'industry', 'functional_area', 'contact_email', 'walk_in'];

    private ?array $declared = null;

    public function __construct(private readonly RowStore $store, private readonly string $file)
    {
    }

    /** @return list<array{key: string, label: string, post_url: string, how_to_post: string, how_to_download: string}> */
    public function portals(): array
    {
        $this->declared ??= (array) json_decode((string) file_get_contents($this->file), true);

        return array_values(array_map(static fn (array $p): array => [
            'key' => (string) $p['key'], 'label' => (string) ($p['label'] ?? $p['key']), 'post_url' => (string) ($p['post_url'] ?? ''),
            'how_to_post' => (string) ($p['how_to_post'] ?? ''), 'how_to_download' => (string) ($p['how_to_download'] ?? ''),
        ], (array) ($this->declared['portals'] ?? [])));
    }

    public function portalLabel(string $key): string
    {
        foreach ($this->portals() as $p) {
            if ($p['key'] === $key) {
                return $p['label'];
            }
        }

        return ucwords(str_replace('_', ' ', $key));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->store->all(self::TABLE));
    }

    public function find(string $id): ?array
    {
        return $this->store->find(self::TABLE, $id);
    }

    public function forRequisition(string $jobId): ?array
    {
        foreach ($this->all() as $p) {
            if ((string) ($p['job_id'] ?? '') === $jobId) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Write (or update) the requisition's job post. Only an approved, open
     * requisition is advertised — a post for a position nobody sanctioned is a
     * hire nobody sanctioned, one step earlier.
     *
     * @param array<string, mixed> $requisition
     * @return array{ok: bool, why: string, posting?: array<string, mixed>}
     */
    public function save(array $input, array $requisition, string $by): array
    {
        if ((string) ($requisition['approval_status'] ?? '') !== 'approved' || (string) ($requisition['status'] ?? '') !== 'open') {
            return ['ok' => false, 'why' => "{$requisition['req_code']} is not approved and open, so it is not advertised yet."];
        }

        if (trim((string) ($input['description'] ?? '')) === '') {
            return ['ok' => false, 'why' => 'A job post needs a description — it is what candidates read on the portal.'];
        }

        foreach (['experience_min', 'experience_max', 'ctc_min', 'ctc_max'] as $n) {
            $v = trim((string) ($input[$n] ?? ''));

            if ($v !== '' && preg_match('/^\d+(\.\d{1,4})?$/', $v) !== 1) {
                return ['ok' => false, 'why' => "'{$v}' is not a number for " . str_replace('_', ' ', $n) . '.'];
            }
        }

        $row = $this->forRequisition((string) $requisition['id']) ?? ['job_id' => (string) $requisition['id'], 'status' => 'draft',
            'channels' => '[]', 'created_by' => $by];
        $row['title'] = (string) $requisition['title'];
        $row['openings'] = (int) $requisition['openings'];
        $row['engagement'] = (string) ($requisition['engagement'] ?? '') ?: null;

        foreach (self::FIELDS as $f) {
            $v = trim((string) ($input[$f] ?? ''));
            // The requisition already holds the salary band and the location: they are the default.
            $v === '' && in_array($f, ['ctc_min', 'ctc_max', 'location'], true) && $v = trim((string) ($requisition[$f] ?? ''));
            $row[$f] = $v === '' ? null : $v;
        }

        $row['show_salary'] = ($row['show_salary'] ?? '') === 'no' ? 'no' : 'yes';
        $row['updated_by'] = $by;
        $row = $this->store->put(self::TABLE, $row);

        return ['ok' => true, 'posting' => $row, 'why' => "Job post for {$requisition['req_code']} saved. Copy it to the portals from the portal pack, then record each one under Mark as posted."];
    }

    /**
     * The post as the portals ask for it, field label => text, in the order a
     * portal's form asks.
     *
     * @param array<string, mixed>|null $p
     * @return array<string, string>
     */
    public function pack(?array $p): array
    {
        if ($p === null) {
            return [];
        }

        $range = static fn (string $a, string $b, string $unit): string => $a === '' && $b === '' ? ''
            : ($a !== '' && $b !== '' ? "{$a} – {$b} {$unit}" : ($a !== '' ? "{$a}+ {$unit}" : "up to {$b} {$unit}"));
        $v = static fn (string $k): string => trim((string) ($p[$k] ?? ''));
        $salary = $v('show_salary') === 'no' ? 'Not disclosed' : $range(self::lakhs($v('ctc_min')), self::lakhs($v('ctc_max')), 'lakh per annum');

        return array_filter([
            'Job title' => $v('title'),
            'Job description' => $v('description'),
            'Key skills' => $v('skills'),
            'Work experience' => $range($v('experience_min'), $v('experience_max'), 'years'),
            'Annual salary (CTC)' => $salary,
            'Job location' => $v('location'),
            'Employment type' => ucwords(str_replace('_', ' ', $v('engagement'))),
            'Vacancies' => $v('openings'),
            'Education' => $v('education'),
            'Industry' => $v('industry'),
            'Department / functional area' => $v('functional_area'),
            'Notice period' => $v('notice_period'),
            'Walk-in details' => $v('walk_in'),
            'Apply / contact email' => $v('contact_email'),
        ], static fn (string $x): bool => $x !== '');
    }

    /** "250000" → "2.5" (lakhs, exact). */
    private static function lakhs(string $amount): string
    {
        if (preg_match('/^(\d+)(?:\.\d+)?$/', $amount, $m) !== 1) {
            return '';
        }

        $rupees = (int) $m[1];
        $frac = rtrim(str_pad((string) ($rupees % 100000), 5, '0', STR_PAD_LEFT), '0');

        return intdiv($rupees, 100000) . ($frac === '' ? '' : '.' . substr($frac, 0, 2));
    }

    /** The post as a Word-openable page — the careers page, WhatsApp groups, consultants. */
    public function doc(array $p, string $company): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $rows = '';

        foreach ($this->pack($p) as $label => $text) {
            $rows .= '<h3 style="margin:14px 0 4px;font:bold 12pt Calibri">' . $h($label) . '</h3><div style="font:11pt Calibri;white-space:pre-wrap">'
                . nl2br($h($text)) . '</div>';
        }

        return '<html><head><meta charset="utf-8"><title>' . $h((string) $p['title']) . '</title></head><body>'
            . '<h1 style="font:bold 18pt Calibri;margin:0">' . $h((string) $p['title']) . '</h1><p style="font:11pt Calibri;color:#555">' . $h($company) . '</p>'
            . $rows . '</body></html>';
    }

    /** @param array<string, mixed>|null $p @return list<array<string, string>> */
    public function channels(?array $p): array
    {
        $list = json_decode((string) ($p['channels'] ?? '[]'), true);

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Record that the post went live on a portal.
     *
     * @return array{ok: bool, why: string}
     */
    public function markPosted(string $id, array $input, string $by): array
    {
        $p = $this->find($id);

        if ($p === null) {
            return ['ok' => false, 'why' => 'No such job post.'];
        }

        $portal = (string) ($input['portal'] ?? '');

        if (!in_array($portal, array_column($this->portals(), 'key'), true)) {
            return ['ok' => false, 'why' => "'{$portal}' is not a declared portal."];
        }

        foreach (['posted_on', 'expires_on'] as $d) {
            $v = trim((string) ($input[$d] ?? ''));
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

            if (($d === 'posted_on' || $v !== '') && ($dt === false || $dt->format('Y-m-d') !== $v)) {
                return ['ok' => false, 'why' => "'{$v}' is not a date for " . str_replace('_', ' ', $d) . '.'];
            }
        }

        $list = $this->channels($p);
        $list[] = ['portal' => $portal, 'ref' => trim((string) ($input['ref'] ?? '')), 'url' => trim((string) ($input['url'] ?? '')),
            'posted_on' => (string) $input['posted_on'], 'expires_on' => trim((string) ($input['expires_on'] ?? '')),
            'status' => 'live', 'by' => $by];
        $p['channels'] = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $p['status'] = 'live';
        $this->store->put(self::TABLE, $p);

        return ['ok' => true, 'why' => $this->portalLabel($portal) . ' posting recorded. When applicants come in, download them from '
            . $this->portalLabel($portal) . ' and use Import candidates with ' . $this->portalLabel($portal) . ' as the portal.'];
    }

    /** Close one portal posting (kept on record). @return array{ok: bool, why: string} */
    public function closeChannel(string $id, int $index, string $by): array
    {
        $p = $this->find($id);
        $list = $this->channels($p);

        if ($p === null || !isset($list[$index])) {
            return ['ok' => false, 'why' => 'No such posting.'];
        }

        $list[$index]['status'] = 'closed';
        $list[$index]['closed_by'] = $by;
        $list[$index]['closed_on'] = date('Y-m-d');
        $p['channels'] = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        in_array('live', array_column($list, 'status'), true) || $p['status'] = 'closed';
        $this->store->put(self::TABLE, $p);

        return ['ok' => true, 'why' => $this->portalLabel((string) $list[$index]['portal']) . ' posting closed. It stays on record.'];
    }
}
