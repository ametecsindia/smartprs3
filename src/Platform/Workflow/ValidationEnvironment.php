<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

use SmartPRS\Platform\Spx\SymbolTable;

/**
 * What the eighteen checks need beyond the definition (Part IX §IX.3.6).
 *
 * Every registry defaults to NULL, not to empty, for the same reason it does in
 * the formula gates: "no role registry was supplied" and "this tenant has no
 * roles" are different answers, and only the second one should ever block. A
 * check handed nothing records NOT CHECKED and warns.
 */
final readonly class ValidationEnvironment
{
    /**
     * @param list<string>|null $roleKeys
     * @param list<string>|null $userIds
     * @param list<string>|null $groupIds
     * @param array<string, list<string>>|null $participantsByNode  node key => resolvable user ids
     * @param list<WorkflowDefinition>|null    $boundWorkflows      others bound to the same entity
     * @param array<string, bool>|null         $storedCaseResults   case name => passed
     * @param list<string>|null                $entityKeys          registered subject entities (W15)
     * @param list<string>|null                $eventKeys           registered completion events (W15)
     */
    public function __construct(
        public ?SymbolTable $symbols = null,
        public ?array $roleKeys = null,
        public ?array $userIds = null,
        public ?array $groupIds = null,
        public ?array $participantsByNode = null,
        public ?array $boundWorkflows = null,
        public ?array $storedCaseResults = null,
        public ?array $entityKeys = null,
        public ?array $eventKeys = null,
    ) {
    }
}
