<?php

declare(strict_types=1);

namespace App\Contract;

use App\DTO\GlucoseReadingDTO;
use Carbon\Carbon;

interface GlucoseRepository
{
    public function save(GlucoseReadingDTO $reading): bool;

    public function latest(): ?GlucoseReadingDTO;

    /**
     * @return GlucoseReadingDTO[]
     */
    public function since(Carbon $timestamp): array;

    /**
     * @return GlucoseReadingDTO[]
     */
    public function all(): array;
}
