<?php

declare(strict_types=1);

namespace App\Contract;

use App\DTO\GlucoseReadingDTO;
use App\DTO\LibreLinkUpSessionDTO;

interface GlucoseProvider
{
    public function authenticate(): LibreLinkUpSessionDTO;

    public function getCurrentReading(): GlucoseReadingDTO;

    /**
     * @return GlucoseReadingDTO[]
     */
    public function getHistory(): array;
}
