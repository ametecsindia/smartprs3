<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE PLATFORM MAILBOX — SmartPRS 2's "Platform Email (SMTP)": the FROM address for what the platform
 * itself sends (new-workspace credentials, demo passkeys, website-lead alerts, test mails). Saved by
 * the Super Admin under SaaS Platform → Platform Email; the password is stored encrypted (Secret).
 *
 * A tenant's own alert mail keeps using that tenant's own server (Alert Email Drafts → Mail server);
 * this never sends a tenant's alerts.
 */
final class PlatformMail
{
    public const DEFAULTS = ['enabled' => false, 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '',
        'password' => '', 'from_email' => '', 'from_name' => 'SmartPRS', 'reply_to' => ''];

    public function __construct(private readonly PlatformSettings $settings, private readonly string $envFile)
    {
    }

    /** @return array<string, mixed> without the password, plus has_password */
    public function config(): array
    {
        $s = array_replace(self::DEFAULTS, $this->settings->get('smtp'));
        $s['has_password'] = (string) $s['password'] !== '';
        unset($s['password']);

        return $s;
    }

    public function ready(): bool
    {
        $s = $this->config();

        return (bool) $s['enabled'] && (string) $s['host'] !== '' && (string) $s['from_email'] !== '';
    }

    /** @param array<string, mixed> $posted */
    public function save(array $posted): void
    {
        $old = array_replace(self::DEFAULTS, $this->settings->get('smtp'));
        $new = ['enabled' => !empty($posted['enabled']), 'host' => trim((string) ($posted['host'] ?? '')),
            'port' => max(1, (int) ($posted['port'] ?? 587)),
            'encryption' => in_array($posted['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? (string) $posted['encryption'] : 'tls',
            'username' => trim((string) ($posted['username'] ?? '')), 'from_email' => strtolower(trim((string) ($posted['from_email'] ?? ''))),
            'from_name' => trim((string) ($posted['from_name'] ?? '')) ?: 'SmartPRS', 'reply_to' => trim((string) ($posted['reply_to'] ?? '')),
            // A blank password field keeps the saved one; it is never echoed back to the form.
            'password' => (string) ($posted['password'] ?? '') !== '' ? Secret::encrypt((string) $posted['password'], $this->envFile) : (string) $old['password']];
        $this->settings->put('smtp', $new);
    }

    /**
     * One email from the platform mailbox. Throws nothing.
     *
     * @param list<string> $to
     * @param array<string, string> $lines label => value, printed as a small table under the text
     * @return array{ok: bool, why: string}
     */
    public function send(array $to, string $subject, string $text, array $lines = [], array $attachments = []): array
    {
        if (!$this->ready()) {
            return ['ok' => false, 'why' => 'The platform mailbox is not set up (SaaS Platform → Platform Email (SMTP)).'];
        }

        $s = array_replace(self::DEFAULTS, $this->settings->get('smtp'));
        $s['password'] = (string) (Secret::decrypt((string) $s['password'], $this->envFile) ?? '');

        if ($s['encryption'] === 'none') {
            $s['encryption'] = '';
        }

        $plain = $text;
        $rows = '';

        foreach ($lines as $label => $value) {
            $plain .= "\n" . $label . ': ' . $value;
            $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#64748b">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</td><td style="padding:4px 0;font-weight:600">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.55;color:#1f2937">'
            . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))
            . ($rows === '' ? '' : '<table style="margin-top:12px;border-collapse:collapse">' . $rows . '</table>')
            . '<p style="margin-top:18px;color:#94a3b8;font-size:12px">SmartPRS by Ametecs India Pvt. Ltd.</p></div>';

        return (new Smtp())->send($s, $to, [], $subject, $plain, $html, 20 + (int) (array_sum(array_map(static fn ($a): int => strlen((string) $a[1]), $attachments)) / 200000), $attachments);
    }
}
