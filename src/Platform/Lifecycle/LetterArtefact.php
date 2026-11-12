<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Lifecycle;

/**
 * AN OFFER LETTER THAT SAYS THE SAME THING IN 2031 — Part XX,
 * Part XXXII Phase 7.
 *
 * "Offer, NDA and letter generation with IMMUTABLE SIGNED ARTEFACTS."
 *
 * The failure this prevents is subtle and common: a letter rendered on demand
 * from a template plus today's data. Re-open last year's offer letter after the
 * template was edited and the salary structure renamed, and it renders
 * differently — the document a person signed is no longer the document the
 * system shows, and neither party can prove what was agreed.
 *
 * So an artefact is FROZEN AT ISSUE: the rendered bytes are stored, hashed, and
 * never regenerated. `render()` returns what was issued, not what the template
 * would produce now. The template version and the data snapshot travel with it so
 * the document can be explained, but explaining it never means re-rendering it.
 *
 * And it is countersigned in the same shape as a statutory pack: two parties,
 * because a document only the issuer signed is a document only the issuer
 * attests to.
 */
final class LetterArtefact
{
    private ?string $issuedContent = null;
    private ?string $contentHash = null;

    /** @var array<string, array{by: string, at: string}> */
    private array $signatures = [];

    /** @param array<string, string> $data the values as they stood at issue */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $templateVersionId,
        public readonly array $data,
    ) {
    }

    /**
     * Issue the artefact ONCE. The bytes are frozen here.
     *
     * @throws \LogicException on a second issue
     */
    public function issue(string $renderedContent, \DateTimeImmutable $at): void
    {
        if ($this->issuedContent !== null) {
            throw new \LogicException(
                "Letter {$this->id} has already been issued. Re-issuing would replace the document a person "
                . 'signed with one rendered from today\'s template and today\'s data, and neither party '
                . 'could then prove what was agreed. A revision is a NEW artefact that supersedes this one.'
            );
        }

        if (trim($renderedContent) === '') {
            throw new \InvalidArgumentException("Letter {$this->id} was issued with no content.");
        }

        $this->issuedContent = $renderedContent;
        $this->contentHash = hash('sha256', $renderedContent);
        $this->signatures['issuer'] = ['by' => 'system', 'at' => $at->format('c')];
    }

    /** What was ISSUED — never what the template would produce now. */
    public function render(): string
    {
        return $this->issuedContent ?? throw new \LogicException(
            "Letter {$this->id} has not been issued, so there is nothing to render. A letter rendered on "
            . 'demand from a template plus today\'s data is a letter that changes when the template does.'
        );
    }

    public function hash(): string
    {
        return $this->contentHash ?? throw new \LogicException("Letter {$this->id} has not been issued.");
    }

    public function sign(string $party, string $by, \DateTimeImmutable $at): void
    {
        if ($this->issuedContent === null) {
            throw new \LogicException("Letter {$this->id} cannot be signed before it is issued.");
        }

        if (trim($by) === '') {
            throw new \InvalidArgumentException('A signature with no signatory signs nothing.');
        }

        $this->signatures[$party] = ['by' => $by, 'at' => $at->format('c')];
    }

    public function isFullyExecuted(): bool
    {
        // Issuer and counterparty. A document only the issuer signed is a
        // document only the issuer attests to.
        return isset($this->signatures['issuer'], $this->signatures['counterparty']);
    }

    /** Has anything altered the issued bytes since? */
    public function isIntact(): bool
    {
        return $this->issuedContent !== null && hash('sha256', $this->issuedContent) === $this->contentHash;
    }

    /** @return array<string, array{by: string, at: string}> */
    public function signatures(): array
    {
        return $this->signatures;
    }
}
