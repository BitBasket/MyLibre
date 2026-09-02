<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\LibreLinkUpSessionDTO;
use Carbon\Carbon;
use Error;
use PHPExperts\DataTypeValidator\InvalidDataTypeException;
use PHPUnit\Framework\TestCase;

final class LibreLinkUpSessionDTOTest extends TestCase
{
    public function testConstructsAndSerializes(): void
    {
        $session = new LibreLinkUpSessionDTO([
            'token' => 'abc',
            'baseUri' => 'https://api-ae.libreview.io/',
            'accountId' => 'user-1',
            'expiresAt' => '2026-09-02T00:00:00Z',
            'patientId' => 'patient-1',
        ]);

        $this->assertSame('abc', $session->token);
        $this->assertSame('https://api-ae.libreview.io/', $session->baseUri);
        $this->assertInstanceOf(Carbon::class, $session->expiresAt);
        $this->assertSame('patient-1', $session->patientId);

        $copy = new LibreLinkUpSessionDTO(array_merge($session->toArray(), ['token' => 'replacement']));
        $this->assertSame('replacement', $copy->token);
        $this->assertSame('abc', $session->token);
    }

    public function testRejectsInvalidTypes(): void
    {
        $this->expectException(InvalidDataTypeException::class);
        new LibreLinkUpSessionDTO([
            'token' => 123,
            'baseUri' => 'https://api.libreview.io/',
        ]);
    }

    public function testIsImmutable(): void
    {
        $session = new LibreLinkUpSessionDTO([
            'token' => 'abc',
            'baseUri' => 'https://api.libreview.io/',
        ]);

        $this->expectException(Error::class);
        $session->token = 'nope';
    }
}
