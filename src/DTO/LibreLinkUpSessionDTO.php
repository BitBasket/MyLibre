<?php

declare(strict_types=1);

namespace App\DTO;

use Carbon\Carbon;
use PHPExperts\SimpleDTO\SimpleDTO;

/**
 * @property-read string  $token
 * @property-read string  $baseUri
 * @property-read ?string $accountId
 * @property-read ?Carbon $expiresAt
 * @property-read ?string $patientId
 */
final class LibreLinkUpSessionDTO extends SimpleDTO
{
    protected string $token;
    protected string $baseUri;
    protected ?string $accountId = null;
    protected ?Carbon $expiresAt = null;
    protected ?string $patientId = null;
}
