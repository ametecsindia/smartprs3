<?php
/**
 * THE SYNTHETIC PACK — machinery evidence, NOT law.
 *
 * Every value in this file is invented. The jurisdiction `IN-ZZ` does not exist,
 * the "authority" is this repository, and `verification.synthetic` is true, which
 * makes `ContributionRow::explain()` stamp every figure derived from it. Nothing
 * here may ever be copied into a real pack.
 *
 * That is deliberate and it is the point. Part XXXII makes Phase 6 conditional on
 * a named compliance owner — "no compliance owner, no phase" — because the
 * platform can compute nothing statutory it cannot attribute. Until that
 * appointment, real PF and PT rates in this repository would be values attributed
 * to nobody, sitting in a file that looks finished. A pack that cannot be
 * mistaken for law lets the MACHINERY be finished and proven — signature,
 * canonicalisation, import and activation gates, golden replay, the immutable
 * store, applicability, snapshotting — while the CONTENT waits for the person who
 * can sign it.
 *
 * The keypair is derived from a fixed, published seed for the same reason: a
 * signing key committed to a repository is not a secret, so this one is loudly
 * not one. Production keys live in an HSM at Ametecs and the signing service
 * holds no exportable key material (§XVII.4.6).
 */
declare(strict_types=1);

use SmartPRS\Platform\Statutory\KeySet;
use SmartPRS\Platform\Statutory\PackVerifier;
use SmartPRS\Platform\Statutory\RulePack;

