#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPExperts\CSVSpeaker\CSVReader;

final class GlucoseThresholdStreakFinder
{
    /*
     * LibreLink is lossy: the fetcher can stall and gaps of up to
     * 20 minutes are expected. Anything longer breaks the chain.
     */
    private const MAX_GAP_SECONDS = 20 * 60;

    /**
     * @param array<int, array{timestamp: DateTimeImmutable, value: float}> $samples
     */
    public function __construct(
        private readonly int $allowMinutes = 0,
    ) {
        if ($allowMinutes < 0) {
            throw new InvalidArgumentException('Allowed excursion must be >= 0 minutes.');
        }
    }

    /**
     * @param callable(float): bool $predicate
     *
     * @return array<int, array{
     *     start: DateTimeImmutable,
     *     end: DateTimeImmutable,
     *     duration: int,
     *     samples: int,
     *     min: float,
     *     max: float
     * }>
     */
    public function find(array $samples, callable $predicate): array
    {
        $results = [];
        $block = [];
        $previousTimestamp = null;

        foreach ($samples as $sample) {
            $timestamp = $sample['timestamp'];

            // LibreLink data is lossy; gaps up to MAX_GAP_SECONDS are tolerated.
            if (
                $previousTimestamp !== null
                && $timestamp->getTimestamp() - $previousTimestamp->getTimestamp()
                    > self::MAX_GAP_SECONDS
            ) {
                $results = array_merge(
                    $results,
                    $this->findInContinuousBlock($block, $predicate)
                );

                $block = [];
            }

            $block[] = $sample;
            $previousTimestamp = $timestamp;
        }

        $results = array_merge(
            $results,
            $this->findInContinuousBlock($block, $predicate)
        );

        return $results;
    }

    /**
     * A streak is a maximal run of readings satisfying the predicate.
     * Readings that violate it are tolerated for up to allowMinutes
     * of continuous excursion; a longer (or final, unfinished)
     * excursion ends the streak at the last conforming reading.
     */
    private function findInContinuousBlock(array $samples, callable $predicate): array
    {
        $count = count($samples);

        if ($count === 0) {
            return [];
        }

        $results = [];

        $streakStart = null;   // index of first conforming reading
        $lastGood    = null;   // index of last conforming reading
        $excursionStart = null; // index where the current excursion began

        $flush = function () use (&$results, $samples, &$streakStart, &$lastGood): void {
            if ($streakStart !== null && $lastGood !== null && $lastGood > $streakStart) {
                $this->addStreak($results, $samples, $streakStart, $lastGood);
            }

            $streakStart = null;
            $lastGood = null;
        };

        for ($i = 0; $i < $count; ++$i) {
            $value = $samples[$i]['value'];

            if ($predicate($value)) {
                if ($streakStart === null) {
                    $streakStart = $i;
                }

                $lastGood = $i;
                $excursionStart = null;
                continue;
            }

            // A violating reading.
            if ($streakStart === null) {
                continue;
            }

            if ($excursionStart === null) {
                $excursionStart = $i;
            }

            /*
             * Count the current violating sample as consuming a slot,
             * so --allow=0 ends the streak on the very first violation
             * and --allow=N tolerates exactly N violating minutes.
             */
            $excursionMinutes = intdiv(
                $samples[$i]['timestamp']->getTimestamp()
                    - $samples[$excursionStart]['timestamp']->getTimestamp(),
                60
            ) + 1;

            if ($excursionMinutes > $this->allowMinutes) {
                // Excursion too long: the streak ended at the last good reading.
                $flush();
                $excursionStart = null;
            }
        }

        // A still-open excursion at the end of the data: only the
        // conforming part up to the last good reading counts.
        $flush();

        return $results;
    }

