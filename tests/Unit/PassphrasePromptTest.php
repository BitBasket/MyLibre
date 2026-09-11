<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\PassphrasePrompt;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PassphrasePromptTest extends TestCase
{
    public function testNewKeyAcceptsMatchingPassphrase(): void
    {
        $prompt = new PassphrasePrompt(self::reader(['correct horse battery', 'correct horse battery']));

        $this->assertSame('correct horse battery', $prompt->forNewKey());
    }

    public function testNewKeyRejectsShortAndMismatchedEntries(): void
    {
        $prompt = new PassphrasePrompt(self::reader([
            'too-short',
            'a long enough passphrase',
            'a different passphrase',
            'a long enough passphrase',
            'a long enough passphrase',
        ]));

        $this->assertSame('a long enough passphrase', $prompt->forNewKey());
    }

    public function testNewKeyGivesUpAfterThreeAttempts(): void
    {
        $prompt = new PassphrasePrompt(self::reader(['short', 'short', 'short']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No matching passphrase was entered.');

        $prompt->forNewKey();
    }

    public function testNewKeyHonoursConfiguredMinimumLength(): void
    {
        $prompt = new PassphrasePrompt(self::reader(['four', 'four']), 4);

        $this->assertSame('four', $prompt->forNewKey());
    }

    public function testNewKeyRejectsPassphraseThatWouldNotSurviveTheEnvFile(): void
    {
        // Surrounding whitespace and wrapping quotes are stripped when .env is read back.
        $prompt = new PassphrasePrompt(self::reader([
            ' padded passphrase ',
            '"quoted passphrase"',
            'a clean passphrase',
            'a clean passphrase',
        ]));

        $this->assertSame('a clean passphrase', $prompt->forNewKey());
    }

    public function testExistingKeyReturnsAnyNonEmptyPassphrase(): void
    {
        // Short values are fine here: an existing key may use a legacy passphrase.
        $prompt = new PassphrasePrompt(self::reader(['old']));

        $this->assertSame('old', $prompt->forExistingKey());
    }

    public function testExistingKeyRejectsEmptyEntryAndGivesUp(): void
    {
        $prompt = new PassphrasePrompt(self::reader(['', '', '']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No passphrase was entered.');

        $prompt->forExistingKey();
    }

    public function testMinimumLengthMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PassphrasePrompt(self::reader([]), 0);
    }

    public function testDefaultMinimumLengthMatchesConstant(): void
    {
        $prompt = new PassphrasePrompt(self::reader([
            str_repeat('x', PassphrasePrompt::MIN_LENGTH - 1),
            str_repeat('x', PassphrasePrompt::MIN_LENGTH),
            str_repeat('x', PassphrasePrompt::MIN_LENGTH),
        ]));

        $this->assertSame(str_repeat('x', PassphrasePrompt::MIN_LENGTH), $prompt->forNewKey());
    }

    /**
     * @param list<string> $answers
     * @return callable(string): string
     */
    private static function reader(array $answers): callable
    {
        return static function (string $label) use (&$answers): string {
            self::assertNotSame('', $label);

            return array_shift($answers) ?? '';
        };
    }
}
