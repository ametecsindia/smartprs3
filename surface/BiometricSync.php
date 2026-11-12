<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * PULLING PUNCHES FROM A BIOMETRIC SERVER — SmartPRS 2's ETimeOfficeService::fetch,
 * ETimeTrackLiteService and GenericApiService, for the Biometric Device Setup rows
 * whose provider is Etimeoffice, Etimetracklite or Generic. (Push devices come in
 * through public/iclock.php instead.) Everything lands through PunchIngest.
 *
 *   eTimeOffice     GET {base}/{endpoint}?Empcode=&FromDate=d/m/Y_H:i&ToDate=…, Basic corp:user:pass:true
 *   eTimeTrackLite  SOAP GetTransactionsLog on …/iclock/WebAPIservice.asmx (serial + API user)
 *   Generic         the JSON recipe in api_config: url/method/headers/body, optional token login,
 *                   response json|xml|delimited, record_path, map {emp, datetime|date+time, direction, machine}
 *
 * Run by tools/cron.php every few minutes (each row honours its own sync_interval_min)
 * and by the "Sync now" / "Test" buttons on Biometric Mapping.
 */
final class BiometricSync
{
    public function __construct(private readonly RowStore $store, private readonly \PDO $pdo, private readonly string $tenantId, private readonly string $root)
    {
    }

    public static function provider(array $cfg): string
    {
        return strtolower(trim((string) ($cfg['provider'] ?? '')));
    }

    /** Is this row due a pull now? */
    public static function due(array $cfg, int $now): bool
    {
        if (!(bool) ($cfg['enabled'] ?? false) || !in_array(self::provider($cfg), ['etimeoffice', 'etimetracklite', 'generic'], true)) {
            return false;
        }

        $every = max(5, (int) ($cfg['sync_interval_min'] ?? 60) ?: 60);
        $last = strtotime((string) ($cfg['last_sync_at'] ?? '')) ?: 0;

        return $now - $last >= $every * 60;
    }

    /**
     * Pull [from, to] and ingest. Returns the outcome and writes it on the row.
     *
     * @return array{ok: bool, fetched: int, accepted: int, pending: int, duplicates: int, error: ?string, sample: string}
     */
    public function run(array $cfg, int $days = 1, bool $dryRun = false): array
    {
        $to = new \DateTimeImmutable('now');
        $from = $to->modify('-' . max(1, min(31, $days)) . ' days');
        $cfg['password'] = Secret::decrypt((string) ($cfg['password'] ?? ''), $this->root . '/.env') ?? (string) ($cfg['password'] ?? '');

        $fetched = match (self::provider($cfg)) {
            'etimeoffice' => $this->etimeoffice($cfg, $from, $to),
            'etimetracklite' => $this->etimetracklite($cfg, $from, $to),
            'generic' => $this->generic($cfg, $from, $to),
            default => ['ok' => false, 'rows' => [], 'error' => 'Provider "' . (string) ($cfg['provider'] ?? '') . '" is not pulled — Push devices send their punches themselves.', 'body' => ''],
        };

        $out = ['ok' => $fetched['ok'], 'fetched' => count($fetched['rows']), 'accepted' => 0, 'pending' => 0, 'duplicates' => 0,
            'error' => $fetched['error'], 'sample' => mb_substr((string) $fetched['body'], 0, 600)];

        if ($fetched['ok'] && !$dryRun) {
            $punches = [];

            foreach ($fetched['rows'] as $r) {
                $punches[] = ['device_sn' => (string) ($r['machine'] ?? '') ?: (string) ($cfg['serial_number'] ?? ''), 'device_user_id' => $r['emp'],
                    'punch_at' => $r['at'], 'direction' => $r['dir'] ?? 'unknown'];
            }

            $res = (new PunchIngest($this->store, $this->pdo, $this->tenantId))->ingest($punches, self::provider($cfg),
                ['emp_prefix' => $cfg['emp_prefix'] ?? '', 'device_mode' => $cfg['device_mode'] ?? '']);
            $out['accepted'] = $res['batch']['accepted'];
            $out['pending'] = $res['batch']['pending'];
            $out['duplicates'] = $res['batch']['duplicates'];
        }

        if (!$dryRun && isset($cfg['id'])) {
            $this->store->put('biometric_configs', ['id' => $cfg['id'], 'last_sync_at' => date('Y-m-d H:i:s'), 'last_count' => $out['accepted'],
                'last_status' => mb_substr($out['ok'] ? 'OK ' . $out['fetched'] . ' fetched, ' . $out['accepted'] . ' new, ' . $out['pending'] . ' unmapped' : 'ERROR ' . (string) $out['error'], 0, 190)]);
        }

        return $out;
    }