    private function addStreak(
        array &$results,
        array $samples,
        int $start,
        int $end
    ): void {
        /*
         * True elapsed time, so bridged dropouts still count.
         */
        $duration = (int) round(
            ($samples[$end]['timestamp']->getTimestamp()
                - $samples[$start]['timestamp']->getTimestamp()) / 60
        );

        $values = array_column(
            array_slice($samples, $start, $end - $start + 1),
            'value'
        );

        $results[] = [
            'start'    => $samples[$start]['timestamp'],
            'end'      => $samples[$end]['timestamp'],
            'duration' => $duration,
            'min'      => min($values),
            'max'      => max($values),
        ];
    }
}

/**
 * Parse:
 *
 * php find-threshold-streaks.php glucose.csv --above=180
 * php find-threshold-streaks.php glucose.csv --range=70-180 --allow=15
 */
function showHelp(): void
{
    echo <<<HELP
    Usage: php find-threshold-streaks.php FILE.csv [CRITERIA...] [OPTIONS...]

    Finds the longest uninterrupted periods where glucose satisfies each
    threshold criterion, optionally allowing brief excursions of N minutes.

    Criteria (repeatable; at least one required):
      --above=N           Streaks where glucose stays strictly > N.
                          (e.g. --above=180 --above=250)
      --below=N           Streaks where glucose stays strictly < N.
                          (e.g. --below=70)
      --max=N             Streaks where glucose never exceeds N (<= N).
                          (e.g. --max=200)
      --range=A-B         Streaks where glucose stays within A..B inclusive.
                          (e.g. --range=70-180)
      --band=C±N          Streaks where glucose stays within C-N..C+N.
                          (e.g. --band=100±5, also accepts --band=100:5)

    Options:
      --allow=N           Allow brief excursions of up to N minutes without
                          breaking a streak. Default: 0 (strict).
      --min=N             Only show streaks of at least N minutes.
                          Default: no minimum.
      --limit=N           Show at most the N longest streaks per criterion.
                          Use -1 for all. Default: 5
      --sort=MODE         Sort streaks per criterion. Modes: duration
                          (longest first, default), start, end
                          (chronological by streak start/end).
                          (events view only)
      --view=MODE         Output style. Modes: daily (one compact row per
                          calendar date, default) or events (detailed
                          per-streak tables).
      -h, --help          Show this help message and exit.

    Examples:
      php find-threshold-streaks.php glucose.csv --above=180 --above=250 --below=70
      php find-threshold-streaks.php glucose.csv --range=70-180 --allow=15 --limit=10
      php find-threshold-streaks.php glucose.csv --band=100±5 --band=100±10 --band=100±20
      php find-threshold-streaks.php glucose.csv --above=180 --sort=start
      php find-threshold-streaks.php glucose.csv --above=180 --max=250 --view=daily
      php find-threshold-streaks.php glucose.csv --above=180 --view=events
      php find-threshold-streaks.php glucose.csv --above=180 --max=180 --range=70-180 \
          --below=70 --below=54

    HELP;
}

/**
 * @return array<int, array{name: string, predicate: callable(float): bool}>
 */
function buildCriteria(array $criteria): array
{
    return $criteria;
}

function addAbove(array &$criteria, string $value): void
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Invalid --above threshold: {$value}");
    }

    $threshold = (float) $value;
    $criteria[] = [
        'name'      => sprintf('> %.0f mg/dL', $threshold),
        'predicate' => static fn (float $v): bool => $v > $threshold,
    ];
}

function addBelow(array &$criteria, string $value): void
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Invalid --below threshold: {$value}");
    }

    $threshold = (float) $value;
    $criteria[] = [
        'name'      => sprintf('< %.0f mg/dL', $threshold),
        'predicate' => static fn (float $v): bool => $v < $threshold,
    ];
}

function addMax(array &$criteria, string $value): void
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Invalid --max threshold: {$value}");
    }

    $threshold = (float) $value;
    $criteria[] = [
        'name'      => sprintf('<= %.0f mg/dL (never exceeding)', $threshold),
        'predicate' => static fn (float $v): bool => $v <= $threshold,
    ];
}

