<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Platform\Notification\Redactor;

/**
 * THE INTERVIEW PANEL IS TOLD (owner, 21 Oct 2026): once a round is scheduled and the panel picked,
 * every panelist gets a bell notification and an email with the candidate's details and the time,
 * and a popup on screen at the scheduled time (dueFor(), read by the page shell).
 *
 * The panel is stored as the Directory labels it was picked from ("CODE · Name, CODE · Name");
 * the codes are read back from that, so nothing new is stored on the interview.
 */
final class InterviewNotices
{
    public const EVENT = 'recruitment.interview.scheduled';

    /** @return list<string> the employee codes in a stored panel */
    public static function codes(string $panel): array
    {
        preg_match_all('/(?:^|,)\s*([^,·]+?)\s+·\s/u', $panel, $m);

        return array_values(array_unique(array_filter(array_map('trim', $m[1]))));
    }

    /**
     * The one-line values every message about this round uses.
     *
     * @param array<string, mixed> $interview @param array<string, mixed> $candidate @param array<string, mixed>|null $req
     * @return array<string, string>
     */
    public static function values(array $interview, array $candidate, ?array $req): array
    {
        $at = (int) strtotime((string) $interview['scheduled_at']);

        return [
            'candidate' => (string) $candidate['name'],
            'position' => (string) ($candidate['position'] ?? '') ?: '—',
            'requisition' => $req === null ? 'Talent pool' : (string) $req['req_code'],
            'round' => (string) $interview['round_label'],
            'when' => date('d-m-Y H:i', $at),
            'mode' => ucwords(str_replace('_', ' ', (string) $interview['mode'])),
            'panel' => (string) ($interview['panel'] ?? ''),
            'mobile' => (string) ($candidate['mobile'] ?? '') ?: '—',
            'email' => (string) ($candidate['email'] ?? '') ?: '—',
            'experience' => ((string) ($candidate['experience_years'] ?? '') ?: '—') . ' yrs',
            'resume' => (string) ($candidate['resume_url'] ?? '') ?: '—',
        ];
    }

    /**
     * Bell + email to every panelist. Never throws: a panel that could not be told is reported,
     * the interview stays scheduled.
     *
     * @param array<string, mixed> $interview @param array<string, mixed> $candidate @param array<string, mixed>|null $req
     * @return array{told: int, emailed: int, problems: list<string>}
     */
    public static function notify(RowStore $store, Notifications $bell, AlertEmails $mail, array $interview, array $candidate, ?array $req): array
    {
        $v = self::values($interview, $candidate, $req);
        $told = $emailed = 0;
        $problems = [];
        $byCode = [];

        foreach ($store->all('employees') as $e) {
            $byCode[Employment::code($e)] = $e;
        }

        $subject = "Interview scheduled: {$v['candidate']} — {$v['round']}, {$v['when']}";
        $body = "You are on the interview panel.\n\n"
            . "Candidate: {$v['candidate']}\nPosition: {$v['position']}\nRequisition: {$v['requisition']}\n"
            . "Round: {$v['round']}\nWhen: {$v['when']}\nMode: {$v['mode']}\nPanel: {$v['panel']}\n"
            . "Mobile: {$v['mobile']}\nEmail: {$v['email']}\nExperience: {$v['experience']}\nResume: {$v['resume']}";

        foreach (self::codes($v['panel']) as $code) {
            // The time is in the key, so a rescheduled round tells the panel again; the same round twice does not.
            $key = $interview['id'] . ':' . $interview['scheduled_at'] . ':' . $code;

            try {
                $told += $bell->queue(self::EVENT, $code, self::EVENT . ':' . $key, $v) ? 1 : 0;
            } catch (\Throwable $e) {
                $problems[] = "{$code}: " . $e->getMessage();
            }

            $e = $byCode[$code] ?? null;
            $to = trim((string) (($e['email'] ?? '') ?: ($e['personal_email'] ?? '')));

            if ($to === '') {
                $problems[] = "{$code} has no email address in the Directory";
                continue;
            }

            $r = $mail->send([$to], [], $subject, $body);
            $now = date('Y-m-d H:i:s');
            $store->put('notification_log', ['kind' => 'email.interview', 'title' => $subject, 'body' => Redactor::redact($body),
                'channels' => 'email', 'email_status' => $r['ok'] ? Outbox::SENT : Outbox::FAILED,
                'dedupe_key' => ($r['ok'] ? 'mail:interview:' : 'mailfail:interview:') . $key . ':' . $now,
                'meta' => json_encode(['recipient' => $code, 'to' => [$to], 'cc' => [], 'result' => $r['why']], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now]);
            if ($r['ok']) {
                $emailed++;
            } else {
                $problems[] = "email to {$code}: " . $r['why'];
            }
        }

        return ['told' => $told, 'emailed' => $emailed, 'problems' => array_values(array_unique($problems))];
    }

    /**
     * Rounds still scheduled with this employee on the panel, from 30 minutes ago to $ahead seconds on:
     * what the page shell turns into a popup at the scheduled time.
     *
     * @return list<array{id: string, at: int, title: string, body: string, url: string}>
     */
    public static function dueFor(RowStore $store, string $code, int $now, int $ahead = 86400): array
    {
        if ($code === '') {
            return [];
        }

        $out = [];

        foreach ($store->all(Recruitment::INTERVIEWS) as $iv) {
            $at = (int) strtotime((string) ($iv['scheduled_at'] ?? ''));

            if ((string) ($iv['status'] ?? '') !== 'scheduled' || $at < $now - 1800 || $at > $now + $ahead
                || !in_array($code, self::codes((string) ($iv['panel'] ?? '')), true)) {
                continue;
            }

            $c = $store->find(Recruitment::CANDIDATES, (string) $iv['candidate_id']);

            if ($c === null) {
                continue;
            }

            $v = self::values($iv, $c, null);
            $out[] = ['id' => (string) $iv['id'], 'at' => $at, 'title' => "Interview now: {$v['candidate']}",
                'body' => "{$v['round']} · {$v['when']} · {$v['mode']}\nPosition: {$v['position']}\nMobile: {$v['mobile']} · Email: {$v['email']}\nPanel: {$v['panel']}",
                'url' => '?r=recruitment&c=' . rawurlencode((string) $c['id'])];
        }

        return $out;
    }
}
