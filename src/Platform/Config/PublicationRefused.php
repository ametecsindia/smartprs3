<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

/**
 * A publication a gate refused (spine S4, Part VIII §9, Part IX §IX.3.6).
 *
 * The report travels with the exception, because "publication failed" is not
 * something an administrator can act on and "G6 blocks: denominator
 * `period.working_days` can be zero — guard it with MAX(…, 1)" is.
 *
 * R-VIII-013 asks the outcomes to be STORED as well as returned, and Publisher
 * writes them before it throws. A refused publication that leaves no record is a
 * support ticket; one that leaves twelve rows is an answer.
 */
final class PublicationRefused extends \RuntimeException
{
    public function __construct(
        public readonly PublishReport $report,
        public readonly string $artefactType,
        public readonly string $artefactKey,
    ) {
        $blocking = $report->blocking();
        $lines = ["Cannot publish {$artefactType}:{$artefactKey} — " . count($blocking) . ' gate(s) block:', ''];

        foreach ($blocking as $check) {
            $lines[] = $check->render();
        }

        parent::__construct(implode("\n", $lines));
    }
}
