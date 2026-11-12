<?php

declare(strict_types=1);

use SmartPRS\Platform\Entity\AuthoringType;
use SmartPRS\Platform\Entity\Checksum;
use SmartPRS\Platform\Entity\Composite;
use SmartPRS\Platform\Entity\Encryption;
use SmartPRS\Platform\Entity\FieldDefinition;
use SmartPRS\Platform\Entity\StorageKind;
use SmartPRS\Platform\Entity\ValueCoercion;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * ENCRYPTION, CHECKSUMS AND COMPOSITES — Part X §X.2.6 and §X.3.2 #18, #30, #31.
 *
 * The three pieces that took `address`, `bank_data` and `government_identifier`
 * from declared-and-unavailable to selectable. SmartPRS 2 stores government
 * identifiers and account numbers as plain columns, validates neither, and
 * exports both in full to whoever clicked the button.
 */

group('Encryption, checksums and composites (Part X)');

$field = static fn (string $key, AuthoringType $type, array $overrides = []): FieldDefinition => new FieldDefinition(
    fieldKey: $key,
    label: 'Probe',
    description: 'A field declared for the Part X secrets suite, in the tenant\'s words.',
    authoringType: $type,
    storageKind: StorageKind::Attribute,
    groupKey: 'personal',
    displayOrder: 1,
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 1,
    versionId: $key . '-v1',
    piiClass: $overrides['piiClass'] ?? 'restricted',
    maskingRule: $overrides['maskingRule'] ?? 'last4',
    constraints: $overrides['constraints'] ?? [],
);

// =============================================================================
// ENCRYPTION — TWO KEYS, TWO JOBS
// =============================================================================

$enc = new Encryption(['k1' => str_repeat('A', 32), 'k2' => str_repeat('C', 32)], str_repeat('B', 32), 'k2');

$envelope = $enc->encrypt('ABCPE1234F');
ok(Encryption::isEnvelope($envelope), 'the envelope is self-describing, so a re-encrypt is not a double-encrypt');
same('ABCPE1234F', $enc->decrypt($envelope), 'and round-trips');
ok($enc->encrypt('X') !== $enc->encrypt('X'),
    'the same value encrypts differently every time — two employees with one Aadhaar must not be visibly a pair');
same($enc->blindIndex('ABCPE1234F'), $enc->blindIndex('abcpe 1234 f'),
    'the blind index normalises first, or the uniqueness constraint misses the duplicate it exists to catch');
ok($enc->blindIndex('ABCPE1234F') !== hash('sha256', 'ABCPE1234F'),
    'and is KEYED: a plain sha256 column is a rainbow table away from being plaintext');

// Rotation: a value written under the old key still reads.
$old = (new Encryption(['k1' => str_repeat('A', 32)], str_repeat('B', 32), 'k1'))->encrypt('OLD-VALUE');
same('OLD-VALUE', $enc->decrypt($old),
    'rotation adds a key and keeps the old ones, so nothing has to be re-encrypted in a migration window');

throws(InvalidArgumentException::class,
    static fn () => new Encryption([], str_repeat('B', 32), 'k1'),
    'no key is an error: a layer that encrypts under a constant is off in the deployment nobody checked');
throws(InvalidArgumentException::class,
    static fn () => new Encryption(['k1' => 'short'], str_repeat('B', 32), 'k1'),
    'and a short key is not padded to length — padding it silently is how a weak key becomes invisible');
throws(InvalidArgumentException::class,
    static fn () => new Encryption(['k1' => str_repeat('A', 32)], str_repeat('A', 32), 'k1'),
    'the blind-index key is a DIFFERENT key: whoever can look a value up must not thereby be able to read it');

throws(InvalidArgumentException::class,
    static fn () => $enc->decrypt('just some text'),
    'a stored value that is not an envelope is an error, never returned as-is');

$tampered = substr($envelope, 0, -4) . 'AAAA';
throws(RuntimeException::class,
    static fn () => $enc->decrypt($tampered),
    'GCM is authenticated: an altered ciphertext is reported as tampering, not skipped as a corrupt row');

// =============================================================================
// CHECKSUMS — TYPO CATCHERS, NOT PROOF
// =============================================================================

$payload = '23456789012';
$valid = $payload . Checksum::verhoeffDigit($payload);
ok(Checksum::verhoeff($valid), 'a generated Verhoeff number verifies');
ok(!Checksum::verhoeff('3' . substr($valid, 1)), 'and a single altered digit does not');
ok(!Checksum::verhoeff(substr($valid, 1, 1) . substr($valid, 0, 1) . substr($valid, 2)),
    'nor does a transposition, which is the error Verhoeff exists to catch and a plain mod-10 misses');

same(null, Checksum::panStructure('ABCPE1234F'), 'a well-formed PAN passes its structural rule');
ok(Checksum::panStructure('ABC1E1234F') !== null, 'the wrong shape is caught');
ok(str_contains((string) Checksum::panStructure('ABCZE1234F'), 'holder type'),
    'and so is an impossible holder type — there is no check digit to catch it afterwards');

ok(Checksum::luhn('4539578763621486'), 'Luhn holds for a card-shaped number');
ok(!Checksum::luhn('4539578763621487'), 'and fails on the altered check digit');

