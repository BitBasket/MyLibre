<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\LibreLink\LibreLinkUpAuth;
use PHPUnit\Framework\TestCase;

final class LibreLinkUpAuthTest extends TestCase
{
    public function testCustomAuthHeaders(): void
    {
        $auth = new LibreLinkUpAuth('the-token', 'account-uuid', '4.16.0');
        $options = $auth->generateGuzzleAuthOptions();

        $this->assertSame('Bearer the-token', $options['headers']['Authorization']);
        $this->assertSame(hash('sha256', 'account-uuid'), $options['headers']['Account-Id']);
        $this->assertSame('llu.android', $options['headers']['product']);
        $this->assertSame('4.16.0', $options['headers']['version']);
        $this->assertFalse($options['http_errors']);
    }
}