function addRange(array &$criteria, string $value): void
{
    if (!preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $value, $m)) {
        throw new InvalidArgumentException("Invalid --range (expected A-B): {$value}");
    }

    $low  = (float) $m[1];
    $high = (float) $m[2];

    if ($low >= $high) {
        throw new InvalidArgumentException("Invalid --range: low must be < high ({$value})");
    }

    $criteria[] = [
        'name'      => sprintf('%.0f-%.0f mg/dL', $low, $high),
        'predicate' => static fn (float $v): bool => $v >= $low && $v <= $high,
    ];
}

function addBand(array &$criteria, string $value): void
{
    // Accept C±N, C:+N, or C:N (both ASCII and UTF-8 ±).
    if (!preg_match('/^(\d+(?:\.\d+)?)(?::|±|\+-)(\d+(?:\.\d+)?)$/u', $value, $m)) {
        throw new InvalidArgumentException(
            "Invalid --band (expected C±N, e.g. 100±5): {$value}"
        );
    }

    $center  = (float) $m[1];
    $spread  = (float) $m[2];
    $low  = $center - $spread;
    $high = $center + $spread;

    $criteria[] = [
        'name'      => sprintf('%.0f ±%.0f mg/dL', $center, $spread),
        'predicate' => static fn (float $v): bool => $v >= $low && $v <= $high,
    ];
}

function parseArguments(array $argv): array
{
    array_shift($argv);

    $filename = null;
    $criteria = [];
    $allowMinutes = 0;
    $minMinutes = null;
    $limit = 5;
    $sort = 'duration';
    $view = 'daily';

    foreach ($argv as $argument) {
        if ($argument === '-h' || $argument === '--help') {
            showHelp();
            exit(0);
        }

        if (str_starts_with($argument, '--above=')) {
            addAbove($criteria, substr($argument, strlen('--above=')));
            continue;
        }

        if (str_starts_with($argument, '--below=')) {
            addBelow($criteria, substr($argument, strlen('--below=')));
            continue;
        }

        if (str_starts_with($argument, '--max=')) {
            addMax($criteria, substr($argument, strlen('--max=')));
            continue;
        }

        if (str_starts_with($argument, '--range=')) {
            addRange($criteria, substr($argument, strlen('--range=')));
            continue;
        }

        if (str_starts_with($argument, '--band=')) {
            addBand($criteria, substr($argument, strlen('--band=')));
            continue;
        }

        if (str_starts_with($argument, '--allow=')) {
            $value = substr($argument, strlen('--allow='));

            if (!ctype_digit($value)) {
                throw new InvalidArgumentException(
                    "Invalid allowed excursion: {$value}"
                );
            }

            $allowMinutes = (int) $value;
            continue;
        }

        if (str_starts_with($argument, '--min=')) {
            $value = substr($argument, strlen('--min='));

            if (!ctype_digit($value)) {
                throw new InvalidArgumentException(
                    "Invalid minimum duration: {$value}"
                );
            }

            $minMinutes = (int) $value;
            continue;
        }

        if (str_starts_with($argument, '--sort=')) {
            $value = substr($argument, strlen('--sort='));

            if (!in_array($value, ['duration', 'start', 'end'], true)) {
                throw new InvalidArgumentException(
                    "Invalid sort mode: {$value} (expected duration, start, or end)"
                );
            }

            $sort = $value;
            continue;
        }

        if (str_starts_with($argument, '--view=')) {
            $value = substr($argument, strlen('--view='));

            if (!in_array($value, ['daily', 'events'], true)) {
                throw new InvalidArgumentException(
                    "Invalid view mode: {$value} (expected daily or events)"
                );
            }

            $view = $value;
            continue;
        }

        if (str_starts_with($argument, '--limit=')) {
            $value = substr($argument, strlen('--limit='));

            if (!ctype_digit($value) && $value !== '-1') {
                throw new InvalidArgumentException(
                    "Invalid limit: {$value}"
                );
            }

            $limit = (int) $value;
            continue;
        }

        if ($filename === null) {
            $filename = $argument;
            continue;
        }

        throw new InvalidArgumentException(
            "Unknown argument: {$argument}"
        );
    }

    if ($filename === null) {
        throw new InvalidArgumentException(
            'Usage: php find-threshold-streaks.php FILE.csv '
            . '[--above=N|--below=N|--max=N|--range=A-B|--band=C±N] '
            . '[--allow=0] [--min=N] [--limit=5]'
        );
    }

    if ($criteria === []) {
        throw new InvalidArgumentException(
            'At least one criterion is required '
            . '(--above, --below, --max, --range, or --band).'
        );
    }

    return [$filename, $criteria, $allowMinutes, $minMinutes, $limit, $sort, $view];
}


