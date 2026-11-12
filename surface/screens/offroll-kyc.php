<?php declare(strict_types=1);
// The old Off-roll KYC & Earnings page (SmartPRS 2's modal) is now the agent's profile (23 Oct 2026):
// an old link with ?id= opens that agent's profile, without one the agents list.
if (trim((string) ($_GET['id'] ?? '')) !== '') {
    include __DIR__ . '/offroll-agent.php';
} else {
    include __DIR__ . '/offroll-agents.php';
}
