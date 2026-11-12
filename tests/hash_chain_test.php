<?php

declare(strict_types=1);

use SmartPRS\Platform\Audit\HashChain;

group('HashChain');

// --- canonical serialisation is order-independent ---------------------------

same(
    HashChain::canonicalise(['action' => 'employee.update', 'actor' => 'U-1', 'entity_id' => 42]),
    HashChain::canonicalise(['entity_id' => 42, 'actor' => 'U-1', 'action' => 'employee.update']),
    'map key order does not change the canonical form',
);
same(
    HashChain::next(null, ['b' => 1, 'a' => ['z' => 1, 'y' => 2]]),
    HashChain::next(null, ['a' => ['y' => 2, 'z' => 1], 'b' => 1]),
    'nested map key order does not change the hash',
);
ok(
    HashChain::canonicalise(['tags' => ['a', 'b']]) !== HashChain::canonicalise(['tags' => ['b', 'a']]),
    'list order DOES change the canonical form — in a list, order is meaning',
);
same(
    HashChain::canonicalise(['at' => new DateTimeImmutable('2026-08-15T10:00:00+00:00')]),
    HashChain::canonicalise(['at' => '2026-08-15T10:00:00+00:00']),
    'instants serialise as RFC3339, deterministically',
);
throws(
    InvalidArgumentException::class,
    static fn () => HashChain::canonicalise(['amount' => 1500.75]),
    'a float in an audit payload is rejected: it does not serialise identically everywhere',
);
same(64, strlen(HashChain::next(null, ['a' => 1])), 'a hash is 64 hex characters (sha256)');
same(
    HashChain::next(null, ['a' => 1]),
    HashChain::next(HashChain::GENESIS, ['a' => 1]),
    'a null previous hash and the genesis marker are the same thing',
);
ok(
    HashChain::next(null, ['a' => 1]) !== HashChain::next('deadbeef', ['a' => 1]),
    'the previous hash is part of the entry hash — that is the chain',
);

// --- build a chain ----------------------------------------------------------

$payloads = [
    ['seq' => 1, 'action' => 'tenant.provisioned', 'entity_type' => 'tenant', 'entity_id' => 1],
    ['seq' => 2, 'action' => 'employee.created', 'entity_type' => 'employment', 'entity_id' => 10],
    ['seq' => 3, 'action' => 'salary.changed', 'entity_type' => 'compensation', 'entity_id' => 10,
        'old_value' => ['ctc_minor' => 60000000], 'new_value' => ['ctc_minor' => 66000000]],
    ['seq' => 4, 'action' => 'payroll.approved', 'entity_type' => 'payroll_run', 'entity_id' => 3],
    ['seq' => 5, 'action' => 'rule.published', 'entity_type' => 'config_version', 'entity_id' => 77],
];

$chain = [];
$previous = HashChain::GENESIS;

foreach ($payloads as $payload) {
    $hash = HashChain::next($previous, $payload);
    $chain[] = ['payload' => $payload, 'prev_hash' => $previous, 'hash' => $hash];
    $previous = $hash;
}

same(null, HashChain::verify($chain), 'an intact chain verifies');
same(5, count($chain), 'five entries');

// --- a tamper anywhere is detected at the right index -----------------------

foreach (array_keys($chain) as $index) {
    $tampered = $chain;
    $tampered[$index]['payload']['action'] = 'employee.deleted';

    $break = HashChain::verify($tampered);
    ok($break !== null, "tampering with entry {$index} is detected");
    same($index, $break['index'] ?? -1, "the break is reported at index {$index}, where the edit was");
    ok(str_contains((string) ($break['reason'] ?? ''), 'Tampered'), 'and is reported as a tampered entry');
}

// Changing a stored hash breaks the link at the *next* entry if the entry
// itself is left consistent — but breaks at the entry itself when it is not.
$rehashed = $chain;
$rehashed[2]['hash'] = str_repeat('0', 64);
$break = HashChain::verify($rehashed);
same(2, $break['index'] ?? -1, 'a rewritten hash is caught at that entry, not later');

// Deleting an entry from the middle breaks the link at the entry that followed.
$deleted = $chain;
unset($deleted[2]);
$deleted = array_values($deleted);
$break = HashChain::verify($deleted);
same(2, $break['index'] ?? -1, 'a deleted entry is detected where the chain skips');
ok(str_contains((string) ($break['reason'] ?? ''), 'Broken link'), 'and is reported as a broken link');

// Reordering two entries breaks at the first of them.
$reordered = $chain;
[$reordered[1], $reordered[2]] = [$reordered[2], $reordered[1]];
$break = HashChain::verify($reordered);
same(1, $break['index'] ?? -1, 'a reordered pair is detected at the first moved entry');

// Inserting a well-formed but unlinked entry is caught.
$inserted = $chain;
array_splice($inserted, 3, 0, [[
    'payload' => ['seq' => 99, 'action' => 'payroll.approved', 'entity_type' => 'payroll_run', 'entity_id' => 4],
    'prev_hash' => $chain[2]['hash'],
    'hash' => HashChain::next($chain[2]['hash'], ['seq' => 99]),
]]);
$break = HashChain::verify($inserted);
ok($break !== null && $break['index'] === 3, 'a forged insertion is detected at the insertion point');

// An empty chain is intact.
same(null, HashChain::verify([]), 'an empty chain verifies');

// --- the daily anchor -------------------------------------------------------

$anchor = HashChain::anchor('7', '2026-08-15', $chain[4]['hash'], 5);
same(64, strlen($anchor), 'the anchor is a sha256');
same($anchor, HashChain::anchor('7', '2026-08-15', $chain[4]['hash'], 5), 'the anchor is deterministic');
ok(
    $anchor !== HashChain::anchor('7', '2026-08-15', $chain[4]['hash'], 6),
    'the anchor covers the entry count, so a silently dropped entry changes it',
);
ok(
    $anchor !== HashChain::anchor('8', '2026-08-15', $chain[4]['hash'], 5),
    'and covers the tenant, so one tenant cannot replay another tenant anchor',
);