/**
 * Split streaks that cross midnight into per-calendar-day overlaps so
 * each day's duration is represented accurately. Each sample covers a
 * one-minute interval, so a streak spans [startTs, endTs + 60).
 *
 * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable, ...}> $streaks
 *
 * @return array<string, int> Map of YYYY-MM-DD => longest clipped minutes.
 */
function clipStreaksPerDay(array $streaks): array
{
    $perDay = [];

    foreach ($streaks as $streak) {
        $startTs = $streak['start']->getTimestamp();
        $endTs = $streak['end']->getTimestamp() + 60;

        $dayStart = strtotime(
            $streak['start']->format('Y-m-d') . ' 00:00:00 UTC'
        );

        while ($dayStart < $endTs) {
            $dayEnd = $dayStart + 86400;

            $clippedMinutes = (int) (
                (min($endTs, $dayEnd) - max($startTs, $dayStart)) / 60
            );

            if ($clippedMinutes > 0) {
                $date = (new DateTimeImmutable("@{$dayStart}", new DateTimeZone('UTC')))
                    ->format('Y-m-d');

                if ($clippedMinutes > ($perDay[$date] ?? 0)) {
                    $perDay[$date] = $clippedMinutes;
                }
            }

            $dayStart = $dayEnd;
        }
    }

    return $perDay;
}

/*
|--------------------------------------------------------------------------
| Load the 1440-minute/day CSV
|--------------------------------------------------------------------------
|
| Column 0    = YYYYMMDD
| Column 1    = 00:00 UTC
| Column 2    = 00:01 UTC
| ...
| Column 1440 = 23:59 UTC
|
*/

try {
    [$filename, $criteria, $allowMinutes, $minMinutes, $limit, $sort, $view] =
        parseArguments($argv);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}

if (!file_exists($filename)) {
    fwrite(
        STDERR,
        sprintf(
            "Error: cannot access '%s': No such file or directory\n",
            $filename
        )
    );

    exit(2);
}

if (!is_file($filename)) {
    fwrite(
        STDERR,
        sprintf("Error: cannot access '%s': Not a regular file\n", $filename)
    );

    exit(2);
}

if (!is_readable($filename)) {
    fwrite(
        STDERR,
        sprintf("Error: cannot access '%s': Permission denied\n", $filename)
    );

    exit(2);
}

/*
 * PHP cannot catch memory-exhaustion fatals, so estimate the memory
 * cost up front. Rows are streamed, but the samples array itself
 * (a DateTimeImmutable + float per reading) still costs roughly 100
 * bytes of RAM per CSV byte.
 */
$memoryLimit = ini_get('memory_limit');

if ($memoryLimit !== '-1' && $memoryLimit !== false) {
    $unit = strtolower(substr($memoryLimit, -1));
    $limitBytes = (int) $memoryLimit;

    $limitBytes *= match ($unit) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };

    $fileSize = (int) (@filesize($filename) ?: 0);

    if ($fileSize * 100 > $limitBytes) {
        fwrite(
            STDERR,
            sprintf(
                "Error: '%s' (%.1f MB) is too large to process "
                . "within the PHP memory limit (%s). "
                . "Increase it with php -d memory_limit=... .\n",
                $filename,
                $fileSize / 1024 ** 2,
                $memoryLimit
            )
        );

        exit(3);
    }
}

