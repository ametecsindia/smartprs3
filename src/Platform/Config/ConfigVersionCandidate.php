<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * One published config_versions row, lifted out of the database so the resolver
 * can stay a pure function (spine S4, V.8.1). The repository builds these; the
 * domain never issues a query.
 */
final readonly class ConfigVersionCandidate
{
    public function __construct(
        public string $artefactType,
        public string $artefactKey,
        public string $versionId,
        public int $versionNo,
        public Scope $scope,
        public int $priority,
        public EffectiveRange $range,
        public mixed $payload = null,
    ) {
    }

    public function label(): string
    {
        return "{$this->artefactType}:{$this->artefactKey} v{$this->versionNo}"
            . " (id {$this->versionId}, scope {$this->scope}, priority {$this->priority})";
    }
}
