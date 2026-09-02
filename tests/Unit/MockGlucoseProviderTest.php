<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\GlucoseReadingDTO;
use App\Mock\MockGlucoseProvider;
use PHPUnit\Framework\TestCase;

final class MockGlucoseProviderTest extends TestCase
{
    public function testReturnsDtos(): void
    {
        $provider = new MockGlucoseProvider();
        $session = $provider->authenticate();
        $current = $provider->getCurrentReading();
        $history = $provider->getHistory();

        $this->assertSame('mock-session', $session->token);
        $this->assertInstanceOf(GlucoseReadingDTO::class, $current);
        $this->assertSame('mock', $current->source);
        $this->assertNotEmpty($history);
        $this->assertInstanceOf(GlucoseReadingDTO::class, $history[0]);
    }
}