throws(InvalidArgumentException::class,
    static fn () => Checksum::verify('123', 'astrology'),
    'an undeclared algorithm is refused rather than passing by default');
same(null, Checksum::verify('anything at all', 'none'),
    "and 'none' is an explicit choice a tenant makes, not the absence of one");

// The split Law 1 asks for: WHICH algorithm is configuration.
$pan = $field('pan', AuthoringType::GovernmentIdentifier, ['constraints' => ['checksum' => 'pan_structure']]);
same('ABCPE1234F', ValueCoercion::coerce($pan, 'abcpe 1234 f')['value'],
    'an identifier normalises on the way in, so one PAN has one stored form');
ok(!ValueCoercion::coerce($pan, 'ABCZE1234F')['ok'], 'and the declared checksum runs during coercion');
ok(str_contains(ValueCoercion::coerce($pan, 'ABCZE1234F')['error'], 'pan_structure'),
    'naming the check that refused it');

$unchecked = $field('other_id', AuthoringType::GovernmentIdentifier);
ok(ValueCoercion::coerce($unchecked, 'WHATEVER123')['ok'],
    'a field declaring no checksum applies none — the algorithm is configuration, not a property of the type');

// =============================================================================
// COMPOSITES — ONE COLUMN PER SUB-FIELD, ROUND-TRIPPING EXACTLY
// =============================================================================

$address = $field('present_address', AuthoringType::Address, ['piiClass' => 'identifying', 'maskingRule' => 'none']);
$members = [
    'line1' => 'Plot 12, Kondapur',
    'city' => 'Hyderabad',
    'state' => 'TG',
    'postal_code' => '500084',
    'country' => 'IN',
];

$cells = Composite::toCells($address, $members);
same('Hyderabad', $cells['PRESENT ADDRESS — CITY'],
    'X.3.2 #18: one column per sub-field, named for the field and the member');
same(8, count($cells), 'every member gets a column, including the empty ones — a missing column is a lost value');
same($members, Composite::fromCells($address, $cells)['value'], 'and the members round-trip exactly');

$blank = Composite::fromCells($address, array_map(static fn (): string => '', $cells));
same(null, $blank['value'], 'an entirely blank composite is ABSENT, not a half-entered one');
ok($blank['ok'], 'and absent is not an error');

$partial = Composite::fromCells($address, ['PRESENT ADDRESS — LINE 1' => 'Plot 12']);
ok(!$partial['ok'], 'but a partly filled one IS: a city-less address is a defect, not a value');
ok(str_contains($partial['error'], 'CITY'), 'and the error names the columns that are missing');

$bank = $field('salary_account', AuthoringType::BankData);
$account = [
    'account_number' => '50100123456789',
    'ifsc' => 'HDFC0001234',
    'holder_name' => 'Mohammad Ejaz Hussain',
    'account_type' => 'SAVINGS',
];
$bankCells = Composite::toCells($bank, $account);

same('••••6789', $bankCells['SALARY ACCOUNT — ACCOUNT NUMBER'],
    'X.3.2 #30: the account number exports MASKED by default, so an export written without thinking is safe');
same('HDFC0001234', $bankCells['SALARY ACCOUNT — IFSC'],
    'and the IFSC does not — it is printed on a cheque, and encrypting it costs grouping for no protection');

$reimported = Composite::fromCells($bank, $bankCells);
ok(!$reimported['ok'],
    'a masked export cell is REFUSED on import: a placeholder that imports cleanly becomes somebody\'s account');
ok(str_contains($reimported['error'], 'masked'), 'and says why');

same('account_number', Composite::encryptedMember(AuthoringType::BankData),
    'exactly one member of a bank account is encrypted and blind-indexed');
same(null, Composite::encryptedMember(AuthoringType::Address), 'and an address encrypts none of itself');

same('Plot 12, Kondapur, Hyderabad, TG, 500084, IN', Composite::compose(AuthoringType::Address, $members),
    'the one-line rendering skips the empty members rather than printing their separators');

$unknownMember = ValueCoercion::coerce($address, ['line1' => 'x', 'planet' => 'Earth']);
ok(!$unknownMember['ok'],
    'an unknown member is refused, never dropped — a typo\'d sub-field that vanishes is a bank account with no IFSC');
ok(str_contains($unknownMember['error'], 'planet'), 'and the error names the member nobody declared');

$ifscChecked = $field('salary_account_2', AuthoringType::BankData, [
    'constraints' => ['member_patterns' => ['ifsc' => '/^[A-Z]{4}0[A-Z0-9]{6}$/']],
]);
ok(ValueCoercion::coerce($ifscChecked, $account)['ok'], 'a well-formed IFSC passes the declared member pattern');
ok(!ValueCoercion::coerce($ifscChecked, ['ifsc' => 'NOPE'] + $account)['ok'],
    'and a malformed one is refused, with the pattern coming from configuration so another country can differ');

// =============================================================================
// AND THE CATALOGUE NOW SAYS SO
// =============================================================================

same(32, count(AuthoringType::available()),
    'address, bank_data and government_identifier were the last three composites to become selectable');
ok(AuthoringType::Address->isAvailable(), 'an address is selectable');
ok(AuthoringType::BankData->isAvailable(), 'and so is a bank account');
ok(AuthoringType::GovernmentIdentifier->isAvailable(), 'and a government identifier');
