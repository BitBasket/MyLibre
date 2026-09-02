<?php

declare(strict_types=1);

namespace App\Database;

use App\Contract\GlucoseRepository;
use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;
use PDO;

final class SQLiteGlucoseRepository implements GlucoseRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function save(GlucoseReadingDTO $reading): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO glucose_readings
                (timestamp, glucose_mg_dl, trend, trend_arrow, source)
             VALUES
                (:timestamp, :glucose_mg_dl, :trend, :trend_arrow, :source)'
        );

        $statement->execute([
            ':timestamp' => $reading->timestamp->getTimestamp(),
            ':glucose_mg_dl' => $reading->glucoseMgDl,
            ':trend' => $reading->trend,
            ':trend_arrow' => $reading->trendArrow,
            ':source' => $reading->source,
        ]);
    }

    public function latest(): ?GlucoseReadingDTO
    {
        $row = $this->pdo->query(
            'SELECT timestamp, glucose_mg_dl, trend, trend_arrow, source
             FROM glucose_readings
             ORDER BY timestamp DESC
             LIMIT 1'
        )->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function since(Carbon $timestamp): array
    {
        $statement = $this->pdo->prepare(
            'SELECT timestamp, glucose_mg_dl, trend, trend_arrow, source
             FROM glucose_readings
             WHERE timestamp >= :timestamp
             ORDER BY timestamp ASC'
        );
        $statement->execute([':timestamp' => $timestamp->getTimestamp()]);

        $readings = [];
        foreach ($statement as $row) {
            $readings[] = $this->hydrate($row);
        }

        return $readings;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): GlucoseReadingDTO
    {
        return new GlucoseReadingDTO([
            'timestamp' => Carbon::createFromTimestamp((int) $row['timestamp'], 'UTC'),
            'glucoseMgDl' => (int) $row['glucose_mg_dl'],
            'trend' => $row['trend'] !== null ? (string) $row['trend'] : null,
            'trendArrow' => $row['trend_arrow'] !== null ? (string) $row['trend_arrow'] : null,
            'source' => (string) $row['source'],
        ]);
    }
}
