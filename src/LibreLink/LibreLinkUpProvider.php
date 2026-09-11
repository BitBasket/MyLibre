<?php

declare(strict_types=1);

namespace App\LibreLink;

use App\Contract\GlucoseProvider;
use App\DTO\GlucoseReadingDTO;
use App\DTO\LibreLinkUpSessionDTO;
use App\Support\Config;
use App\Support\Logger;
use Carbon\Carbon;
use PHPExperts\RESTSpeaker\NoAuth;
use PHPExperts\RESTSpeaker\RESTAuthDriver;
use PHPExperts\RESTSpeaker\RESTSpeaker;

final class LibreLinkUpProvider implements GlucoseProvider
{
    private RESTSpeaker $api;

    private ?LibreLinkUpSessionDTO $session = null;

    /** @var callable(RESTAuthDriver, string): RESTSpeaker */
    private $speakerFactory;

    private int $lastStatus = -1;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SessionStore $sessions,
        ?callable $speakerFactory = null,
    ) {
        $this->speakerFactory = $speakerFactory ?? static function (RESTAuthDriver $auth, string $baseUri): RESTSpeaker {
            return new RESTSpeaker($auth, LibreLinkUpEndpoints::normalizeBaseUri($baseUri));
        };
        $this->api = ($this->speakerFactory)(new NoAuth(), $this->initialBaseUri());
    }

    public function authenticate(): LibreLinkUpSessionDTO
    {
        $session = $this->loginAt($this->initialBaseUri(), 0);
        $this->session = $session;
        $this->sessions->save($session);
        $this->api = $this->authenticatedSpeaker($session);

        return $session;
    }

    public function getCurrentReading(): GlucoseReadingDTO
    {
        $graph = $this->fetchGraph();
        $measurement = $this->extractMeasurement($graph);

        return $this->normalizeReading($measurement);
    }

    public function getHistory(): array
    {
        $graph = $this->fetchGraph();
        $readings = [];

        $current = $this->extractMeasurement($graph);
        $readings[] = $this->normalizeReading($current);

        $points = $this->property($graph, 'graphData');
        if (is_array($points)) {
            foreach ($points as $point) {
                if (is_object($point) || is_array($point)) {
                    $readings[] = $this->normalizeReading($this->asObject($point));
                }
            }
        }

        usort(
            $readings,
            static fn (GlucoseReadingDTO $a, GlucoseReadingDTO $b): int => $a->timestamp <=> $b->timestamp
        );

        return $readings;
    }

    private function fetchGraph(): object
    {
        $session = $this->ensureSession();
        $patientId = $session->patientId ?: $this->discoverPatientId($session);
        if ($patientId !== $session->patientId) {
            $session = new LibreLinkUpSessionDTO(array_merge($session->toArray(), ['patientId' => $patientId]));
            $this->session = $session;
            $this->sessions->save($session);
        }

        $payload = $this->request('GET', LibreLinkUpEndpoints::graph($patientId), allowReauth: true);
        $data = $this->property($payload, 'data');
        if (!is_object($data) && !is_array($data)) {
            throw new LibreLinkResponseException('LibreLinkUp response did not contain a glucose measurement');
        }

        return $this->asObject($data);
    }

    private function discoverPatientId(LibreLinkUpSessionDTO $session): string
    {
        $payload = $this->request('GET', LibreLinkUpEndpoints::CONNECTIONS, allowReauth: true);
        $connections = $this->property($payload, 'data');
        if (!is_array($connections) || $connections === []) {
            $status = $this->property($payload, 'status');
            throw new LibreLinkResponseException(
                'LibreLinkUp connection lookup failed'
                . ($status !== null ? ' (status ' . json_encode($status) . ')' : '')
                . ($this->lastStatus > 0 ? ' HTTP ' . $this->lastStatus : ''),
            );
        }

        $configured = $this->config->libreLinkPatientId;
        foreach ($connections as $connection) {
            $row = $this->asObject($connection);
            $patientId = (string) ($this->property($row, 'patientId') ?? '');
            if ($patientId === '') {
                continue;
            }
            if ($configured === '' || $configured === $patientId) {
                return $patientId;
            }
        }

        throw new LibreLinkResponseException('LibreLinkUp connection lookup failed');
    }

    private function ensureSession(): LibreLinkUpSessionDTO
    {
        if ($this->session !== null && !$this->isExpired($this->session)) {
            $this->api = $this->authenticatedSpeaker($this->session);
            return $this->session;
        }

        $cached = $this->sessions->load();
        if ($cached !== null && !$this->isExpired($cached)) {
            $this->session = $cached;
            $this->api = $this->authenticatedSpeaker($cached);
            return $cached;
        }

        return $this->authenticate();
    }

    private function loginAt(string $baseUri, int $redirectHops): LibreLinkUpSessionDTO
    {
        $baseUri = LibreLinkUpEndpoints::normalizeBaseUri($baseUri);
        $this->api = ($this->speakerFactory)(new NoAuth(), $baseUri);

        $payload = $this->send('POST', LibreLinkUpEndpoints::LOGIN, [
            'email' => $this->config->libreLinkEmail,
            'password' => $this->config->libreLinkPassword,
        ], [
            'headers' => LibreLinkUpEndpoints::clientHeaders($this->config->libreLinkClientVersion),
        ]);

        $this->assertHttpOk($this->lastStatus, 'LibreLinkUp authentication failed');

        $data = is_object($payload) ? $this->property($payload, 'data') : null;
        $apiStatus = is_object($payload) ? $this->property($payload, 'status') : null;

        if (is_object($data) && $this->property($data, 'redirect') && $this->property($data, 'region')) {
            if ($redirectHops >= 2) {
                throw new LibreLinkAuthException('LibreLinkUp authentication failed: regional discovery looped');
            }
            $region = (string) $this->property($data, 'region');
            $this->logger->info('LibreLinkUp redirected to region ' . $region);
            return $this->loginAt(LibreLinkUpEndpoints::baseUriForRegion($region), $redirectHops + 1);
        }

        if ((int) $apiStatus !== 0) {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: HTTP ' . $this->lastStatus);
        }

        if (!is_object($data)) {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: malformed login response');
        }

        $ticket = $this->asObject($this->property($data, 'authTicket') ?? new \stdClass());
        $token = (string) ($this->property($ticket, 'token') ?? '');
        if ($token === '') {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: no session token');
        }

        $user = $this->property($data, 'user');
        $accountId = is_object($user) || is_array($user)
            ? (string) ($this->property($this->asObject($user), 'id') ?? '')
            : '';

        $expires = $this->property($ticket, 'expires');
        $expiresAt = is_numeric($expires) ? Carbon::createFromTimestamp((int) $expires, 'UTC') : null;

        $this->logger->info('LibreLinkUp authentication succeeded');

        return new LibreLinkUpSessionDTO([
            'token' => $token,
            'baseUri' => $baseUri,
            'accountId' => $accountId !== '' ? $accountId : null,
            'expiresAt' => $expiresAt,
            'patientId' => $this->config->libreLinkPatientId !== '' ? $this->config->libreLinkPatientId : null,
        ]);
    }

    private function request(string $method, string $path, bool $allowReauth): object
    {
        $payload = $this->send($method, $path);
        $status = $this->lastStatus;
        if ($status === 401 && $allowReauth) {
            $this->logger->info('LibreLinkUp session rejected; authenticating again');
            $this->sessions->clear();
            $this->session = null;
            $this->authenticate();
            return $this->request($method, $path, allowReauth: false);
        }

        $this->assertHttpOk($status, 'LibreLinkUp request failed');

        if (!is_object($payload)) {
            throw new LibreLinkResponseException('LibreLinkUp response did not contain a glucose measurement');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, mixed $body = null, array $options = []): mixed
    {
        $options['http_errors'] = false;

        try {
            $payload = strtoupper($method) === 'POST'
                ? $this->api->post($path, $body, $options)
                : $this->api->get($path, $options);
            $status = $this->api->getLastStatusCode();
            $this->lastStatus = $status > 0 ? $status : $this->lastStatus;
            return $payload;
        } catch (LibreLinkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            if ($response === null) {
                throw new LibreLinkNetworkException('LibreLinkUp request failed: network error', 0, $e);
            }
            $this->lastStatus = $response->getStatusCode();
            $raw = (string) $response->getBody();
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
    }

    private function assertHttpOk(int $status, string $prefix): void
    {
        if ($status === 429) {
            throw new LibreLinkRateLimitException('LibreLinkUp request rate-limited: HTTP 429');
        }
        if ($status === 401) {
            throw new LibreLinkAuthException($prefix . ': HTTP 401');
        }
        if ($status < 200 || $status >= 300) {
            if ($status < 0) {
                throw new LibreLinkNetworkException($prefix . ': network error');
            }
            throw new LibreLinkResponseException($prefix . ': HTTP ' . $status);
        }
    }

    private function extractMeasurement(object $graph): object
    {
        $connection = $this->property($graph, 'connection');
        if (is_object($connection) || is_array($connection)) {
            $measurement = $this->property($this->asObject($connection), 'glucoseMeasurement');
            if (is_object($measurement) || is_array($measurement)) {
                return $this->asObject($measurement);
            }
        }

        throw new LibreLinkResponseException('LibreLinkUp response did not contain a glucose measurement');
    }

    private function normalizeReading(object $abbott): GlucoseReadingDTO
    {
        $mgDl = $this->property($abbott, 'ValueInMgPerDl');
        if (!is_numeric($mgDl)) {
            throw new LibreLinkResponseException('LibreLinkUp response did not contain a glucose measurement');
        }

        $timestamp = $this->property($abbott, 'FactoryTimestamp')
            ?? $this->property($abbott, 'Timestamp');
        if (!is_string($timestamp) || $timestamp === '') {
            throw new LibreLinkResponseException('LibreLinkUp response did not contain a glucose measurement');
        }

        [$trend, $arrow] = TrendNormalizer::fromArrow($this->property($abbott, 'TrendArrow'));

        return new GlucoseReadingDTO([
            'timestamp' => Carbon::parse($timestamp, 'UTC'),
            'glucoseMgDl' => (int) round((float) $mgDl),
            'trend' => $trend,
            'trendArrow' => $arrow,
            'source' => 'librelinkup',
        ]);
    }

    private function authenticatedSpeaker(LibreLinkUpSessionDTO $session): RESTSpeaker
    {
        return ($this->speakerFactory)(
            new LibreLinkUpAuth(
                $session->token,
                $session->accountId,
                $this->config->libreLinkClientVersion,
            ),
            $session->baseUri,
        );
    }

    private function initialBaseUri(): string
    {
        if ($this->config->libreLinkBaseUri !== '') {
            return LibreLinkUpEndpoints::normalizeBaseUri($this->config->libreLinkBaseUri);
        }

        return LibreLinkUpEndpoints::baseUriForRegion($this->config->libreLinkRegion);
    }

    private function isExpired(LibreLinkUpSessionDTO $session): bool
    {
        if ($session->expiresAt === null) {
            return false;
        }

        return $session->expiresAt->lessThanOrEqualTo(Carbon::now('UTC')->addMinute());
    }

    private function property(mixed $subject, string $name): mixed
    {
        if (is_array($subject)) {
            return $subject[$name] ?? null;
        }
        if (is_object($subject)) {
            return $subject->{$name} ?? null;
        }

        return null;
    }

    private function asObject(mixed $value): object
    {
        if (is_object($value)) {
            return $value;
        }
        if (is_array($value)) {
            return (object) $value;
        }

        throw new LibreLinkResponseException('LibreLinkUp response did not contain a glucose measurement');
    }
}
