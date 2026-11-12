<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE PLATFORM'S WHATSAPP — SmartPRS 2's WaService over Interakt: one approved template, its {{n}}
 * values, one Indian mobile. The Interakt key is pasted by the Super Admin under SaaS Platform →
 * WhatsApp API (Interakt) and stored encrypted (Secret). Every attempt is written to `wa_log`.
 *
 * A template is named by PURPOSE (welcome, payment, renewal, lead, otp): the approved row of that
 * purpose in WhatsApp Templates wins, else SmartPRS 2's default name (smartprs_<purpose>).
 */
final class WhatsApp
{
    public const DEFAULT_URL = 'https://api.interakt.ai/v1/public/message/';

    public function __construct(private readonly RowStore $store, private readonly PlatformSettings $settings, private readonly string $envFile)
    {
    }

    /** @return array{enabled: bool, url: string, country_code: string, has_key: bool} */
    public function config(): array
    {
        $s = $this->settings->get('whatsapp');

        return ['enabled' => (bool) ($s['enabled'] ?? false), 'url' => (string) ($s['url'] ?? '') ?: self::DEFAULT_URL,
            'country_code' => (string) ($s['country_code'] ?? '') ?: '+91', 'has_key' => (string) ($s['key'] ?? '') !== ''];
    }

    public function ready(): bool
    {
        $c = $this->config();

        return $c['enabled'] && $c['has_key'];
    }

    /** @param array<string, mixed> $posted */
    public function save(array $posted): void
    {
        $old = $this->settings->get('whatsapp');
        $url = trim((string) ($posted['url'] ?? ''));
        $this->settings->put('whatsapp', ['enabled' => !empty($posted['enabled']),
            'url' => filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://') ? $url : self::DEFAULT_URL,
            'country_code' => preg_match('/^\+\d{1,4}$/', trim((string) ($posted['country_code'] ?? ''))) === 1 ? trim((string) $posted['country_code']) : '+91',
            // Blank keeps the saved key; it is never shown again.
            'key' => trim((string) ($posted['key'] ?? '')) !== '' ? Secret::encrypt(trim((string) $posted['key']), $this->envFile) : (string) ($old['key'] ?? '')]);
    }

    public function templateFor(string $purpose): string
    {
        try {
            foreach ($this->store->all('wa_templates') as $t) {
                if ((string) $t['purpose'] === $purpose && (string) $t['status'] === 'approved') {
                    return (string) $t['name'];
                }
            }
        } catch (\Throwable) {
            // no wa_templates table yet: the default name below
        }

        return 'smartprs_' . $purpose;
    }

    /**
     * @param list<string> $values the {{1}}..{{n}} values, in order
     * @return array{ok: bool, why: string}
     */
    public function send(string $mobile, string $template, array $values, string $kind, string $language = 'en'): array
    {
        $phone = substr((string) preg_replace('/\D+/', '', $mobile), -10);
        $ok = false;
        $why = '';

        try {
            $s = $this->settings->get('whatsapp');
            $key = Secret::decrypt((string) ($s['key'] ?? ''), $this->envFile);

            if (!$this->ready() || $key === null || $key === '') {
                throw new \RuntimeException('WhatsApp (Interakt) is not set up — SaaS Platform → WhatsApp API (Interakt).');
            }

            if (strlen($phone) !== 10) {
                throw new \RuntimeException('No valid 10-digit mobile number to send to.');
            }

            $c = $this->config();
            $ch = curl_init($c['url']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $key, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['countryCode' => $c['country_code'], 'phoneNumber' => $phone, 'type' => 'Template',
                    'template' => ['name' => $template, 'languageCode' => $language, 'bodyValues' => array_values(array_map('strval', $values))]])]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $ok = $body !== false && $status >= 200 && $status < 300;
            $why = $ok ? 'Sent to ' . $phone . '.' : ($body === false ? 'Interakt not reachable: ' . $err : 'Interakt answered HTTP ' . $status . ': ' . substr((string) $body, 0, 300));
        } catch (\Throwable $e) {
            $why = $e->getMessage();
        }

        try {
            $now = date('Y-m-d H:i:s');
            $this->store->put('wa_log', ['mobile' => $phone ?: null, 'template' => $template, 'body_values' => json_encode(array_values($values)),
                'kind' => $kind, 'status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : $why, 'created_at' => $now, 'updated_at' => $now]);
        } catch (\Throwable $e) {
            error_log('wa_log write failed: ' . $e->getMessage()); // the send's own answer still goes back to the caller
        }

        return ['ok' => $ok, 'why' => $why];
    }
}
