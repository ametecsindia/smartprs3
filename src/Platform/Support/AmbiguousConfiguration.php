<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * Spine S3.2 rule 3: "No third tie-break exists. If both tie, publication is
 * blocked ... Ambiguity is a configuration error, never a silent coin-toss."
 *
 * The message names both candidates, because the validator's job is to tell an
 * administrator which two artefacts to disambiguate.
 */
final class AmbiguousConfiguration extends \RuntimeException
{
    /** @param string[] $candidates */
    public function __construct(
        public readonly array $candidates,
        string $detail,
    ) {
        parent::__construct(
            'Ambiguous configuration: ' . implode(' and ', $candidates)
            . ' tie on specificity and priority. ' . $detail
            . ' Publication is blocked until one is made more specific or given a different priority.'
        );
    }
}
