<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\LibreLink\TrendNormalizer;
use PHPUnit\Framework\TestCase;

final class TrendNormalizerTest extends TestCase
{
    public function testMapsKnownArrows(): void
    {
        $this->assertSame(['rapidly falling', '↓'], TrendNormalizer::fromArrow(1));
        $this->assertSame(['falling', '↘'], TrendNormalizer::fromArrow(2));
        $this->assertSame(['stable', '→'], TrendNormalizer::fromArrow(3));
        $this->assertSame(['rising', '↗'], TrendNormalizer::fromArrow(4));
        $this->assertSame(['rapidly rising', '↑'], TrendNormalizer::fromArrow(5));
    }

    public function testUnknownArrowIsNotInvented(): void
    {
        $this->assertSame([null, null], TrendNormalizer::fromArrow(99));
        $this->assertSame([null, null], TrendNormalizer::fromArrow(null));
    }
}
