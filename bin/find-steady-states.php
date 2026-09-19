#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPExperts\CSVSpeaker\CSVReader;

const SORT_KEYS = ['start', 'minutes', 'min', 'max', 'center'];

final class GlucoseSteadyStateFinder
{
    /*
     * LibreLink is lossy: the fetcher can stall and gaps of up to
     * 20 minutes are expected. Anything longer breaks the chain.
     */
    private const MAX_GAP_SECONDS = 20 * 60;

    public function __construct(
        private readonly float $variance = 5.0,
    ) {
        if ($variance < 0) {
            throw new InvalidArgumentException('Variance must be >= 0.');
        }
    }

    /**
     * @param array<int, array{
     *     timestamp: DateTimeImmutable,
     *     value: float
     * }> $samples
     *
     * @return array<int, array{
     *     start: DateTimeImmutable,
     *     end: DateTimeImmutable,
     *     duration: int,
     *     samples: int,
     *     min: float,
     *     max: float,
     *     center: float,
     *     variance: float
     * }>
     */
    public function find(array $samples): array
    {
        if ($samples === []) {
            return [];
        }

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
                    $this->findInContinuousBlock($block)
                );

                $block = [];
            }

            $block[] = $sample;
            $previousTimestamp = $timestamp;
        }

        $results = array_merge(
            $results,
            $this->findInContinuousBlock($block)
        );

        return $results;
    }

    /**
     * Finds disjoint maximal streaks. A streak is anchored to its
     * first reading, and continues while every reading stays within
     * ±variance of that anchor:
     *
     *     |reading - anchor| <= variance
     *
     * The first reading outside the band ends the streak, and that
     * reading anchors the next streak, so the output is a partition
     * of the data into steady stretches.
     */
    private function findInContinuousBlock(array $samples): array
    {
        $count = count($samples);

        if ($count === 0) {
            return [];
        }

        $results = [];

        $start = 0;
        $anchor = $samples[0]['value'];

        for ($end = 1; $end < $count; ++$end) {
            $value = $samples[$end]['value'];

            if (abs($value - $anchor) > $this->variance) {
                // [start, end - 1] is a maximal streak; end anchors a new one.
                $this->addWindow($results, $samples, $start, $end - 1);

                $start = $end;
                $anchor = $value;
            }
        }

        // Whatever remains is the final streak.
        $this->addWindow($results, $samples, $start, $count - 1);

        return $results;
    }

    private function addWindow(
        array &$results,
        array $samples,
        int $start,
        int $end
    ): void {
        // A streak needs at least 2 consecutive readings to be meaningful.
        if ($end <= $start) {
            return;
        }

        /*
         * True elapsed time, so bridged dropouts still count.
         *
         * Example:
         *
         * 00:59 -> 01:20 = 21 elapsed minutes
         *
         * even though there are 22 one-minute readings.
         */
        $duration = (int) round(
            ($samples[$end]['timestamp']->getTimestamp()
                - $samples[$start]['timestamp']->getTimestamp()) / 60
        );

        $values = array_column(
            array_slice($samples, $start, $end - $start + 1),
            'value'
        );

        $min = min($values);
        $max = max($values);

        // The anchor is the first reading of the streak.
        $anchor = $values[0];

        $results[] = [
            'start'    => $samples[$start]['timestamp'],
            'end'      => $samples[$end]['timestamp'],
            'duration' => $duration,
            'samples'  => count($values),
            'min'      => $min,
            'max'      => $max,
            'center'   => $anchor,
            'variance' => max($max - $anchor, $anchor - $min),
        ];
    }
}

/**
 * Parse:
 *
 * php find-steady-states.php glucose.csv
 * php find-steady-states.php glucose.csv --variance=2
 * php find-steady-states.php glucose.csv --variance=5
 */
function showHelp(): void
{
    echo <<<HELP
    Usage: php find-steady-states.php FILE.csv [--variance=5]

    Finds steady-state glucose windows in a CSV of 1-minute readings.

    Arguments:
      FILE.csv            CSV where column 0 is YYYYMMDD and columns 1-1440
                          are the glucose readings for 00:00-23:59 UTC.

    Options:
      --variance=N        Maximum allowed ±deviation from the streak's
                          anchor, in mg/dL. Use "inf" for no limit.
                          Default: 5
      --min=N             Only show streaks of at least N minutes.
                          Default: no minimum.
      --limit=N           Show at most the N longest streaks.
                          Use -1 for all.
                          Default: 10
      --sort=KEY[-]       Sort output by KEY: start, minutes, min, max,
                          center. A bare key sorts ascending; a trailing
                          "-" sorts descending (e.g. --sort=minutes-).
                          Default: minutes, descending.
      -h, --help          Show this help message and exit.

    HELP;
}

