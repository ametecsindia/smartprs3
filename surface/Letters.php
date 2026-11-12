<?php

declare(strict_types=1);

namespace SmartPRS\Surface;


/**
 * LETTERS FROM A DECLARATION, WITH THE BLANKS REFUSED.
 *
 * An HR system's letters are the documents an employee keeps for twenty years
 * and shows to a bank, a landlord and the next employer. SmartPRS 2 builds them
 * in a controller per letter type; more often, in practice, somebody keeps a
 * Word file on a desktop, and the version that goes out depends on whose desktop.
 * Here a letter is a template in `config/letters.json` and one engine renders it.
 *
 * A PLACEHOLDER THAT RESOLVES TO NOTHING IS A REFUSAL. This is the rule the whole
 * class exists for. An appointment letter that goes out reading "your annual cost
 * to company will be  " is worse than one that failed to render, because it is
 * signed, filed and acted on — and the blank is invisible to whoever clicked
 * "issue" among six paragraphs of prose they have read a hundred times. So the
 * render either produces a complete letter or produces a list of what is missing,
 * and there is no third outcome.
 *
 * AN ISSUED LETTER IS KEPT AS THE TEXT THAT WENT OUT, not as a reference to a
 * template. Templates change; the letter somebody was given did not. Storing the
 * key and re-rendering later would mean an experience certificate whose wording
 * quietly changes years after it was signed, which is the same defect as an
 * overwritten record and harder to notice.
 *
 * ponytail: renders text, not PDF. `pdf_path` on the table is where the print
 * step will put its file; the text is what has to be right first, and a PDF of a
 * wrong letter is not an improvement.
 */
final class Letters
{
    private const COLLECTION = 'letters';

    /** @var list<array<string, mixed>>|null */
    private ?array $letters = null;

    private const PREFIX = 'letter:';

    /** A letter TYPE the tenant created on screen (owner, 23 Oct 2026), versioned like every customisation. */
    private const NEW_PREFIX = 'letter-new:';

    /** What a customer may change about a template. `key` and `subject` are not here. */
    public const TEMPLATE_KEYS = ['label', 'body', 'requires', 'lop_days', 'email_subject', 'needs_ack'];

    /** Does the employee have to click "I acknowledge" on this letter (owner, 23 Oct 2026)? */
    public static function needsAck(array $template): bool
    {
        return (string) ($template['needs_ack'] ?? '') === '1';
    }

    /**
     * LOSS OF PAY A LETTER CARRIES (owner, 23 Oct 2026): a half-day deduction letter, an LOP letter for N
     * days — the company's own terms, set on the template. Days in halves ("0.5", "1", "2.5"); blank = none.
     */
    public static function lopHalves(string $days): ?int
    {
        $days = trim($days);

        if ($days === '' || $days === '0') {
            return 0;
        }

        if (preg_match('/^(\d{1,2})(?:\.(0|5))?$/', $days, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 2 + (($m[2] ?? '') === '5' ? 1 : 0);
    }

    /** The filters a placeholder may use. Anything else would print as raw text. */
    public const FILTERS = ['', 'money', 'money_words', 'date', 'upper'];

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->letters !== null) {
            return $this->letters;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['letters'] ?? null)) {
            throw new \RuntimeException(
                "The letter registry at {$this->file} is missing or unreadable. Refused rather than "
                . 'treated as "no templates", which would render a screen offering nothing and no '
                . 'indication that anything was wrong.'
            );
        }

