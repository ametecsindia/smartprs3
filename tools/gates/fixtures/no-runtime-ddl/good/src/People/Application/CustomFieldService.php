<?php
declare(strict_types=1);

namespace SmartPRS\People\Application;

use SmartPRS\People\Contracts\CustomFieldRepository;

/**
 * CLEAN FIXTURE — a custom field is a row in a declared table, never a column.
 * The comment below names DDL on purpose: prose is not a build failure, code is.
 * SmartPRS 2 would have run an ALTER TABLE here.
 */
final class CustomFieldService
{
    public function __construct(private readonly CustomFieldRepository $repo)
    {
    }

    public function add(string $entity, string $key, string $type): void
    {
        $this->repo->declareField($entity, $key, $type);
    }
}