function parseArguments(array $argv): array
{
    array_shift($argv);

    $filename = null;
    $variance = 5.0;
    $minMinutes = null;
    $limit = 10;
    $sortKey = 'minutes';
    $sortDescending = true;

    foreach ($argv as $argument) {
        if ($argument === '-h' || $argument === '--help') {
            showHelp();
            exit(0);
        }

        if (str_starts_with($argument, '--variance=')) {
            $value = substr($argument, strlen('--variance='));

            if (strcasecmp($value, 'inf') === 0
                || strcasecmp($value, 'infinity') === 0
            ) {
                $variance = INF;
            } elseif (is_numeric($value)) {
                $variance = (float) $value;
            } else {
                throw new InvalidArgumentException(
                    "Invalid variance: {$value}"
                );
            }

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

        if (str_starts_with($argument, '--sort=')) {
            $value = substr($argument, strlen('--sort='));

            $flip = str_ends_with($value, '-');
            $key = $flip
                ? substr($value, 0, -1)
                : $value;

            if (!in_array($key, SORT_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Invalid sort key: {$value} (expected one of: "
                    . implode(', ', SORT_KEYS) . ', with an '
                    . 'optional trailing "-" to flip the direction)'
                );
            }

            /*
             * Uniform semantics: a bare key sorts ascending, a
             * trailing "-" sorts descending. (With no --sort at all,
             * the default is minutes descending.)
             */
            $sortKey = $key;
            $sortDescending = $flip;
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
            'Usage: php find-steady-states.php FILE.csv '
            . '[--variance=5] [--min=N] [--limit=10]'
        );
    }

    if ($variance < 0) {
        throw new InvalidArgumentException(
            'Variance must be >= 0.'
        );
    }

    return [$filename, $variance, $minMinutes, $limit, $sortKey, $sortDescending];
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
    [$filename, $variance, $minMinutes, $limit, $sortKey, $sortDescending] =
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

    $finder = new GlucoseSteadyStateFinder($variance);

    $windows = $finder->find($samples);
} catch (\Throwable $exception) {
    if (str_contains($exception->getMessage(), 'Allowed memory size')) {
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

$sortField = $sortKey === 'minutes' ? 'duration' : $sortKey;

usort(
    $windows,
    static fn (array $a, array $b): int => $sortDescending
        ? $b[$sortField] <=> $a[$sortField]
        : $a[$sortField] <=> $b[$sortField]
);

// Optional noise filter: keep only streaks of at least N minutes.
if ($minMinutes !== null) {
    $windows = array_values(
        array_filter(
            $windows,
            static fn (array $window): bool =>
                $window['duration'] >= $minMinutes
        )
    );
}

// Show at most the N longest streaks.
if ($limit !== -1) {
    $windows = array_slice($windows, 0, $limit);
}

printf(
    "Steady states: ±%s mg/dL%s\n\n",
    is_infinite($variance) ? '∞' : number_format($variance, 1),
    $minMinutes !== null
        ? sprintf(", minimum %d minutes", $minMinutes)
        : ''
);

if ($windows === []) {
    echo "None found.\n";
    exit(0);
}

echo "| Start UTC          | End UTC            |    Duration |   Min |   Max | Center | ±Actual |\n";
echo "|--------------------|--------------------|------------:|------:|------:|-------:|--------:|\n";

foreach ($windows as $window) {
    $hours = intdiv($window['duration'], 60);
    $minutes = $window['duration'] % 60;

    $durationText = $hours > 0
        ? sprintf('%d h %2d min', $hours, $minutes)
        : "{$minutes} min";

    printf(
        "| %-18s | %-18s | %11s | %5d | %5d | %6d | %7d |\n",
        $window['start']->format('Y-m-d H:i'),
        $window['end']->format('Y-m-d H:i'),
        $durationText,
        (int) $window['min'],
        (int) $window['max'],
        (int) $window['center'],
        (int) $window['variance'],
    );
}