    // ------------------------------------------------------------- providers

    /** @return array{ok: bool, rows: list<array<string, string>>, error: ?string, body: string} */
    private function etimeoffice(array $cfg, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (trim((string) ($cfg['corp_id'] ?? '')) === '' || trim((string) ($cfg['username'] ?? '')) === '' || $cfg['password'] === '') {
            return ['ok' => false, 'rows' => [], 'error' => 'Corporate ID, API username and password are all needed.', 'body' => ''];
        }

        $url = rtrim((string) ($cfg['base_url'] ?? '') ?: 'https://api.etimeoffice.com/api', '/') . '/' . trim((string) ($cfg['endpoint'] ?? '') ?: 'DownloadPunchDataMCID', '/')
            . '?' . http_build_query(['Empcode' => (string) ($cfg['empcode'] ?? '') ?: 'ALL', 'FromDate' => $from->format('d/m/Y_H:i'), 'ToDate' => $to->format('d/m/Y_H:i')]);
        $r = self::http('GET', $url, [], '', [$cfg['corp_id'] . ':' . $cfg['username'] . ':' . $cfg['password'] . ':true', $cfg['password']]);

        if (!$r['ok']) {
            return ['ok' => false, 'rows' => [], 'error' => $r['error'], 'body' => $r['body']];
        }

        $json = json_decode($r['body'], true);
        $data = is_array($json) ? ($json['PunchData'] ?? $json['punchData'] ?? $json['Data'] ?? (array_is_list($json) ? $json : [])) : [];
        $rows = [];

        foreach (is_array($data) ? $data : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            [$emp, $dt, $dir, $mc] = array_is_list($item)
                ? [$item[0] ?? null, $item[2] ?? null, $item[3] ?? null, $item[4] ?? null]
                : [self::pick($item, ['Empcode', 'EmployeeCode', 'Code']), self::pick($item, ['DateTime', 'PunchDate', 'PunchDateTime', 'DownloadDate']),
                    self::pick($item, ['INOUT', 'InOut', 'INOUTStatus', 'Status', 'Direction']), self::pick($item, ['MachineNo', 'MachineNumber', 'Machine', 'MCID', 'MCNo', 'DeviceId'])];
            $at = self::date((string) $dt, ['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y_H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i']);

            if (trim((string) $emp) !== '' && $at !== null) {
                $rows[] = ['emp' => trim((string) $emp), 'at' => $at, 'machine' => trim((string) $mc), 'dir' => self::direction($cfg, (string) $mc, (string) $dir)];
            }
        }

        return ['ok' => true, 'rows' => $rows, 'error' => null, 'body' => $r['body']];
    }

