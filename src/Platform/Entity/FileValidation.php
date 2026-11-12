<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * FILE-BEARING FIELDS VALIDATE CONTENT, NOT FILENAME — Part X §X.3.2 #19-22,
 * R-X-028.
 *
 * "MIME is sniffed from the bytes; the extension must ADDITIONALLY be allowed;
 * the declared size bounds are enforced; and the object is quarantined until the
 * scan verdict is `clean`."
 *
 * Both halves of that, in that order, because either alone is a hole:
 *
 *   Extension only — what SmartPRS 2 does, with no MIME sniff, no content check
 *   and no image decode anywhere — accepts `payload.php` renamed to
 *   `payslip.pdf`. It also accepts any base64 blob between 500 B and 4 MB as a
 *   selfie.
 *
 *   MIME only accepts a genuine PDF uploaded where a photo was asked for, which
 *   is not a security problem but is a broken record: the thumbnail never
 *   renders and nobody knows why.
 *
 * QUARANTINE IS THE DEFAULT, and it is a state rather than a flag somebody sets.
 * A file whose scan has not returned is not visible, not downloadable and not
 * exported; a file whose scan returned `infected` is retained for the incident
 * and never served. The alternative — visible until proven otherwise — is a
 * window in which the malware is on the platform's own domain.
 *
 * The scanner itself is not here. It is a verdict this class is TOLD, because a
 * platform kernel does not shell out to ClamAV; what belongs here is that a
 * missing verdict is not a pass.
 */
final class FileValidation
{
    /** X.3.2 #19-22. What each file-bearing type will accept, before the field narrows it. */
    private const TYPE_MIME = [
        'photo' => ['image/jpeg', 'image/png', 'image/webp'],
        'signature' => ['image/png', 'image/svg+xml'],
        'file' => ['application/pdf', 'image/jpeg', 'image/png', 'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'document' => ['application/pdf', 'image/jpeg', 'image/png'],
    ];

    /** The extension that must ALSO be allowed, per sniffed MIME. */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        'application/pdf' => ['pdf'],
        'application/zip' => ['zip'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    ];

    public const VERDICT_PENDING = 'pending';
    public const VERDICT_CLEAN = 'clean';
    public const VERDICT_INFECTED = 'infected';
    public const VERDICT_ERROR = 'error';

    /** Beyond this, a field must declare its own cap; nothing is unbounded. */
    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Check one upload against a field's declaration.
     *
     * @param  string  $bytes     the CONTENT, because the content is what is checked
     * @param  string  $filename  used for the extension only, never for the type
     * @return array{ok: bool, mime: ?string, error: string}
     */
    public static function check(FieldDefinition $field, string $bytes, string $filename): array
    {
        if (!array_key_exists($field->authoringType->value, self::TYPE_MIME)) {
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' is a {$field->authoringType->value} and carries no file."
            );
        }

        if ($bytes === '') {
            return ['ok' => false, 'mime' => null, 'error' => 'The upload is empty.'];
        }

        $max = (int) ($field->constraints['max_bytes'] ?? self::DEFAULT_MAX_BYTES);
        $min = (int) ($field->constraints['min_bytes'] ?? 1);
        $size = strlen($bytes);

        if ($size > $max) {
            return [
                'ok' => false,
                'mime' => null,
                'error' => "The upload is {$size} bytes and '{$field->fieldKey}' allows {$max}.",
            ];
        }

        if ($size < $min) {
            return [
                'ok' => false,
                'mime' => null,
                'error' => "The upload is {$size} bytes and '{$field->fieldKey}' requires at least {$min}. "
                    . 'A file too small to be what it claims is usually a failed upload, not a small file.',
            ];
        }

        $mime = self::sniff($bytes);

        if ($mime === null) {
            return [
                'ok' => false,
                'mime' => null,
                'error' => 'The content could not be identified. An unidentifiable upload is refused rather '
                    . 'than trusted to its extension (R-X-028).',
            ];
        }

        $allowed = (array) ($field->constraints['allowed_mime'] ?? self::TYPE_MIME[$field->authoringType->value]);

        if (!in_array($mime, $allowed, true)) {
            return [
                'ok' => false,
                'mime' => $mime,
                'error' => "The content is {$mime}, and '{$field->fieldKey}' accepts "
                    . implode(', ', $allowed) . '. The name of the file did not come into it.',
            ];
        }

        // The extension must ALSO be allowed. A genuine PDF named `.exe` is a
        // genuine PDF that something downstream will hand to the shell.
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $expected = self::MIME_EXTENSIONS[$mime] ?? [];

        if ($expected !== [] && !in_array($extension, $expected, true)) {
            return [
                'ok' => false,
                'mime' => $mime,
                'error' => "The content is {$mime} but the file is named '.{$extension}'. Both must agree: "
                    . 'content decides what it IS, and the extension decides what everything downstream will '
                    . 'treat it as.',
            ];
        }

        return ['ok' => true, 'mime' => $mime, 'error' => ''];
    }

    /**
     * Whether a stored file may be shown, downloaded or exported.
     *
     * Every verdict except `clean` is NO, including the one that has not come
     * back yet and the one where the scanner itself failed. A scanner outage
     * that made files visible would be the outage worth attacking.
     */
    public static function isServable(string $verdict): bool
    {
        return $verdict === self::VERDICT_CLEAN;
    }

    /** Why a file is not being served, in words for the person who uploaded it. */
    public static function quarantineReason(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_CLEAN => '',
            self::VERDICT_PENDING => 'The upload is held until its scan completes. It is not lost, and nobody '
                . 'else can see it yet.',
            self::VERDICT_INFECTED => 'The upload was refused by the malware scan. It is retained for the '
                . 'incident record and will not be served to anybody.',
            self::VERDICT_ERROR => 'The scan could not complete, so the upload stays held. A scanner failure '
                . 'is not a pass — an outage that released files would be the outage worth causing.',
            default => throw new \InvalidArgumentException(
                "'{$verdict}' is not a scan verdict. An unknown verdict is not a synonym for clean."
            ),
        };
    }

    /**
     * The MIME the BYTES say they are.
     *
     * `finfo` reads magic numbers. Where the extension disagrees, the extension
     * is what is wrong — that is the whole ordering of R-X-028.
     */
    public static function sniff(string $bytes): ?string
    {
        if (!class_exists(\finfo::class)) {
            // Not a fallback to the extension. A platform without fileinfo
            // cannot honour R-X-028, and saying so at the boundary is better
            // than a deployment that silently checks nothing.
            throw new \RuntimeException(
                'ext-fileinfo is not loaded, so content cannot be sniffed. R-X-028 requires it: a build '
                . 'without it would validate uploads by filename, which is what SmartPRS 2 does.'
            );
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return $mime === false || $mime === 'application/x-empty' ? null : $mime;
    }
}
