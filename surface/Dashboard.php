<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A DASHBOARD WITH NO QUERIES OF ITS OWN.
 *
 * SmartPRS 2's dashboard runs its own SQL in `DashboardController`. So does its
 * employee list, and its reports. The headcount on the dashboard and the
 * headcount on the report screen are two answers to one question, computed by two
 * pieces of code with two sets of filters, and when they disagree — they always
 * eventually disagree — the argument cannot be settled because neither number can
 * be traced. Here every tile names a REPORT and the report is the only thing that
 * counts anything.
 *
 * A TILE WHOSE REPORT IS MISSING IS REPORTED, NEVER SHOWN AS ZERO. A KPI reading
 * 0 because its source does not exist is indistinguishable from a real zero, and
 * a real zero on a dashboard is acted on: nobody has taken leave this month,
 * nothing is pending, everything is settled. That is the worst number that can
 * appear on any screen in this product.
 *
 * A TILE THE VIEWER MAY NOT SEE SHOWS A PADLOCK, NOT A FIGURE. The reports screen
 * gates ₹4.2 crore of total CTC behind the salary permission; putting the same
 * total on a dashboard that anybody can open would hand it back. A dashboard is
 * the easiest place in any HR system to leak a number, precisely because nobody
 * thinks of a summary as data.
 *
 * ponytail: tiles read one report each and take either its total or one bucket.
 * A tile that needs two reports is a report, and belongs in `reports.json`.
 */
final class Dashboard
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly Reports $reports,
        private readonly string $file,
    ) {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['kpis'] ?? null)) {
            throw new \RuntimeException(
                "The dashboard declaration at {$this->file} is missing or unreadable. Refused rather "
                . 'than treated as "no tiles", which would render an empty landing page that looks '
                . 'like a working one with nothing to show.'
            );
        }

        return $this->config = $decoded;
    }

    /**
     * The tiles, with their figures — or with the reason there is no figure.
     *
     * @param callable(string): bool $may
     * @return list<array<string, mixed>>
     */
    public function kpis(callable $may): array
    {
        $out = [];

        foreach ((array) $this->all()['kpis'] as $kpi) {
            $out[] = $kpi + $this->figure($kpi, $may);
        }

        return $out;
    }

    /**
     * @param callable(string): bool $may
     * @return list<array<string, mixed>>
     */
    public function panels(callable $may): array
    {
        $out = [];

        foreach ((array) ($this->all()['panels'] ?? []) as $panel) {
            $report = $this->reports->find((string) $panel['report']);

            if ($report === null) {
                $out[] = $panel + ['locked' => false, 'rows' => [], 'total' => 0.0,
                    'why' => $this->missing((string) $panel['report'])];

                continue;
            }

            $gate = $this->gate($report, $may);

            if ($gate !== null) {
                $out[] = $panel + ['locked' => true, 'rows' => [], 'total' => 0.0, 'why' => $gate];

                continue;
            }

            $result = $this->reports->run($report);
            $out[] = $panel + ['locked' => false, 'rows' => $result['rows'],
                'total' => $result['total'], 'counted' => $result['counted'],
                'money' => (bool) ($report['money'] ?? false), 'why' => $result['why']];
        }

        return $out;
    }

    /**
     * @param callable(string): bool $may
     * @return array{value: int|string|null, locked: bool, why: ?string, money: bool}
     */
    private function figure(array $kpi, callable $may): array
    {
        $report = $this->reports->find((string) $kpi['report']);

        if ($report === null) {
            // NOT ZERO. A tile reading 0 because its report was renamed is a
            // number somebody acts on.
            return ['value' => null, 'locked' => false, 'money' => false,
                'why' => $this->missing((string) $kpi['report'])];
        }

        $gate = $this->gate($report, $may);

        if ($gate !== null) {
            return ['value' => null, 'locked' => true, 'money' => false, 'why' => $gate];
        }

        $result = $this->reports->run($report);

        if ($result['why'] !== null) {
            return ['value' => null, 'locked' => false, 'money' => false, 'why' => $result['why']];
        }

        $bucket = (string) ($kpi['bucket'] ?? '');

        if ($bucket === '') {
            return ['value' => $result['total'], 'locked' => false,
                'money' => (bool) ($report['money'] ?? false), 'why' => null];
        }

        foreach ($result['rows'] as $row) {
            if (strcasecmp((string) $row['label'], $bucket) === 0) {
                // The report already answered exactly — an int count or an exact
                // decimal string. Casting it to float here to put it on a tile
                // was the last place a ₹4.2-crore figure could lose its paise.
                return ['value' => $row['value'], 'locked' => false,
                    'money' => (bool) ($report['money'] ?? false), 'why' => null];
            }
        }

        // A bucket the report never produced is a genuine zero — there really are
        // none — and it says so rather than leaving the reader to guess whether
        // the tile is broken.
        return ['value' => 0, 'locked' => false, 'money' => (bool) ($report['money'] ?? false),
            'why' => sprintf('No "%s" rows at all — a real zero, not a missing figure.', $bucket)];
    }

    /** @param callable(string): bool $may */
    private function gate(array $report, callable $may): ?string
    {
        $needed = $this->reports->permissionsFor($report);

        if ($needed['why'] !== null) {
            return $needed['why'];
        }

        foreach ($needed['permissions'] as $permission) {
            if (!$may($permission)) {
                return 'This figure comes from a report you cannot open, so it is not shown here '
                    . 'either. It needs ' . implode(' and ', $needed['permissions'])
                    . ' — a dashboard is the easiest place to hand back a number the report screen '
                    . 'refuses.';
            }
        }

        return null;
    }

    private function missing(string $key): string
    {
        return sprintf(
            'No report called "%s" is declared, so there is no figure to show. Reported rather than '
            . 'rendered as zero: a tile reading 0 because its source is missing is indistinguishable '
            . 'from a real zero, and a real zero on a dashboard gets acted on.', $key);
    }
}