try {
    /*
     * Stream the CSV row by row with readCSVGenerator() instead of
     * buffering the entire file with toArray(), so that years worth
     * of CGM data can be processed within a constant row buffer.
     */
    $csv = CSVReader::fromFile($filename, false);

    $samples = [];
    $utc = new DateTimeZone('UTC');
    $rowNumber = 0;

    foreach ($csv->readCSVGenerator([]) as $row) {
        ++$rowNumber;

        if ($row === [] || $row === [null]) {
            continue;
        }

        $dateString = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            trim((string) ($row[0] ?? ''))
        );

        if (!preg_match('/^\d{8}$/', $dateString)) {
            fwrite(
                STDERR,
                sprintf(
                    "Skipping row %d: invalid date '%s'\n",
                    $rowNumber + 1,
                    $dateString
                )
            );

            continue;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Ymd',
            $dateString,
            $utc
        );

        /*
         * createFromFormat() silently normalizes impossible dates
         * (e.g. 20260230 -> 2026-03-02) instead of returning false,
         * so round-trip the parsed value to reject them.
         */
        if ($date === false || $date->format('Ymd') !== $dateString) {
            fwrite(
                STDERR,
                sprintf(
                    "Skipping row %d: invalid date '%s'\n",
                    $rowNumber + 1,
                    $dateString
                )
            );

            continue;
        }

        /*
         * There should be 1 date column + 1440 glucose columns.
         */
        for ($minute = 0; $minute < 1440; ++$minute) {
            $column = $minute + 1;
            $rawValue = $row[$column] ?? '';

            // A blank cell means that minute has no reading.
            if ($rawValue === '' || $rawValue === null) {
                continue;
            }

            if (!is_numeric($rawValue)) {
                fwrite(
                    STDERR,
                    sprintf(
                        "Ignoring invalid glucose value at %s + %d min: %s\n",
                        $dateString,
                        $minute,
                        $rawValue
                    )
                );

                continue;
            }

            $samples[] = [
                'timestamp' => $date->modify("+{$minute} minutes"),
                'value'     => (float) $rawValue,
            ];
        }
    }

    usort(
        $samples,
        static fn (array $a, array $b): int =>
            $a['timestamp'] <=> $b['timestamp']
    );

    $finder = new GlucoseThresholdStreakFinder($allowMinutes);

    $criteriaResults = [];

    foreach ($criteria as $criterion) {
        $streaks = $finder->find($samples, $criterion['predicate']);

        if ($view === 'daily') {
            // Compact per-day summary: clip streaks at midnight and
            // record the longest qualifying span per calendar date.
            $perDay = clipStreaksPerDay($streaks);

            if ($minMinutes !== null) {
                $perDay = array_filter(
                    $perDay,
                    static fn (int $minutes): bool => $minutes >= $minMinutes
                );
            }

            $criteriaResults[] = [
                'name'   => $criterion['name'],
                'perDay' => $perDay,
            ];

            continue;
        }

        // Sort streaks by the requested key.
        usort(
            $streaks,
            match ($sort) {
                'start' => static fn (array $a, array $b): int =>
                    $a['start'] <=> $b['start'],
                'end' => static fn (array $a, array $b): int =>
                    $a['end'] <=> $b['end'],
                default => static fn (array $a, array $b): int =>
                    $b['duration'] <=> $a['duration'],
            }
        );

        // Optional noise filter: keep only streaks of at least N minutes.
        if ($minMinutes !== null) {
            $streaks = array_values(
                array_filter(
                    $streaks,
                    static fn (array $streak): bool =>
                        $streak['duration'] >= $minMinutes
                )
            );
        }

        if ($limit !== -1) {
            $streaks = array_slice($streaks, 0, $limit);
        }

        $criteriaResults[] = [
            'name'    => $criterion['name'],
            'streaks' => $streaks,
        ];
    }
} catch (\Throwable $exception) {    if (str_contains($exception->getMessage(), 'Allowed memory size')) {
        fwrite(
            STDERR,
            sprintf(
                "Error: ran out of memory processing '%s'.\n",
                $filename
            )
        );

        exit(3);
    }

    fwrite(
        STDERR,
        sprintf(
            "Error: failed to process '%s': %s\n",
            $filename,
            $exception->getMessage()
        )
    );

    exit(2);
}