        // The customer's wording laid over the shipped template, at the one
        // point every read passes through — so the preview, the permission
        // derivation and the issued text are all the same letter.
        return $this->letters = array_map($this->overlay(...), array_merge($decoded['letters'], $this->created()));
    }

    /** @return list<array<string, mixed>> letter types this tenant created, as created */
    private function created(): array
    {
        $out = [];

        foreach ($this->customisation?->live() ?? [] as $k => $payload) {
            if (str_starts_with((string) $k, self::NEW_PREFIX) && is_array($payload) && ($payload['retired'] ?? false) !== true) {
                $out[] = ['key' => substr((string) $k, strlen(self::NEW_PREFIX)), 'label' => (string) ($payload['label'] ?? ''),
                    'subject' => 'employee', 'requires' => array_values((array) ($payload['requires'] ?? [])),
                    'body' => (string) ($payload['body'] ?? ''), 'lop_days' => (string) ($payload['lop_days'] ?? ''), 'email_subject' => (string) ($payload['email_subject'] ?? ''), 'needs_ack' => (string) ($payload['needs_ack'] ?? ''), 'created' => true];
            }
        }

        return $out;
    }

    /**
     * A NEW LETTER TYPE, made on screen (owner, 23 Oct 2026). Checked exactly as a change of wording is —
     * every placeholder must be something an employee holds — and kept as version 1 of its own record.
     *
     * @param list<string> $requires
     * @param list<string> $available the fields an employee actually has
     * @return array{ok: bool, why: string, key?: string}
     */
    public function create(string $label, string $body, array $requires, array $available, string $byId, string $byLabel, string $note = '', string $lopDays = '', string $emailSubject = '', bool $needsAck = false): array
    {
        $label = trim($label);
        $body = trim(str_replace("\r\n", "\n", $body));
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');

        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }
        if ($label === '' || $key === '') {
            return ['ok' => false, 'why' => 'Give the letter a name.'];
        }
        if ($this->find($key) !== null || array_filter($this->all(), static fn (array $t): bool => strcasecmp(trim((string) $t['label']), $label) === 0) !== []) {
            return ['ok' => false, 'why' => "A letter called \"{$label}\" already exists — pick another name, or use Change wording on that one."];
        }

        $template = ['label' => $label, 'body' => $body, 'requires' => array_values(array_filter(array_map('trim', $requires)))]
            + (trim($lopDays) === '' || trim($lopDays) === '0' ? [] : ['lop_days' => trim($lopDays)])
            + (trim($emailSubject) === '' ? [] : ['email_subject' => trim($emailSubject)])
            + ($needsAck ? ['needs_ack' => '1'] : []);
        $problems = self::templateProblems($template, $available);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        $saved = $this->customisation->saveRaw(self::NEW_PREFIX . $key, $template, $byId, $byLabel, $note);
        $this->letters = null;

        return $saved['ok'] ? ['ok' => true, 'key' => $key, 'why' => "Letter template \"{$label}\" created. It is in every letter dropdown now."] : $saved;
    }

    private function overlay(array $template): array
    {
        $override = $this->customisation?->live()[self::PREFIX . $template['key']] ?? [];

        return $override === []
            ? $template
            : array_merge($template,
                array_intersect_key($override, array_flip(self::TEMPLATE_KEYS)))
                + ['customised' => true];
    }

    /** The template AS SHIPPED — what a change is compared against. */
    public function shipped(string $key): ?array
    {
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        foreach ((array) ($decoded['letters'] ?? []) as $template) {
            if ((string) $template['key'] === $key) {
                return $template;
            }
        }

        // A tenant-created type: "as shipped" is the version it was created with.
        foreach ($this->created() as $template) {
            if ((string) $template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function history(string $key): array
    {
        return $this->customisation?->history(self::PREFIX . $key) ?? [];
    }

    /**
     * Record a customer's own version of a template.
     *
     * @param array<string, mixed> $override
     * @param list<string> $available the fields an employee actually has
     * @return array{ok: bool, why: string, version?: int}
     */
    public function customise(string $key, array $override, ?array $shipped, array $available,
        string $byId, string $byLabel, string $note = ''): array
    {
        if ($shipped === null) {
            return ['ok' => false, 'why' => "There is no letter template '{$key}' to customise."];
        }

        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $problems = self::templateProblems($override, $available, $shipped);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        return $this->customisation->saveRaw(self::PREFIX . $key, $override, $byId, $byLabel, $note);
    }

    /**
     * What is wrong with a customer's wording.
     *
     * THE CHECK THAT MATTERS: a placeholder naming a field no employee has can
     * never resolve, so the template refuses for every single person, for ever.
     * Caught here, where it is one word in a box, rather than six weeks later
     * when somebody cannot issue a relieving letter to a person who is leaving
     * today and the message says a field is missing that HR has never heard of.
     *
     * @param list<string> $available
     * @return list<string>
     */
    public static function templateProblems(array $override, array $available,
        array $shipped = []): array
    {
        $problems = [];

        // AN EMPTY FIELD LIST IS A REFUSAL, NOT A SKIPPED CHECK. If nobody can
        // say what an employee holds, the placeholder check below cannot run —
        // and running it against nothing would pass every template silently,
        // which is the failure this codebase keeps finding in its own work.
        if ($available === []) {
            return ['The fields an employee holds could not be read, so it is not possible to '
                . 'check that the placeholders in this template resolve to anything. Refused rather '
                . 'than saved unchecked: a check that runs against nothing passes everything.'];
        }

        $known = array_merge($available, ['today', 'company', 'effective_date', 'salary_effective_date', 'salary_structure', 'training_terms', 'signatory', 'lop_days', 'lop_days_words', 'lop_date', 'lop_pay_month', 'lop_pay_cut']);

        // CHECKED ON THE MERGED TEMPLATE, not the override alone. A customer who
        // rewrites only the body inherits the shipped `requires` list — so a new
        // wording that drops the joining date leaves the template insisting on a
        // field it no longer prints, and the letter refuses for everybody with a
        // message naming a field that is nowhere on the page. The override says
        // nothing about `requires`, so a check reading the override would pass
        // it. Same lesson as everywhere else here: check the thing that will
        // exist, not the thing that was typed.
        $merged = array_merge($shipped, $override);

        foreach (array_keys($override) as $key) {
            if (!in_array((string) $key, self::TEMPLATE_KEYS, true)) {
                $problems[] = sprintf('"%s" cannot be customised on a letter template. The key and '
                    . 'the subject it is written about are what the rest of the application finds '
                    . 'it by.', (string) $key);
            }
        }

        if (array_key_exists('lop_days', $override) && self::lopHalves((string) $override['lop_days']) === null) {
            $problems[] = 'Loss of pay is in days and half days — e.g. 0.5, 1, 2 or 2.5.';
        }

        if (array_key_exists('body', $override) && trim((string) $override['body']) === '') {
            $problems[] = 'An empty letter is not a letter. Leave some wording.';
        }

        $body = (string) ($merged['body'] ?? '');

        foreach (self::placeholders($body) as $placeholder) {
            if (!in_array($placeholder['filter'], self::FILTERS, true)) {
                $problems[] = sprintf(
                    '%s uses "%s", which is not a way of formatting a value — it would print in the '
                    . 'letter exactly as written. The ones that exist are: %s.',
                    $placeholder['token'], $placeholder['filter'],
                    implode(', ', array_filter(self::FILTERS)),
                );

                continue;
            }

            if (!in_array($placeholder['field'], $known, true)) {
                $problems[] = sprintf(
                    '%s names "%s", which is not something an employee holds — so this template '
                    . 'would refuse to render for every person, for ever. Caught here, where it is '
                    . 'one word in a box, rather than on the day somebody needs the letter.',
                    $placeholder['token'], $placeholder['field'],
                );
            }
        }

        $used = array_column(self::placeholders($body), 'field');

        foreach ((array) ($merged['requires'] ?? []) as $required) {
            if ($body !== '' && !in_array((string) $required, $used, true)) {
                $problems[] = sprintf(
                    'The template insists on "%s" but the wording never prints it, so the letter '
                    . 'would refuse for everybody with a message naming a field that is nowhere on '
                    . 'the page. Either put it back in the wording or take it off the required '
                    . 'list.', (string) $required);
            }
        }

        return $problems;
    }

    public function find(string $key): ?array
    {
        foreach ($this->all() as $letter) {
            if ((string) $letter['key'] === $key) {
                return $letter;
            }
        }

        return null;
    }

    /**
     * The permissions a template needs — DERIVED FROM WHAT IT PRINTS, not from a
     * line somebody remembered to add.
     *
     * An offer letter and a warning letter are both "a letter", and treating them
     * as one permission is how somebody who may issue warnings ends up able to
     * print everybody's CTC. So a template whose body formats money additionally
     * requires the salary permission, and it requires it because the body says
     * `|money`, not because a `permission` key was filled in — a declaration that
     * has to be remembered is one that gets forgotten on the eighth template.
     *
     * @return list<string>
     */
    public static function permissionsFor(array $template): array
    {
        $needed = ['employee.update'];

        foreach (self::placeholders((string) $template['body']) as $placeholder) {
            if (str_starts_with($placeholder['filter'], 'money')) {
                $needed[] = 'employee.salary.view';

                break;
            }
        }

        return $needed;
    }

    /**
     * The statutory schemes a letter can promise, and the word a component would
     * declare to compute one. Language, not business data: `pf` is the engine's
     * own flag vocabulary (see SalaryComponent's flags docblock), and the
     * spellings are how an Indian appointment letter says the scheme's name.
     */
    private const SCHEMES = [
        ['name' => 'provident fund', 'says' => '/provident fund|\bPF\b/i', 'flag' => 'pf'],
        ['name' => 'employees\' state insurance', 'says' => '/state insurance|\bESI\b/i',
            'flag' => 'esi'],
        ['name' => 'professional tax', 'says' => '/professional tax|\bPT\b/i', 'flag' => 'pt'],
        ['name' => 'gratuity', 'says' => '/gratuity/i', 'flag' => 'gratuity'],
        ['name' => 'tax deducted at source', 'says' => '/\bTDS\b|income tax|tax deducted at source/i',
            'flag' => 'tds'],
    ];

    /**
     * What this letter PROMISES that payroll does not DO.
     *
     * The sentence in the appointment letter — "statutory deductions, including
     * provident fund and professional tax where applicable, will be made in
     * accordance with the law" — is an ordinary, correct clause. It is the
     * employer's own statement of intent and there is nothing wrong with printing
     * it. What is wrong is printing it over a payroll that computes none of it and
     * telling nobody: the letter is signed, filed, and believed, and the person who
     * finds out is the employee, years later, looking for a balance that was never
     * built.
     *
     * DERIVED FROM THE BODY AND THE STRUCTURE, the same way permissionsFor() derives
     * the salary permission from the body saying `|money`. No list of letter keys:
     * a customer who writes their own appointment wording, or adds a scheme to it,
     * gets checked on what their wording actually says.
     *
     * ponytail: the signal is A TRUTHY FLAG NAMING THE SCHEME ON A COMPONENT OF
     * KIND `deduction`. That is the only place the structure says what a deduction
     * IS without reading its code, and codes are the customer's to rename — 'D-PT'
     * is a business literal and this codebase's first law is that none live here.
     * What the signal CANNOT see: whether a declared deduction is the right amount
     * — the flat 200.00 professional tax this build ships, with no state and no
     * slab behind it, would satisfy this check the moment somebody flags it. It
     * also refuses to read a wage-base flag as coverage: `pf_base` on an earning
     * says a base is being assembled, not that anything is deducted or remitted, so
     * the shipped structure is reported for provident fund despite carrying it.
     * And the plain truth underneath all of it: this build computes no statutory
     * scheme from real law at all — Surface\Statutory runs against a synthetic pack
     * whose own jurisdiction does not exist. What would make this exact is the
     * deduction carrying the statutory head it answers to, resolved by a signed
     * pack from a named compliance owner; then the check becomes "is this head
     * computed" instead of "did somebody declare a flag".
     *
     * @param array<string, mixed> $template the merged template, as `all()` returns it
     * @param array<string, mixed> $version the structure version in force
     * @return list<array{scheme: string, why: string}> empty when the letter promises
     *         nothing the structure does not do
     */
    public static function promisesNotKept(array $template, array $version): array
    {
        $declared = [];

        foreach ((array) ($version['components'] ?? []) as $component) {
            if ((string) ($component['kind'] ?? '') !== 'deduction') {
                continue;
            }

            foreach ((array) ($component['flags'] ?? []) as $flag => $on) {
                if ($on) {
                    $declared[] = (string) $flag;
                }
            }
        }

        $body = (string) ($template['body'] ?? '');
        $unkept = [];

        foreach (self::SCHEMES as $scheme) {
            if (preg_match($scheme['says'], $body) !== 1) {
                continue;
            }

            foreach ($declared as $flag) {
                if (preg_match('/(^|_)' . $scheme['flag'] . '(_|$)/', $flag) === 1) {
                    continue 2;
                }
            }

            $unkept[] = ['scheme' => $scheme['name'], 'why' => sprintf(
                'This letter tells the employee that %s will be deducted in accordance with the law. '
                . 'The salary structure in force declares no deduction that computes it, so no line '
                . 'for it reaches the payslip and nothing is remitted. The wording is a normal clause '
                . 'and is not the thing to change; issuing it over a payroll that does none of it '
                . 'means the company has put in writing, over a signature, something this system will '
                . 'not do — and the first person to find out is the employee, at the point they go '
                . 'looking for the balance. Either declare the deduction on the structure or take the '
                . 'promise out of the wording.',
                $scheme['name'],
            )];
        }

        return $unkept;
    }

    /**
     * Every placeholder a template uses, in the order it uses them.
     *
     * @return list<array{token: string, field: string, filter: string}>
     */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*(?:\|\s*([a-z_]+)\s*)?\}\}/i', $body, $matches,
            PREG_SET_ORDER);

        $found = [];

        foreach ($matches as $match) {
            $found[] = ['token' => $match[0], 'field' => $match[1], 'filter' => $match[2] ?? ''];
        }

        return $found;
    }

    /**
     * Render a letter for one employee.
     *
     * @return array{ok: bool, body: string, missing: list<string>, why: string}
     */
    public function render(array $template, array $subject, array $context = []): array
    {
        // `effective_date` is what HR picks on the draft screen (21 Sep, owner:
        // "option to update effective date manually"); today when none is given.
        $values = $subject + $context + ['today' => date('Y-m-d'), 'effective_date' => date('Y-m-d')];
        $missing = [];
        $body = (string) $template['body'];

        foreach (self::placeholders($body) as $placeholder) {
            $raw = $values[$placeholder['field']] ?? null;
            $text = $raw === null ? '' : trim((string) $raw);

            if ($text === '' || $text === '0000-00-00') {
                // NAMED, and named as the reader would name it, because the
                // person about to fix it is in HR and not in the schema.
                $missing[] = self::humanise($placeholder['field']);

                continue;
            }

            $body = str_replace($placeholder['token'], self::format($text, $placeholder['filter']),
                $body);
        }

        $missing = array_values(array_unique($missing));

        // A required field missing is the same refusal as any other missing one.
        // `requires` exists so a template can insist on a field it mentions only
        // in a sentence a reader would not miss but a regex would.
        foreach ((array) ($template['requires'] ?? []) as $field) {
            if (trim((string) ($values[$field] ?? '')) === ''
                && !in_array(self::humanise((string) $field), $missing, true)) {
                $missing[] = self::humanise((string) $field);
            }
        }

        if ($missing !== []) {
            return ['ok' => false, 'body' => '', 'missing' => $missing, 'why' => sprintf(
                'Not rendered. %s %s no value on this employee, and a letter with a blank where a '
                . 'name, a date or a salary belongs is worse than one that failed to render — it '
                . 'gets signed, filed and acted on, and nobody reads six paragraphs they have read '
                . 'a hundred times closely enough to see the gap.',
                implode(', ', $missing),
                count($missing) === 1 ? 'has' : 'have',
            )];
        }

        // SIGNED FOR THE COMPANY BY (owner, 23 Oct): the Company / Branch / HR Admin picked on the draft prints
        // above "Authorised Signatory" — or wherever the wording puts {{signatory}}.
        $signatory = trim((string) ($context['signatory'] ?? ''));
        $plain = (bool) ($template['plain'] ?? false);   // an email subject: no paragraphs added

        // THE PAY CUT IN WORDS (owner, 23 Oct): a letter that carries loss of pay says exactly what is cut and
        // from which salary — at {{lop_pay_cut}} if the wording has it, else as its own paragraph before the sign-off.
        $cut = trim((string) ($context['lop_pay_cut'] ?? ''));
        if (!$plain && $cut !== '' && !str_contains((string) $template['body'], '{{lop_')) {
            $para = 'Accordingly, ' . $cut . '.';
            $body = preg_match('/^(Warm regards|Regards|Kind regards|Best regards|Yours (sincerely|faithfully|truly)|Sincerely|Thanking you|For [^\n]+$)/mi', $body, $m, PREG_OFFSET_CAPTURE) === 1
                ? substr($body, 0, (int) $m[0][1]) . $para . "\n\n" . substr($body, (int) $m[0][1])
                : rtrim($body) . "\n\n" . $para;
        }

        if (!$plain && $signatory !== '' && !str_contains((string) $template['body'], '{{signatory')) {
            $at = strrpos($body, 'Authorised Signatory');
            $body = $at === false ? rtrim($body) . "\n\n" . $signatory . "\nAuthorised Signatory"
                : substr($body, 0, $at) . $signatory . "\n" . substr($body, $at);
        }

        return ['ok' => true, 'body' => $body, 'missing' => [],
            'why' => 'Every placeholder resolved.'];
    }

    /**
     * Issue a rendered letter: keep the TEXT, not the template it came from.
     *
     * @return array{ok: bool, why: string, letter?: array<string, mixed>}
     */
    public function issue(array $template, array $subject, string $byLabel,
        array $context = []): array
    {
        $rendered = $this->render($template, $subject, $context);

        if (!$rendered['ok']) {
            return ['ok' => false, 'why' => $rendered['why']];
        }

        try {
            $letter = $this->store->put(self::COLLECTION, [
                'employee_id' => $subject['id'] ?? null,
                'letter_type' => (string) $template['key'],
                'issued_on' => date('Y-m-d'),
                'status' => 'issued',
                // The text that went out, kept as it went out. Re-rendering from
                // the template later would let the wording of a signed
                // certificate change quietly years afterwards.
                'body' => $rendered['body'],
                'issued_by' => $byLabel,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }

        return ['ok' => true, 'letter' => $letter, 'why' => sprintf(
            '%s issued and kept as the text that went out. Re-issuing writes another letter rather '
            . 'than changing this one — the copy in somebody\'s file does not change when the '
            . 'template does.',
            (string) $template['label'],
        )];
    }

    /** @return list<array<string, mixed>> letters issued for one employee, newest first */
    public function issued(string $employeeId): array
    {
        try {
            $rows = array_values(array_filter(
                $this->store->all(self::COLLECTION),
                static fn (array $r): bool => (string) ($r['employee_id'] ?? '') === $employeeId,
            ));
        } catch (\Throwable) {
            return [];
        }

        // NEWEST FIRST, and the ordering key is checked rather than assumed.
        // `(int) $id` on the file store's non-numeric ids casts every row to 0,
        // so the sort silently does nothing and the list comes back in whatever
        // order it arrived — the failure being that it LOOKS sorted. Where the
        // ids are numeric they order; where they are not, the store's own
        // insertion order is reversed, which is what "newest" means there.
        $numeric = $rows !== [] && array_reduce($rows, static fn (bool $c, array $r): bool
            => $c && ctype_digit((string) ($r['id'] ?? '')), true);

        if (!$numeric) {
            return array_reverse($rows);
        }

        usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

        return $rows;
    }

    /** How a value is written into a letter. */
    private static function format(string $value, string $filter): string
    {
        // MONEY GOES THROUGH `Amount`/`Money`, which already formats on the Indian scale
        // (₹42,00,000.00, not ₹4,200,000.00) and already writes the words on it
        // (Forty Two Lakh, not Four Point Two Million). I had written both again
        // here before finding them; the second implementation is the one that
        // drifts, and on the line a salary dispute is settled by.
        return match ($filter) {
            'money' => Amount::of($value)->format(),
            'money_words' => Amount::of($value)->toWords(),
            'date' => self::date($value),
            'upper' => mb_strtoupper($value),
            default => $value,
        };
    }

    /** A date as an Indian reader expects it, and the raw value when it is not one. */
    private static function date(string $value): string
    {
        $time = strtotime($value);

        // The raw value rather than a guess. A date that will not parse printed
        // as "01 January 1970" is a wrong letter that looks like a right one.
        return $time === false ? $value : date('d-m-Y', $time);
    }

    private static function humanise(string $field): string
    {
        return ucfirst(trim(str_replace('_', ' ', preg_replace('/_id$/', '', $field) ?? $field)));
    }
}
