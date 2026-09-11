<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Asks the operator for the PGP passphrase on the controlling terminal.
 *
 * Input is read without echo so the passphrase never shows up in the
 * terminal scrollback and never reaches the shell history.
 */
final class PassphrasePrompt
{
    public const MIN_LENGTH = 12;

    public const MAX_ATTEMPTS = 3;

    /** Signals that must not leave the terminal with echo disabled. */
    private const SIGNALS = ['SIGINT', 'SIGQUIT', 'SIGTERM', 'SIGHUP'];

    /** Give up on an idle passphrase prompt after this many seconds. */
    private const READ_TIMEOUT_SECONDS = 300;

    /** Consecutive stream_select() failures that mean the terminal is not selectable. */
    private const MAX_SELECT_FAILURES = 5;

    /** @var Closure(string): string */
    private readonly Closure $read;

    /**
     * @param callable(string): string $read Reads one hidden line, given the prompt label.
     */
    public function __construct(callable $read, private readonly int $minLength = self::MIN_LENGTH)
    {
        if ($minLength < 1) {
            throw new InvalidArgumentException('Minimum passphrase length must be at least 1.');
        }

        $this->read = Closure::fromCallable($read);
    }

    /**
     * Prompts on the controlling terminal. Callers should check terminalAvailable() first.
     */
    public static function interactive(int $minLength = self::MIN_LENGTH): self
    {
        return new self(self::terminalReader(), $minLength);
    }

    public static function terminalAvailable(): bool
    {
        $tty = @fopen('/dev/tty', 'r+');
        if (is_resource($tty)) {
            fclose($tty);

            return true;
        }

        return defined('STDIN') && @stream_isatty(STDIN);
    }

    /**
     * Passphrase for a keypair that is about to be generated: entered twice, minimum length enforced.
     */
    public function forNewKey(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $passphrase = ($this->read)(sprintf(
                'Enter a passphrase for the new PGP key (at least %d characters): ',
                $this->minLength,
            ));
            if (strlen($passphrase) < $this->minLength) {
                fwrite(STDERR, sprintf(
                    "The passphrase must be at least %d characters.\n",
                    $this->minLength,
                ));
                continue;
            }

            if (!Env::roundTrips($passphrase)) {
                fwrite(STDERR, "The passphrase cannot start or end with a space, or be wrapped in quotes.\n");
                continue;
            }

            if ($passphrase !== ($this->read)('Repeat the passphrase: ')) {
                fwrite(STDERR, "The passphrases did not match.\n");
                continue;
            }

            return $passphrase;
        }

        throw new RuntimeException('No matching passphrase was entered.');
    }

    /**
     * Passphrase for an existing keypair: entered once, any non-empty value is accepted.
     */
    public function forExistingKey(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $passphrase = ($this->read)('Enter the passphrase for the existing PGP private key: ');
            if ($passphrase !== '') {
                return $passphrase;
            }

            fwrite(STDERR, "The passphrase cannot be empty.\n");
        }

        throw new RuntimeException('No passphrase was entered.');
    }

    /**
     * @return Closure(string): string
     */
    private static function terminalReader(): Closure
    {
        return static function (string $label): string {
            $tty = @fopen('/dev/tty', 'r+');
            if (is_resource($tty)) {
                $input = $tty;
                $output = $tty;
            } elseif (defined('STDIN') && @stream_isatty(STDIN)) {
                $input = STDIN;
                $output = STDERR;
            } else {
                throw new RuntimeException('No terminal is available to read the passphrase.');
            }

            fwrite($output, $label);
            $saved = self::stty('-g');
            $hidden = $saved !== null;
            if (!$hidden) {
                fwrite(STDERR, "\nWarning: terminal echo could not be disabled, the passphrase will be visible.\n");
            }
            // Handlers go in before echo is disabled, or an interrupt in between
            // would leave the terminal without echo.
            $restored = self::restoreEchoOnSignals($saved, $output);
            if ($hidden) {
                self::stty('-echo');
            }

            try {
                $line = self::readHiddenLine($input);
            } finally {
                if ($hidden) {
                    self::stty($saved);
                    // The terminal did not echo the line ending we typed.
                    fwrite($output, "\n");
                }
                self::releaseSignals($restored);
                if (is_resource($tty)) {
                    fclose($tty);
                }
            }

            if ($line === false) {
                throw new RuntimeException('Unable to read the passphrase from the terminal.');
            }

            return rtrim($line, "\r\n");
        };
    }

    /**
     * Reads one line while staying interruptible: a plain blocking read would not
     * return control to PHP, so a signal handler could not restore terminal echo
     * and Ctrl-C at the prompt would leave the terminal unusable.
     *
     * @param resource $input
     */
    private static function readHiddenLine(mixed $input): string|false
    {
        $deadline = microtime(true) + self::READ_TIMEOUT_SECONDS;
        $failures = 0;

        while (true) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            $read = [$input];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);

            if ($ready === false) {
                // Interrupted by a signal: let the handler run (it restores echo),
                // then wait again. Repeated failure means the terminal is not
                // selectable here, so fall back to a blocking read.
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if (++$failures >= self::MAX_SELECT_FAILURES) {
                    return fgets($input);
                }
                continue;
            }

            $failures = 0;
            if ($ready > 0) {
                return fgets($input);
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Re-enables echo when the prompt is interrupted, so Ctrl-C at the passphrase
     * prompt does not leave the terminal unusable.
     *
     * @return array{handlers: array<int, mixed>, async: ?bool}|null
     */
    private static function restoreEchoOnSignals(string $saved, mixed $output): ?array
    {
        if ($saved === null || !function_exists('pcntl_signal') || !function_exists('pcntl_signal_get_handler')) {
            return null;
        }

        $handlers = [];
        foreach (self::SIGNALS as $name) {
            if (!defined($name)) {
                continue;
            }

            $signal = (int) constant($name);
            $handlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $signal) use ($saved, $output): void {
                self::stty($saved);
                if (is_resource($output)) {
                    fwrite($output, "\n");
                }
                exit(128 + $signal);
            });
        }

        if ($handlers === []) {
            return null;
        }

        return ['handlers' => $handlers, 'async' => pcntl_async_signals(true)];
    }

    /**
     * @param array{handlers: array<int, mixed>, async: ?bool}|null $restored
     */
    private static function releaseSignals(?array $restored): void
    {
        if ($restored === null) {
            return;
        }

        foreach ($restored['handlers'] as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($restored['async'] !== null) {
            pcntl_async_signals($restored['async']);
        }
    }

    /**
     * Runs stty against the controlling terminal, or null when it cannot be used.
     */
    private static function stty(string $argument): ?string
    {
        $output = @shell_exec('stty ' . escapeshellarg($argument) . ' < /dev/tty 2>/dev/null');
        $state = is_string($output) ? trim($output) : '';

        return $state === '' ? null : $state;
    }
}
