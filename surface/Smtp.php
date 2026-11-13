<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * ONE EMAIL OVER SMTP — STARTTLS (587), implicit TLS (465) or plain, AUTH LOGIN,
 * a text + HTML body in UTF-8. Every server reply is checked; the first refusal
 * comes back as the reason, verbatim, so "535 Username and Password not
 * accepted" reaches the screen instead of a generic failure.
 *
 * ponytail: our own ~100 lines rather than symfony/mailer from vendor — vendor is
 * not installed by composer on every machine this runs on, loading its autoload
 * also loads Laravel's global helpers into this app, and this is exercised
 * end-to-end against a real SMTP server in tests. Swap for symfony/mailer if
 * OAuth2 / DKIM / attachments are ever needed.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    /**
     * @param array{host: string, port: int, encryption: string, username: string, password?: string, from_email: string, from_name: string, reply_to?: string} $server
     * @param list<string> $to
     * @param list<string> $cc
     * @return array{ok: bool, why: string}
     */
    public function send(array $server, array $to, array $cc, string $subject, string $text, string $html, int $timeout = 20, array $attachments = []): array
    {
        try {
            $secure = $server['encryption'] === 'ssl';
            $ssl = ['SNI_enabled' => true, 'peer_name' => $server['host']];
            // THE CA BUNDLE PHP ALREADY KNOWS (21 Oct): this socket reads openssl.cafile, and Laragon sets only
            // curl.cainfo — so every TLS handshake failed "certificate verify failed". Fall back to it.
            $ca = (string) ini_get('openssl.cafile') ?: (string) ini_get('curl.cainfo');
            if ($ca !== '' && is_file($ca)) {
                $ssl['cafile'] = $ca;
            }
            $context = stream_context_create(['ssl' => $ssl]);
            $socket = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . $server['host'] . ':' . (int) $server['port'],
                $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

            if ($socket === false) {
                return ['ok' => false, 'why' => "Could not reach {$server['host']}:{$server['port']} — {$errstr}"];
            }

            $this->socket = $socket;
            stream_set_timeout($socket, $timeout);
            $this->expect(220);
            $helo = gethostname() ?: 'smartprs.local';
            $ext = $this->command('EHLO ' . $helo, 250);

            if ($server['encryption'] === 'tls') {
                if (stripos($ext, 'STARTTLS') === false) {
                    throw new \RuntimeException('the server does not offer STARTTLS — choose SSL (465) or None');
                }

                $this->command('STARTTLS', 220);

                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new \RuntimeException('the TLS handshake failed (' . (error_get_last()['message'] ?? 'no detail')
                        . '). If the server is right, this PHP may lack its CA certificate bundle (php.ini openssl.cafile).');
                }

                $this->command('EHLO ' . $helo, 250);
            }

            if ((string) $server['username'] !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode((string) $server['username']), 334);
                $this->command(base64_encode((string) ($server['password'] ?? '')), 235);
            }

            $this->command('MAIL FROM:<' . $server['from_email'] . '>', 250);

            foreach (array_merge($to, $cc) as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }

            $this->command('DATA', 354);
            $this->write(self::message($server, $to, $cc, $subject, $text, $html, $attachments) . "\r\n.");
            // THE SERVER'S RECEIPT (owner, 13 Nov 2026: "it says sent but nothing arrives"): the 250 reply carries the
            // mail server's queue id — proof it was handed over, and what the mail provider needs to trace it.
            $receipt = trim($this->expect(250));
            try {
                $this->command('QUIT', 221);
            } catch (\Throwable $q) {
                error_log('SMTP QUIT not answered after a delivered message: ' . $q->getMessage()); // the message itself was accepted
            }

            return ['ok' => true, 'why' => 'Accepted by ' . $server['host'] . ' for ' . implode(', ', $to) . ($cc === [] ? '' : ' (cc ' . implode(', ', $cc) . ')')
                . ' — receipt: ' . substr((string) preg_replace('/\s+/', ' ', $receipt), 0, 160)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        } finally {
            is_resource($this->socket) && fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * The full RFC 5322 message, dot-stuffed, CRLF line ends.
     * ATTACHMENTS (owner, 21 Oct: BGV details to the agency) wrap the text/HTML in multipart/mixed.
     *
     * @param list<array{0: string, 1: string}> $attachments [file name, bytes]
     */
    public static function message(array $server, array $to, array $cc, string $subject, string $text, string $html, array $attachments = []): string
    {
        $enc = static fn (string $s): string => preg_match('/[^\x20-\x7e]/', $s) === 1 ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $boundary = 'sprs-' . bin2hex(random_bytes(8));
        $domain = substr((string) strrchr((string) $server['from_email'], '@'), 1) ?: 'smartprs.local';
        $head = ['Date: ' . date('r'), 'From: ' . $enc((string) $server['from_name']) . ' <' . $server['from_email'] . '>', 'To: ' . implode(', ', $to)];
        $cc === [] || $head[] = 'Cc: ' . implode(', ', $cc);
        (string) ($server['reply_to'] ?? '') === '' || $head[] = 'Reply-To: ' . $server['reply_to'];
        array_push($head, 'Subject: ' . $enc($subject), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"');
        $part = static fn (string $type, string $body): string => "--{$boundary}\r\nContent-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
        $msg = implode("\r\n", $head) . "\r\n\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--{$boundary}--";

        if ($attachments !== []) {
            $mixed = 'sprs-m-' . bin2hex(random_bytes(8));
            [$hd, $bd] = explode("\r\n\r\n", $msg, 2);
            $hd = str_replace('Content-Type: multipart/alternative; boundary="' . $boundary . '"', 'Content-Type: multipart/mixed; boundary="' . $mixed . '"', $hd);
            $msg = $hd . "\r\n\r\n--{$mixed}\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n" . $bd . "\r\n";
            foreach ($attachments as [$name, $bytes]) {
                $n = $enc(str_replace(['"', "\r", "\n"], '', (string) $name));
                $msg .= "--{$mixed}\r\nContent-Type: application/octet-stream; name=\"{$n}\"\r\nContent-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; filename=\"{$n}\"\r\n\r\n" . rtrim(chunk_split(base64_encode((string) $bytes), 76, "\r\n")) . "\r\n";
            }
            $msg .= "--{$mixed}--";
        }

        return (string) preg_replace('/^\./m', '..', $msg); // dot-stuffing (base64 never starts a line with '.', headers might)
    }

    private function write(string $line): void
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException('the connection was closed while sending');
        }
    }

    /** @param int|list<int> $ok */
    private function command(string $line, int|array $ok): string
    {
        $this->write($line);

        return $this->expect($ok);
    }

    /** @param int|list<int> $ok */
    private function expect(int|array $ok): string
    {
        $reply = '';

        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;

            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($reply, 0, 3);

        if (!in_array($code, (array) $ok, true)) {
            throw new \RuntimeException($reply === '' ? 'the server did not answer (timed out)' : 'the mail server refused: ' . trim($reply));
        }

        return $reply;
    }
}