    /** @return array{ok: bool, rows: list<array<string, string>>, error: ?string, body: string} */
    private function etimetracklite(array $cfg, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $u = trim((string) ($cfg['base_url'] ?? ''));

        if ($u === '' || trim((string) ($cfg['serial_number'] ?? '')) === '' || trim((string) ($cfg['username'] ?? '')) === '') {
            return ['ok' => false, 'rows' => [], 'error' => 'WebAPI URL, device serial and API user are all needed.', 'body' => ''];
        }

        $u = preg_match('~^https?://~i', $u) === 1 ? $u : 'http://' . $u;

        if (preg_match('~\.asmx(\?|$)~i', $u) !== 1 && ($p = parse_url($u)) !== false && !empty($p['host'])) {
            $u = ($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/iclock/WebAPIservice.asmx';
        }

        $x = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $envelope = '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<GetTransactionsLog xmlns="http://tempuri.org/"><FromDateTime>' . $x($from->format('Y/m/d H:i:s')) . '</FromDateTime><ToDateTime>' . $x($to->format('Y/m/d H:i:s'))
            . '</ToDateTime><SerialNumber>' . $x((string) $cfg['serial_number']) . '</SerialNumber><UserName>' . $x((string) $cfg['username']) . '</UserName><UserPassword>'
            . $x((string) $cfg['password']) . '</UserPassword><strDataList></strDataList></GetTransactionsLog></soap:Body></soap:Envelope>';
        $r = self::http('POST', $u, ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: http://tempuri.org/GetTransactionsLog'], $envelope);

        if (!$r['ok']) {
            return ['ok' => false, 'rows' => [], 'error' => $r['error'], 'body' => $r['body']];
        }

        if (preg_match('~<faultstring[^>]*>(.*?)</faultstring>~is', $r['body'], $fm) === 1) {
            return ['ok' => false, 'rows' => [], 'error' => 'SOAP fault: ' . trim(html_entity_decode($fm[1])), 'body' => $r['body']];
        }

        $raw = '';

        foreach (['strDataList', 'GetTransactionsLogResult'] as $tag) {
            if ($raw === '' && preg_match('~<' . $tag . '[^>]*>(.*?)</' . $tag . '>~is', $r['body'], $m) === 1) {
                $v = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $raw = in_array(strtolower($v), ['true', 'false'], true) ? '' : $v;
            }
        }

        $records = preg_split('~\r\n|\r|\n~', $raw) ?: [];
        $records = count($records) <= 1 && str_contains($raw, ';') ? explode(';', $raw) : $records;
        $rows = [];

        foreach ($records as $rec) {
            $f = array_values(array_filter(array_map('trim', self::split(trim($rec))), static fn (string $s): bool => $s !== ''));
            $at = null;
            $used = [];

            for ($i = 0; $i < count($f) && $at === null; $i++) {
                $cand = $f[$i];
                $span = [$i];

                if (preg_match('~^(\d{4}-\d{2}-\d{2}|\d{2}[/-]\d{2}[/-]\d{4})$~', $cand) === 1 && isset($f[$i + 1]) && preg_match('~^\d{1,2}:\d{2}~', $f[$i + 1]) === 1) {
                    $cand .= ' ' . $f[$i + 1];
                    $span[] = $i + 1;
                }

                $at = self::date($cand, ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i']);
                $used = $at !== null ? $span : [];
            }

            if ($at === null) {
                continue;
            }

            $emp = null;
            $dir = '';
            $mc = '';

            foreach ($f as $i => $v) {
                if (in_array($i, $used, true)) {
                    continue;
                }

                if ($emp === null && preg_match('~\d~', $v) === 1) {
                    $emp = $v;
                } elseif (in_array(strtolower($v), ['in', 'i', 'checkin', 'check-in', 'duty on', 'out', 'o', 'checkout', 'check-out', 'duty off'], true)) {
                    $dir = $v;
                } elseif (in_array(strtolower($v), array_filter([strtolower((string) ($cfg['in_machine_id'] ?? '')), strtolower((string) ($cfg['out_machine_id'] ?? ''))]), true)) {
                    $mc = $v;
                }
            }

            if ($emp !== null) {
                $rows[] = ['emp' => $emp, 'at' => $at, 'machine' => $mc, 'dir' => self::direction($cfg, $mc, $dir)];
            }
        }

        return ['ok' => true, 'rows' => $rows, 'error' => null, 'body' => $raw !== '' ? $raw : $r['body']];
    }

    /** @return array{ok: bool, rows: list<array<string, string>>, error: ?string, body: string} */
    private function generic(array $cfg, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $a = json_decode((string) ($cfg['api_config'] ?? ''), true);
        $a = is_array($a) ? $a : [];
        $url = trim((string) ($a['url'] ?? $cfg['base_url'] ?? ''));

        if ($url === '') {
            return ['ok' => false, 'rows' => [], 'error' => 'No API URL set (Generic API mapping → url, or API Base URL).', 'body' => ''];
        }

        $fmt = trim((string) ($a['date_format_req'] ?? '')) ?: 'Y-m-d H:i:s';
        $repl = ['{from}' => $from->format($fmt), '{to}' => $to->format($fmt), '{from_date}' => $from->format('Y-m-d'), '{to_date}' => $to->format('Y-m-d'),
            '{serial}' => (string) ($cfg['serial_number'] ?? ''), '{user}' => (string) ($cfg['username'] ?? ''), '{pass}' => (string) $cfg['password'],
            '{key}' => (string) ($a['api_key'] ?? ''), '{empcode}' => (string) ($cfg['empcode'] ?? '') ?: 'ALL', '{token}' => ''];
        $headers = static function (string $raw) use (&$repl): array {
            $out = [];

            foreach (preg_split('~\r\n|\r|\n~', $raw) ?: [] as $line) {
                if (str_contains($line, ':')) {
                    $out[] = strtr(trim($line), $repl);
                }
            }

            return $out;
        };

        if (!empty($a['token_url'])) {
            $t = self::http(strtoupper((string) ($a['token_method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST', strtr((string) $a['token_url'], array_map('rawurlencode', $repl)),
                array_merge($headers((string) ($a['token_headers'] ?? '')), [($a['token_body_type'] ?? 'json') === 'form' ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json']),
                strtr((string) ($a['token_body'] ?? '{}'), $repl));
            $tok = self::dotGet(json_decode($t['body'], true), (string) ($a['token_path'] ?? 'token'));

            if (!$t['ok'] || !is_scalar($tok)) {
                return ['ok' => false, 'rows' => [], 'error' => 'Token login failed: ' . ($t['error'] ?? 'no token at "' . ($a['token_path'] ?? 'token') . '"'), 'body' => $t['body']];
            }

            $repl['{token}'] = (string) $tok;
        }

        $method = strtoupper((string) ($a['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
        $type = strtolower((string) ($a['body_type'] ?? 'none'));
        $h = $headers((string) ($a['headers'] ?? ''));
        $h[] = match ($type) { 'json' => 'Content-Type: application/json', 'form' => 'Content-Type: application/x-www-form-urlencoded', 'xml' => 'Content-Type: text/xml; charset=utf-8', default => 'X-None: 1' };
        $r = self::http($method, strtr($url, array_map('rawurlencode', $repl)), $h, $method === 'POST' ? strtr((string) ($a['body'] ?? ''), $repl) : '',
            !empty($a['basic_auth']) ? [(string) ($cfg['username'] ?? ''), (string) $cfg['password']] : null);

        if (!$r['ok']) {
            return ['ok' => false, 'rows' => [], 'error' => $r['error'], 'body' => $r['body']];
        }

        $records = [];
        $rtype = strtolower((string) ($a['response_type'] ?? 'json'));

        if ($rtype === 'delimited') {
            $lines = explode(stripcslashes((string) ($a['record_delim'] ?? '\n')) ?: "\n", trim($r['body']));
            $lines = !empty($a['skip_header']) ? array_slice($lines, 1) : $lines;

            foreach ($lines as $l) {
                if (trim($l) !== '') {
                    $records[] = array_map('trim', explode(stripcslashes((string) ($a['field_delim'] ?? '\t')) ?: "\t", trim($l, "\r\n")));
                }
            }
        } elseif ($rtype === 'xml') {
            $doc = new \DOMDocument();

            if (@$doc->loadXML(trim($r['body']))) {
                foreach ($doc->getElementsByTagName('*') as $el) {
                    if (strcasecmp($el->localName, (string) ($a['record_path'] ?? '')) === 0) {
                        $row = [];

                        foreach ($el->childNodes as $c) {
                            if ($c->nodeType === XML_ELEMENT_NODE) {
                                $row[$c->localName] = trim($c->textContent);
                            }
                        }

                        foreach ($el->attributes ?? [] as $at) {
                            $row[$at->nodeName] = $at->nodeValue;
                        }

                        $records[] = $row;
                    }
                }
            }
        } else {
            $data = json_decode(trim($r['body']), true);
            $data = ($a['record_path'] ?? '') !== '' ? self::dotGet($data, (string) $a['record_path']) : $data;
            $records = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];
        }

        $map = is_array($a['map'] ?? null) ? $a['map'] : [];
        $dirIn = array_map('strtolower', array_map('trim', explode(',', (string) ($a['dir_in'] ?? 'in,i,0'))));
        $dirOut = array_map('strtolower', array_map('trim', explode(',', (string) ($a['dir_out'] ?? 'out,o,1'))));
        $rows = [];

        foreach ($records as $rec) {
            $f = static fn (string $k): string => is_array($rec) ? trim((string) (is_scalar($v = self::dotGet($rec, $k)) ? $v : '')) : '';
            $emp = ($map['emp'] ?? '') !== '' ? $f((string) $map['emp']) : '';
            $dt = ($map['datetime'] ?? '') !== '' ? $f((string) $map['datetime']) : trim($f((string) ($map['date'] ?? '')) . ' ' . $f((string) ($map['time'] ?? '')));
            $pf = trim((string) ($a['punch_date_format'] ?? ''));
            $at = self::date($dt, $pf !== '' ? [$pf] : ['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'Y/m/d H:i:s', 'Y-m-d\TH:i:s']);

            if ($emp === '' || $at === null) {
                continue;
            }

            $mc = ($map['machine'] ?? '') !== '' ? $f((string) $map['machine']) : '';
            $raw = strtolower(($map['direction'] ?? '') !== '' ? $f((string) $map['direction']) : '');
            $dir = in_array($raw, $dirIn, true) ? 'in' : (in_array($raw, $dirOut, true) ? 'out' : '');
            $rows[] = ['emp' => $emp, 'at' => $at, 'machine' => $mc, 'dir' => self::direction($cfg, $mc, $dir)];
        }

        return ['ok' => true, 'rows' => $rows, 'error' => null, 'body' => $r['body']];
    }

    // --------------------------------------------------------------- helpers

    /** IN/OUT machine ids win; then the raw flag; blank = unknown (sequenced by PunchIngest). */
    private static function direction(array $cfg, string $machine, string $raw): string
    {
        $machine = trim($machine);
        $in = trim((string) ($cfg['in_machine_id'] ?? ''));
        $out = trim((string) ($cfg['out_machine_id'] ?? ''));

        if ($machine !== '' && $in !== '' && strcasecmp($machine, $in) === 0) {
            return 'in';
        }

        if ($machine !== '' && $out !== '' && strcasecmp($machine, $out) === 0) {
            return 'out';
        }

        $s = strtolower(trim($raw));

        return $s === '' ? 'unknown' : (str_contains($s, 'out') || $s === 'o' || $s === 'duty off' ? 'out' : 'in');
    }

    /** @param list<string> $formats */
    private static function date(string $v, array $formats): ?string
    {
        $v = trim($v);

        foreach ($formats as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $v);

            if ($d !== false) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /** @param list<string> $keys */
    private static function pick(array $row, array $keys): ?string
    {
        $lower = array_change_key_case($row, CASE_LOWER);

        foreach ($keys as $k) {
            if (isset($lower[strtolower($k)]) && trim((string) $lower[strtolower($k)]) !== '') {
                return (string) $lower[strtolower($k)];
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function split(string $rec): array
    {
        foreach (["\t", '|', ','] as $d) {
            if (str_contains($rec, $d)) {
                return explode($d, $rec);
            }
        }

        return preg_split('~\s{2,}~', $rec) ?: [$rec];
    }

    private static function dotGet(mixed $data, string $path): mixed
    {
        foreach ($path === '' ? [] : explode('.', $path) as $k) {
            if (!is_array($data) || !array_key_exists($k, $data)) {
                return null;
            }

            $data = $data[$k];
        }

        return $data;
    }

    /**
     * @param list<string> $headers
     * @param array{0: string, 1: string}|null $basic
     * @return array{ok: bool, status: int, body: string, error: ?string}
     */
    private static function http(string $method, string $url, array $headers, string $body = '', ?array $basic = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($basic !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basic[0] . ':' . $basic[1]);
        }

        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $resp = is_string($resp) ? $resp : '';

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $resp,
            'error' => $status >= 200 && $status < 300 ? null : ($err !== '' ? $err : 'HTTP ' . $status)];
    }
}
