<?php

declare(strict_types=1);

namespace App\DTO;

use Carbon\Carbon;
use PHPExperts\SimpleDTO\SimpleDTO;

/**
 * @property-read Carbon  $timestamp
 * @property-read int     $glucoseMgDl
 * @property-read ?string $trend
 * @property-read ?string $trendArrow
 * @property-read string  $source
 */
final class GlucoseReadingDTO extends SimpleDTO
{
    protected Carbon $timestamp;
    protected int $glucoseMgDl;
    protected ?string $trend;
    protected ?string $trendArrow;
    protected string $source = 'librelinkup';
}
