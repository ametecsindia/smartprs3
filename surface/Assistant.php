<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE ASSISTANT ANSWERS FROM THE ROWS, INSIDE THE ASKER'S PERMISSIONS, AND SENDS
 * NOTHING ANYWHERE.
 *
 * SmartPRS 2's "AI Assistant" is a chat box. This one is a question matched to a
 * declared intent (config/assistant.json) and answered by the same code paths the
 * screens use — the same Scope, the same `$may`, the same settlement — so the
 * assistant can never say something the asker could not have read on a screen.
 *
 * NO MODEL, NO API. CLAUDE.md: "No customer data in a prompt — employee records,
 * salaries, borrower details." An assistant that forwarded "what is Ravi's
 * salary?" to a hosted model would forward Ravi. So the wording is matched
 * locally and the answer is computed locally; the only "intelligence" is the
 * phrase list, which a tenant edits like any other configuration.
 *
 * ponytail: whole-phrase matching, first intent in declaration order wins. The
 * upgrade path is a local classifier fed the same intents; the handlers do not
 * change.
 */
final class Assistant
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, array<string, mixed>> intent key => declaration */
    public function intents(): array
    {
        if ($this->config === null) {
            $decoded = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

            if (!is_array($decoded) || !is_array($decoded['intents'] ?? null)) {
                throw new \RuntimeException("config/assistant.json is missing or unreadable at {$this->file}.");
            }

            $this->config = $decoded;
        }

        return (array) $this->config['intents'];
    }

    /** Which intent a question is, or null when none of the declared phrases occur. */
    public function classify(string $question): ?string
    {
        $q = ' ' . preg_replace('/[^a-z0-9\-]+/', ' ', strtolower($question)) . ' ';

        // Longest phrase anywhere wins over declaration order, so "my leave
        // balance" is my_leave_balance and not open_screen because of "where".
        $best = null;
        $bestLen = 0;

        foreach ($this->intents() as $key => $intent) {
            foreach ((array) ($intent['phrases'] ?? []) as $phrase) {
                $p = strtolower(trim((string) $phrase));

                if ($p !== '' && str_contains($q, ' ' . $p) && strlen($p) > $bestLen) {
                    $best = (string) $key;
                    $bestLen = strlen($p);
                }
            }
        }

        return $best;
    }

    /**
     * Answer a question with the handlers the front controller supplies.
     *
     * @param array<string, callable(string): array{answer: string, links?: list<array{label: string, href: string}>}> $handlers
     * @return array{intent: ?string, label: string, answer: string, links: list<array{label: string, href: string}>}
     */
    public function answer(string $question, array $handlers): array
    {
        $question = trim($question);
        $intent = $question === '' ? null : $this->classify($question);

        if ($intent === null || !isset($handlers[$intent])) {
            return ['intent' => null, 'label' => 'Not understood',
                'answer' => $question === '' ? 'Ask something — the examples below are questions this assistant answers.'
                    : "I did not recognise that. I answer the questions listed below, from this company's own records; "
                    . 'phrases are declared in config/assistant.json, so a wording that should have worked can be added there.',
                'links' => []];
        }

        $out = $handlers[$intent]($question);

        return ['intent' => $intent, 'label' => (string) ($this->intents()[$intent]['label'] ?? $intent),
            'answer' => (string) ($out['answer'] ?? ''), 'links' => array_values((array) ($out['links'] ?? []))];
    }

    /** The word after "who is", "find", … — or the last token when there is no verb. */
    public static function subjectOf(string $question): string
    {
        $q = trim(preg_replace('/[?.!,]+/', ' ', $question) ?? $question);

        if (preg_match('/(?:who is|find|look ?up|search(?: for)?|details of|about)\s+(.+)$/i', $q, $m) === 1) {
            return trim($m[1]);
        }

        $parts = preg_split('/\s+/', $q) ?: [];

        return (string) end($parts);
    }
}