printf(
    "Threshold streaks%s\n\n",
    $allowMinutes > 0
        ? sprintf(" (excursions of up to %d minutes allowed)", $allowMinutes)
        : ''
);

if ($view === 'daily') {
    /*
     * One row per calendar date, one column per criterion.
     */
    $dates = [];

    foreach ($criteriaResults as $criterionResult) {
        foreach (array_keys($criterionResult['perDay']) as $date) {
            $dates[$date] = true;
        }
    }

    $dates = array_keys($dates);
    sort($dates);

    if ($dates === []) {
        echo "No qualifying streaks found.\n";
        exit(0);
    }

    /*
     * Compact single-line headers: the threshold label with its unit,
     * without the "Longest" prefix or "(never exceeding)" qualifier.
     */
    $shortenLabel = static function (string $name): string {
        return str_replace(' (never exceeding)', '', $name);
    };

    $headers = ['Date'];
    foreach ($criteriaResults as $criterionResult) {
        $headers[] = $shortenLabel($criterionResult['name']);
    }

    $rows = [$headers];

    foreach ($dates as $date) {
        $row = [$date];

        foreach ($criteriaResults as $criterionResult) {
            $minutes = $criterionResult['perDay'][$date] ?? null;

            $row[] = $minutes === null
                ? '-'
                : ($minutes >= 60
                    ? sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60)
                    : sprintf('%d', $minutes));
        }

        $rows[] = $row;
    }

    $widths = [];

    foreach ($headers as $index => $header) {
        $width = strlen($header);

        foreach (array_slice($rows, 1) as $row) {
            $width = max($width, strlen($row[$index]));
        }

        $widths[$index] = $width;
    }

    foreach ($rows as $rowIndex => $row) {
        $cells = [];

        foreach ($row as $index => $cell) {
            $isNumeric = $index > 0 && $rowIndex > 0;
            $cells[] = $isNumeric
                ? str_pad($cell, $widths[$index], ' ', STR_PAD_LEFT)
                : str_pad($cell, $widths[$index]);
        }

        echo '| ' . implode(' | ', $cells) . " |\n";

        if ($rowIndex === 0) {
            $separators = [];

            /*
             * Each cell is rendered as "| <padded cell> |", so the
             * separator needs two extra characters to span the padding.
             */
            foreach ($widths as $index => $width) {
                $separators[] = $index === 0
                    ? str_repeat('-', $width + 2)
                    : str_repeat('-', $width + 1) . ':';
            }

            echo '|' . implode('|', $separators) . "|\n";
        }
    }

    exit(0);
}

foreach ($criteriaResults as $criterionResult) {
    printf("== %s ==\n", $criterionResult['name']);

    if ($criterionResult['streaks'] === []) {
        echo "None found.\n\n";
        continue;
    }

    echo "| Start UTC          | End UTC            |    Duration |   Min |   Max |\n";
    echo "|--------------------|--------------------|------------:|------:|------:|\n";

    foreach ($criterionResult['streaks'] as $streak) {
        $hours = intdiv($streak['duration'], 60);
        $minutes = $streak['duration'] % 60;

        $durationText = $hours > 0
            ? sprintf('%d h %2d min', $hours, $minutes)
            : "{$minutes} min";

        printf(
            "| %-18s | %-18s | %11s | %5d | %5d |\n",
            $streak['start']->format('Y-m-d H:i'),
            $streak['end']->format('Y-m-d H:i'),
            $durationText,
            (int) $streak['min'],
            (int) $streak['max'],
        );
    }

    echo "\n";
}
