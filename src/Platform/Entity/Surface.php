<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * THE THIRTEEN SURFACES — Part X §X.4.1, spine S9, R-X-029.
 *
 * "These thirteen surfaces are the complete set. Any new consumer of employee
 * data is one of them or is added to this table by a platform release — never by
 * growing a second field list."
 *
 * SmartPRS 2 has no such list, which is why it has nine employee write paths
 * that nobody enumerated until somebody went looking: three of them
 * (`EssController`, `BiometricConfigController`, `LetterController`) are not
 * documented as employee writers at all. A closed enum is the smallest thing
 * that makes "which surfaces does this field appear on?" a question with an
 * answer.
 *
 * `writes()` is not decoration. A surface that only reads cannot be named in a
 * WriteCommand, so "the directory screen saved something" is not a defect this
 * platform can have.
 */
enum Surface: string
{
    case Directory = 'directory';
    case Edit = 'edit';
    case SelfOnboarding = 'self_onboarding';
    case Import = 'import';
    case Export = 'export';
    case Ess = 'ess';
    case Mss = 'mss';
    case Mobile = 'mobile';
    case Api = 'api';
    case RecruitmentConversion = 'recruitment_conversion';
    case Template = 'template';
    case Report = 'report';
    case Payslip = 'payslip';

    /**
     * The surface a workflow's finalise node writes through (R-X-039).
     *
     * Not one of the thirteen, and deliberately not added to them: it is not a
     * place a person edits an employee, it is the platform applying a change a
     * person already made on one of the thirteen, after approval. Naming it
     * separately keeps R-X-029's list honest and keeps the original actor
     * visible in the audit rather than replaced by "the workflow".
     */
    public const WORKFLOW = 'workflow';

    public function writes(): bool
    {
        return match ($this) {
            self::Directory, self::Export, self::Template, self::Report, self::Payslip => false,
            default => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Directory => 'the employee list',
            self::Edit => 'the HR employee record screen',
            self::SelfOnboarding => 'the candidate-facing journey',
            self::Import => 'bulk import',
            self::Export => 'bulk export',
            self::Ess => 'employee self-service',
            self::Mss => 'manager self-service',
            self::Mobile => 'the mobile client',
            self::Api => '/api/v3',
            self::RecruitmentConversion => 'candidate → employment conversion',
            self::Template => 'letters, offer packs and forms',
            self::Report => 'the report builder',
            self::Payslip => 'the payslip',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return list<self> the surfaces a write may name */
    public static function writable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->writes()));
    }
}