/** @return array{pack: RulePack, keys: KeySet, secret: string} */
function syntheticPack(array $overrides = []): array
{
    $signer = sodium_crypto_sign_seed_keypair(str_repeat("\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $counter = sodium_crypto_sign_seed_keypair(str_repeat("\x02", SODIUM_CRYPTO_SIGN_SEEDBYTES));

    $keys = new KeySet([
        'synthetic-signer' => sodium_crypto_sign_publickey($signer),
        'synthetic-counter' => sodium_crypto_sign_publickey($counter),
    ]);

    $content = $overrides['content'] ?? [
        'rules' => [
            [
                'code' => 'SYNTH-PT-001',
                'head' => 'PT',
                // No threshold is a literal: the bands live in a slab table that
                // carries its own source reference, and the rule names the table.
                'calculation' => "SLAB(salary.wage_base('is_synth_base'), 'SYNTH-BANDS', period.end)",
                'wage_base_flag' => 'is_synth_base',
                'rounding' => 'half_up',
                'source_reference' => 'SYNTHETIC — invented for machinery evidence; cites no authority '
                    . 'because it states no law',
            ],
        ],
        'slab_tables' => [
            'SYNTH-BANDS' => [
                'source_reference' => 'SYNTHETIC — invented band table, not a real jurisdiction',
                // `from` is in minor units and `rate` is a percentage — a marginal
                // walk, the shape SLAB() already implements. The figures are
                // invented; the point is that no threshold is a literal in the
                // rule, and every band table names its own authority.
                'bands' => [
                    ['from' => 0, 'rate' => '0'],
                    ['from' => 1000000, 'rate' => '2'],
                    ['from' => 2000000, 'rate' => '5'],
                ],
            ],
        ],
        // The filing calendar is MATERIALISED from these, never typed by a
        // customer and never compiled into the application (R-XVII-126).
        'obligations' => [
            [
                'code' => 'SYNTH-PT-RETURN',
                'due_day' => 15,
                'due_rule' => 'fifteen days after the period ends (SYNTHETIC — states no law)',
                'form' => 'SYNTH-FORM-1',
            ],
        ],
        'floor' => [
            'rule_code' => 'SYNTH-PT-001',
            'direction' => 'higher_is_better',
            'severity' => 'exception_required',
            'source_reference' => 'SYNTHETIC — invented floor, cites no authority because it states no law',
        ],
        'golden_cases' => [
            [
                'id' => 'SYN-01',
                'head' => 'PT',
                'establishment' => 'EST-SYNTH',
                'as_of' => '2026-10-31',
                'components' => ['CMP-A' => '30000.00', 'CMP-B' => '5000.00'],
                'component_flags' => ['CMP-A' => ['is_synth_base' => true], 'CMP-B' => []],
                'expect_base' => '30000.00',
                'expect_amount' => '700.00',
            ],
            [
                'id' => 'SYN-02',
                'head' => 'PT',
                'establishment' => 'EST-SYNTH',
                'as_of' => '2026-10-31',
                'components' => ['CMP-A' => '5000.00'],
                'component_flags' => ['CMP-A' => ['is_synth_base' => true]],
                'expect_base' => '5000.00',
                'expect_amount' => '0.00',
            ],
        ],
    ];

    $manifest = [
        'manifest_schema_version' => 1,
        'pack' => [
            'pack_key' => $overrides['pack_key'] ?? 'IN-ZZ-SYNTH',
            'jurisdiction_code' => 'IN-ZZ',
            'statute_code' => 'pt',
            'pack_version' => $overrides['version'] ?? '2026.1',
            'supersedes_version' => null,
            'title' => 'SYNTHETIC pack — machinery evidence, not law',
            'publisher' => 'ametecs',
        ],
        'validity' => [
            'effective_from' => $overrides['effective_from'] ?? '2026-10-01',
            'effective_to' => $overrides['effective_to'] ?? null,
            'announced_at' => '2026-09-12T11:04:00Z',
            'published_at' => '2026-09-12T11:47:00Z',
            'activation_deadline' => '2026-09-28',
        ],
        'compatibility' => [
            'min_platform_version' => $overrides['min_platform'] ?? '3.0.0',
            'max_platform_version' => null,
            'requires_component_flags' => ['is_synth_base'],
            'requires_symbols' => ['salary.wage_base', 'period.end'],
        ],
        'verification' => [
            'synthetic' => true,
            'verified_by' => 'NOBODY — this pack is synthetic and no person has verified it',
            'verified_role' => 'n/a (synthetic)',
            'verified_on' => '2026-09-12',
            'reviewed_by' => 'NOBODY — synthetic',
            'reviewed_role' => 'n/a (synthetic)',
            'reviewed_on' => '2026-09-12',
            'evidence_document_sha256' => hash('sha256', 'synthetic evidence document'),
            'primary_sources' => [[
                'authority' => 'SmartPRS 3 repository (NOT a statutory authority)',
                'reference' => 'tools/phase6/synthetic-pack.php',
                'dated' => '2026-09-12',
                'url' => null,
                'retrieved_on' => '2026-09-12',
                'document_sha256' => hash('sha256', 'synthetic'),
            ]],
            'legal_opinion_ref' => null,
        ],
        'integrity' => [
            'canonicalisation' => 'JCS/RFC-8785',
            'content_hash_alg' => 'SHA-256',
            'content_hash' => '',
            'signature_alg' => 'Ed25519',
            'signature' => '',
            'public_key_id' => 'synthetic-signer',
            'countersignature' => '',
            'countersign_key_id' => $overrides['countersign_key_id'] ?? 'synthetic-counter',
        ],
        'content_summary' => [
            'rules' => 1,
            'slab_tables' => 1,
            'golden_cases' => 2,
            'change_note' => 'SYNTHETIC pack. Every value is invented, the jurisdiction does not exist, and '
                . 'nothing here states any law. It exists so the pack mechanism can be proven end to end '
                . 'before a compliance owner is appointed.',
        ],
    ];

    $manifest = PackVerifier::sign(
        $manifest,
        $content,
        sodium_crypto_sign_secretkey($signer),
        sodium_crypto_sign_secretkey($counter),
    );

    return [
        'pack' => RulePack::of($manifest, $content),
        'keys' => $keys,
        'manifest' => $manifest,
        'content' => $content,
        'secret' => sodium_crypto_sign_secretkey($signer),
        'counter_secret' => sodium_crypto_sign_secretkey($counter),
    ];
}
