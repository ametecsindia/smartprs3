<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Lifecycle;

/**
 * A CANDIDATE WHO BECOMES AN EMPLOYEE WITHOUT BEING RE-TYPED — Part XX,
 * Part XXXII Phase 7.
 *
 * The scope clause is "candidate→employee conversion with NO RE-ENTRY", and the
 * reason it is a clause at all is that re-entry is not merely tedious: it is
 * where the data diverges. A name spelled one way in recruitment and another in
 * payroll is two people to a statutory return, and the discovery happens at
 * assessment.
 *
 * So `convert()` carries every captured field across and REPORTS what it could
 * not carry, rather than silently dropping it. `unmappedFields()` is the method
 * that makes "no re-entry" checkable: a conversion that leaves fields behind
 * says which, so somebody decides rather than a form quietly starting blank.
 */
final readonly class Candidate
{
    /** @param array<string, string> $captured every field recruitment collected */
    public function __construct(
        public string $id,
        public array $captured,
        public string $stage = 'applied',
    ) {
    }

    /**
     * @param array<string, string> $fieldMap candidate field => employment field
     * @return array{employment: array<string, string>, carried: int, unmapped: list<string>}
     */
    public function convert(array $fieldMap): array
    {
        $employment = [];
        $unmapped = [];

        foreach ($this->captured as $field => $value) {
            if (!isset($fieldMap[$field])) {
                $unmapped[] = $field;

                continue;
            }

            $employment[$fieldMap[$field]] = $value;
        }

        return [
            'employment' => $employment,
            'carried' => count($employment),
            // Named, never dropped. A field that quietly does not cross is a
            // field somebody re-types differently.
            'unmapped' => $unmapped,
        ];
    }
}
