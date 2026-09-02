# Build a LibreLinkUp Glucose Dashboard in PHP

Build a lightweight, local-first Progressive Web App that displays my current FreeStyle Libre 2 glucose readings on my Linux laptop by retrieving them through the unofficial LibreLinkUp API.

The backend must be written in **modern PHP**, not Node.js, TypeScript, Rust, Python, or another language.

Use these PHP Experts libraries as first-class architectural components:

```bash
composer require phpexperts/simple-dto
composer require phpexperts/rest-speaker
```

Specifically:

* Use `phpexperts/rest-speaker` for **all communication with Abbott / LibreLinkUp**.
* Use `phpexperts/simple-dto` for **all important data structures crossing architectural boundaries**, especially normalized glucose readings and LibreLinkUp session/connection information.
* Do not introduce Guzzle directly as an application dependency or build a parallel HTTP abstraction around it. RESTSpeaker already provides the HTTP abstraction.
* Do not replace SimpleDTO with hand-written array bags, generic `stdClass` objects, Symfony DTOs, Laravel data objects, or another DTO package.

---

# Goal

I currently use the official LibreLink EG Android app to receive FreeStyle Libre 2 readings.

I want to stop repeatedly checking my phone.

The architecture should be:

```text
FreeStyle Libre 2
      |
      | BLE
      v
LibreLink EG on Android
      |
      | Abbott / LibreLinkUp cloud
      v
PHP LibreLinkUp poller
      |
      v
SQLite
      |
      +-----------------------+
      |                       |
      v                       v
PHP local API            Chrome PWA
                              |
                              | polls localhost
                              v
                     Current glucose + chart
```

Do NOT attempt direct Bluetooth communication with the Libre 2.

Do NOT interfere with the official LibreLink EG app.

Do NOT attempt to become another BLE receiver.

The official phone application remains responsible for receiving sensor data and uploading it to Abbott.

---

# Technology Stack

Use:

* PHP 8.4+
* Composer
* `phpexperts/rest-speaker`
* `phpexperts/simple-dto`
* PDO SQLite
* Vanilla JavaScript
* HTML/CSS
* Chart.js
* PWA manifest
* Service worker
* PHPUnit
* Linux systemd user service

Avoid unnecessary frameworks.

Do NOT use:

* Laravel
* Symfony Framework
* React
* Vue
* Angular
* TypeScript
* Node.js as an application runtime
* WebSockets
* Redis
* RabbitMQ
* Docker as a runtime requirement

A lightweight PHP router is acceptable if useful, but do not introduce a full-stack framework.

---

# Why RESTSpeaker Is Required

LibreLinkUp is an unofficial/private HTTP API from this application's perspective.

All Abbott HTTP interaction must therefore be isolated behind:

```text
src/LibreLink/
```

and implemented using:

```php
PHPExperts\RESTSpeaker\RESTSpeaker
```

RESTSpeaker should own HTTP transport concerns.

Do NOT scatter raw HTTP calls throughout the application.

Do NOT instantiate Guzzle directly in application code.

---

# RESTSpeaker Usage

Install it with:

```bash
composer require phpexperts/rest-speaker
```

RESTSpeaker is built around an authentication strategy and a base URI.

The base URI MUST end with `/`.

For unauthenticated requests:

```php
use PHPExperts\RESTSpeaker\NoAuth;
use PHPExperts\RESTSpeaker\RESTSpeaker;

$api = new RESTSpeaker(
    new NoAuth(),
    'https://example.libreview.io/'
);
```

RESTSpeaker automatically handles JSON-oriented REST calls.

For example:

```php
$response = $api->get('api/example');
```

For POST requests, pass the PHP array/object as the second argument:

```php
$response = $api->post('llu/auth/login', [
    'email' => $email,
    'password' => $password,
]);
```

RESTSpeaker will serialize POST/PUT/PATCH bodies appropriately for JSON APIs.

Use:

```php
$api->getLastStatusCode();
```

when response handling needs the HTTP status code.

The application should normally receive already-decoded JSON objects from RESTSpeaker rather than manually repeating:

```php
json_decode(
    $response->getBody()->getContents()
);
```

Do not duplicate functionality RESTSpeaker already provides.

---

# LibreLinkUp Authentication With RESTSpeaker

LibreLinkUp authentication appears to involve at least two phases:

```text
Unauthenticated login
        |
        v
Receive session/token/region information
        |
        v
Authenticated LibreLinkUp requests
```

Implement this explicitly.

Use:

```php
RESTSpeaker + NoAuth
```

for login/discovery.

After authentication succeeds, construct a RESTSpeaker using a custom LibreLinkUp authentication driver.

Create something similar to:

```text
src/LibreLink/LibreLinkUpAuth.php
```

The auth strategy should extend:

```php
PHPExperts\RESTSpeaker\RESTAuth
```

and use RESTSpeaker's custom-auth mechanism.

Conceptually:

```php
use PHPExperts\RESTSpeaker\RESTAuth;

final class LibreLinkUpAuth extends RESTAuth
{
    public function __construct(
        private string $token,
        private ?string $accountId = null,
    ) {
        parent::__construct(self::AUTH_MODE_CUSTOM);
    }

    protected function generateCustomAuthOptions(): array
    {
        return [
            'headers' => [
                // Populate exactly the headers actually required
                // by the current LibreLinkUp protocol.
            ],
        ];
    }
}
```

The exact required Abbott headers MUST be established by inspecting current working LibreLinkUp implementations and verifying actual behavior.

Do not invent headers.

Likely concerns include things such as:

* bearer/session token
* account/user identifier
* LibreLink client version
* product identifier
* regional server
* API version

But determine the current requirements before implementing them.

RESTSpeaker will automatically merge:

```php
generateGuzzleAuthOptions()
```

into outgoing requests.

Therefore, Abbott authentication headers must live in the custom auth strategy rather than being manually repeated in every API call.

This is important.

Prefer:

```php
$api = new RESTSpeaker(
    new LibreLinkUpAuth($session->token, $session->accountId),
    $session->baseUri
);

$connections = $api->get('llu/connections');
```

instead of:

```php
$api->get('llu/connections', [
    'headers' => [
        'Authorization' => ...,
        ...
    ],
]);
```

on every request.

---

# Region Discovery

My Libre installation is **LibreLink EG / Egypt**.

Do NOT assume the United States LibreLinkUp host.

LibreLinkUp authentication can redirect/discover the actual regional backend.

Implement regional discovery correctly.

The initial configuration may contain:

```env
LIBRELINK_EMAIL=
LIBRELINK_PASSWORD=
LIBRELINK_REGION=AUTO
```

`AUTO` should be preferred.

If login determines that the account belongs to another LibreView region, create the authenticated RESTSpeaker using the discovered regional base URI.

Remember:

```text
RESTSpeaker base URIs must end with /
```

Normalize this before instantiation.

Also allow an explicit region/base URI override for debugging.

---

# SimpleDTO Is Required

Install:

```bash
composer require phpexperts/simple-dto
```

Use:

```php
PHPExperts\SimpleDTO\SimpleDTO
```

for domain and transport DTOs.

SimpleDTO DTOs should be treated as immutable values.

Construct them once and replace them with a new DTO when data changes.

Do NOT mutate them after construction.

---

# Required DTO: GlucoseReadingDTO

Create:

```text
src/DTO/GlucoseReadingDTO.php
```

Something conceptually like:

```php
<?php

declare(strict_types=1);

namespace App\DTO;

use Carbon\Carbon;
use PHPExperts\SimpleDTO\SimpleDTO;

/**
 * @property-read Carbon     $timestamp
 * @property-read int        $glucoseMgDl
 * @property-read ?string    $trend
 * @property-read ?string    $trendArrow
 * @property-read string     $source
 */
final class GlucoseReadingDTO extends SimpleDTO
{
    protected Carbon $timestamp;
    protected int $glucoseMgDl;
    protected ?string $trend;
    protected ?string $trendArrow;
    protected string $source = 'librelinkup';
}
```

Adapt this to the precise SimpleDTO version installed if necessary.

SimpleDTO supports automatic conversion of appropriate date strings into Carbon objects, so use that feature rather than manually passing date strings around the application.

A reading should be created like:

```php
$reading = new GlucoseReadingDTO([
    'timestamp'   => $timestamp,
    'glucoseMgDl' => 174,
    'trend'       => 'falling',
    'trendArrow'  => '↘',
    'source'      => 'librelinkup',
]);
```

Access values through DTO properties:

```php
$reading->glucoseMgDl;
$reading->timestamp;
$reading->trendArrow;
```

When persistence or API serialization requires an array:

```php
$reading->toArray();
```

When JSON output is appropriate, SimpleDTO is JSON serializable:

```php
json_encode($reading);
```

Do not repeatedly transform the normalized reading back into arbitrary arrays throughout the domain layer.

---

# Required DTO: LibreLinkUpSessionDTO

Create something similar to:

```text
src/DTO/LibreLinkUpSessionDTO.php
```

Conceptually:

```php
/**
 * @property-read string  $token
 * @property-read string  $baseUri
 * @property-read ?string $accountId
 * @property-read ?Carbon $expiresAt
 */
final class LibreLinkUpSessionDTO extends SimpleDTO
{
    protected string $token;
    protected string $baseUri;
    protected ?string $accountId;
    protected ?Carbon $expiresAt;
}
```

Do NOT mutate a session DTO when its token changes.

Instead:

```php
$newSession = new LibreLinkUpSessionDTO([
    // new values
]);
```

This preserves SimpleDTO's immutable-value semantics.

---

# Optional LibreLinkUp DTOs

Where useful, also create DTOs such as:

```text
LibreLinkUpConnectionDTO
LibreLinkUpUserDTO
LibreLinkUpSensorDTO
```

But avoid DTO explosion.

Use DTOs where they provide a useful architectural contract.

The two most important contracts are:

```text
LibreLinkUpSessionDTO
GlucoseReadingDTO
```

---

# Abbott Response Objects vs Internal DTOs

RESTSpeaker may return decoded Abbott JSON as objects.

That is acceptable **inside the LibreLinkUp adapter only**.

For example:

```php
$response = $api->get(...);
```

may produce an upstream API object.

Do NOT allow that object to escape from:

```text
src/LibreLink/
```

Translate it immediately into our own DTO.

Conceptually:

```php
private function normalizeReading(object $abbott): GlucoseReadingDTO
{
    return new GlucoseReadingDTO([
        'timestamp'   => ...,
        'glucoseMgDl' => ...,
        'trend'       => ...,
        'trendArrow'  => ...,
        'source'      => 'librelinkup',
    ]);
}
```

The rest of the application must know nothing about Abbott's JSON field names.

This boundary is critical.

---

# Glucose Provider Interface

Create:

```php
interface GlucoseProvider
{
    public function authenticate(): LibreLinkUpSessionDTO;

    public function getCurrentReading(): GlucoseReadingDTO;

    /**
     * @return GlucoseReadingDTO[]
     */
    public function getHistory(): array;
}
```

The implementation should be:

```text
LibreLinkUpProvider
```

The rest of the application should depend on:

```text
GlucoseProvider
```

rather than the concrete LibreLinkUp implementation.

---

# LibreLinkUpProvider

Create approximately:

```text
src/LibreLink/
    LibreLinkUpProvider.php
    LibreLinkUpAuth.php
    LibreLinkUpEndpoints.php
```

`LibreLinkUpProvider` owns:

* authentication
* regional discovery
* session handling
* connection discovery
* current glucose retrieval
* graph/history retrieval
* mapping Abbott values into SimpleDTO objects

It should own one or more RESTSpeaker clients internally.

Conceptually:

```php
final class LibreLinkUpProvider implements GlucoseProvider
{
    private RESTSpeaker $api;

    private ?LibreLinkUpSessionDTO $session = null;

    public function authenticate(): LibreLinkUpSessionDTO
    {
        // Login via RESTSpeaker + NoAuth.
        // Determine region.
        // Build LibreLinkUpSessionDTO.
        // Create authenticated RESTSpeaker.
    }

    public function getCurrentReading(): GlucoseReadingDTO
    {
        // RESTSpeaker request.
        // Validate response.
        // Normalize Abbott object.
        // Return GlucoseReadingDTO.
    }
}
```

---

# Session Persistence

Do NOT authenticate from scratch every 60 seconds.

Persist reusable session/token information locally when practical.

Possible location:

```text
data/libre-session.json
```

or SQLite.

Do not store the password there.

The password should exist only in:

```env
LIBRELINK_PASSWORD=
```

with appropriate filesystem permissions.

On startup:

1. Load an existing session if available.
2. Try it.
3. If rejected/expired, perform a fresh authentication.
4. Replace the old immutable `LibreLinkUpSessionDTO`.
5. Persist the replacement session.
6. Continue polling.

---

# Poller

Create:

```text
bin/poll-glucose
```

or:

```text
bin/poll-glucose.php
```

The program should run continuously:

```php
while (true) {
    try {
        $reading = $provider->getCurrentReading();

        $repository->save($reading);
    } catch (Throwable $e) {
        // Safe logging.
        // Re-authenticate when appropriate.
        // Back off appropriately for upstream failures.
    }

    sleep(60);
}
```

Do not blindly retry authentication errors forever at one-second intervals.

Distinguish:

* authentication/session expiration
* rate limiting
* network failure
* malformed upstream response
* database error

Use sensible backoff.

After a successful poll, resume approximately 60-second polling.

---

# SQLite

Use native:

```php
PDO
```

with SQLite.

Do not install an ORM.

Create something like:

```sql
CREATE TABLE glucose_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp INTEGER NOT NULL UNIQUE,
    glucose_mg_dl INTEGER NOT NULL,
    trend TEXT,
    trend_arrow TEXT,
    source TEXT NOT NULL
);

CREATE INDEX idx_glucose_timestamp
    ON glucose_readings(timestamp);
```

The repository API should use DTOs:

```php
interface GlucoseRepository
{
    public function save(GlucoseReadingDTO $reading): void;

    public function latest(): ?GlucoseReadingDTO;

    /**
     * @return GlucoseReadingDTO[]
     */
    public function since(Carbon $timestamp): array;
}
```

When reading from SQLite, reconstruct:

```php
new GlucoseReadingDTO([...]);
```

Do not return database rows as arbitrary arrays to controllers.

---

# Deduplication

LibreLinkUp will often return the same latest measurement until a new sensor reading exists.

Do not create duplicate rows.

Use the source measurement timestamp as the primary deduplication criterion.

SQLite may use:

```sql
INSERT OR IGNORE
```

against the unique timestamp.

The poller should consider receiving the same reading again a successful poll, not an error.

---

# Local HTTP API

Expose at minimum:

```text
GET /api/glucose/current
GET /api/glucose/history
GET /api/glucose/history?hours=3
GET /api/glucose/history?hours=6
GET /api/glucose/history?hours=12
GET /api/glucose/history?hours=24
GET /api/health
```

There is no need for SSE or WebSockets.

The browser can cheaply poll localhost every few seconds.

---

# Browser Polling

Have the PWA request:

```text
/api/glucose/current
```

approximately every 5 seconds.

This is a localhost SQLite lookup, NOT an Abbott API request.

Therefore:

```text
Abbott API polling: approximately every 60 seconds
Browser localhost polling: approximately every 5 seconds
```

Keep these concerns separate.

The browser must NEVER cause an immediate LibreLinkUp request.

The architecture should guarantee:

```text
PWA -> local API -> SQLite
```

not:

```text
PWA -> local API -> Abbott
```

This prevents browser refreshes or multiple tabs from increasing Abbott API traffic.

---

# API Response

Example:

```json
{
  "glucoseMgDl": 174,
  "trend": "falling",
  "trendArrow": "↘",
  "timestamp": "2026-09-01T19:31:00Z",
  "ageSeconds": 38,
  "stale": false
}
```

The stored `GlucoseReadingDTO` should remain the canonical measurement.

Fields such as:

```text
ageSeconds
stale
```

are derived presentation/API metadata and should be calculated from:

```text
GlucoseReadingDTO.timestamp
```

at request time.

Do not persist `ageSeconds`.

---

# PWA Dashboard

The dashboard's central purpose is:

> Let me glance at my laptop instead of constantly checking my phone.

Prioritize the current reading.

Something approximately like:

```text
             174
            mg/dL

              ↘

       updated 42 sec ago


   250 ┐
       │       ╭──╮
   200 │   ╭───╯  ╰──
       │ ╭─╯
   150 ┼─╯
       └────────────────
          last 3 hours
```

Below/around the current value show:

* glucose mg/dL
* Libre-provided trend
* trend arrow
* reading timestamp
* age of reading
* stale state
* recent graph

Provide history buttons:

```text
3h
6h
12h
24h
```

Use Chart.js or an equivalently small chart library.

No SPA framework is necessary.

---

# Trend Handling

Normalize Abbott's trend representation inside:

```text
LibreLinkUpProvider
```

rather than teaching the browser Abbott-specific numeric codes.

Possible normalized values:

```text
↑ rapidly rising
↗ rising
→ stable
↘ falling
↓ rapidly falling
```

But determine the real LibreLinkUp trend semantics from the upstream API.

Do not invent a clinical interpretation if Abbott provides insufficient information.

The DTO should contain normalized application values:

```php
$reading->trend;
$reading->trendArrow;
```

---

# Stale Data

Staleness must be obvious.

Suggested behavior:

```text
< 3 minutes   normal
3–10 minutes  stale warning
> 10 minutes  clearly disconnected/stale
```

For example:

```text
174 mg/dL
↘
Last reading 8 minutes ago

DATA STALE
```

Do NOT continue displaying an old measurement in a way that visually implies it is current.

---

# Offline PWA Behavior

The service worker should cache:

* HTML
* CSS
* JavaScript
* icons
* manifest

Previously retrieved graph data may be kept client-side so reopening the PWA temporarily offline can show the last-known state.

However, clearly indicate the actual age of the data.

Never represent cached values as fresh readings.

---

# Mock Provider

Implement:

```text
MockGlucoseProvider
```

which also satisfies:

```php
GlucoseProvider
```

and returns real:

```php
GlucoseReadingDTO
```

instances.

Enable it via:

```env
GLUCOSE_PROVIDER=mock
```

Production:

```env
GLUCOSE_PROVIDER=librelinkup
```

This allows frontend/backend work without repeatedly hitting Abbott.

---

# Configuration

Provide:

```text
.env.example
```

with approximately:

```env
APP_ENV=development

HOST=127.0.0.1
PORT=8765

GLUCOSE_PROVIDER=mock

LIBRELINK_EMAIL=
LIBRELINK_PASSWORD=
LIBRELINK_REGION=AUTO
LIBRELINK_BASE_URI=

SQLITE_PATH=data/glucose.sqlite

ABBOTT_POLL_SECONDS=60
BROWSER_POLL_SECONDS=5
```

Credentials must not be committed.

---

# Security

Critical requirements:

* LibreLinkUp email/password must never reach browser JavaScript.
* LibreLinkUp session tokens must never reach browser JavaScript.
* Never expose Abbott auth headers through the local API.
* Never log passwords.
* Never log complete authentication tokens.
* Never log cookies containing credentials.
* Bind to `127.0.0.1` by default.
* LAN exposure must require an explicit configuration change.
* `.env` must be gitignored.
* session cache files must be gitignored.

---

# Error Logging

Log useful context such as:

```text
LibreLinkUp authentication failed: HTTP 401
LibreLinkUp request rate-limited: HTTP 429
LibreLinkUp connection lookup failed
LibreLinkUp response did not contain a glucose measurement
Database insert failed
```

Never log:

```text
password
complete token
complete Authorization header
sensitive cookies
```

---

# Repository Structure

Prefer approximately:

```text
bin/
    poll-glucose.php

public/
    index.php
    index.html
    app.js
    app.css
    manifest.webmanifest
    service-worker.js
    icons/

src/
    Contract/
        GlucoseProvider.php
        GlucoseRepository.php

    DTO/
        GlucoseReadingDTO.php
        LibreLinkUpSessionDTO.php

    LibreLink/
        LibreLinkUpProvider.php
        LibreLinkUpAuth.php
        LibreLinkUpEndpoints.php

    Mock/
        MockGlucoseProvider.php

    Database/
        SQLiteConnection.php
        SQLiteGlucoseRepository.php

    API/
        GlucoseController.php
        HealthController.php

    Support/
        Config.php
        Logger.php

data/
    .gitkeep

tests/
    Unit/
    Integration/

.env.example
.gitignore
composer.json
phpunit.xml
README.md
```

Adjust this where there is a compelling reason.

Do not create abstractions merely for abstraction's sake.

---

# Composer Configuration

Use PSR-4 autoloading.

Something conceptually like:

```json
{
  "require": {
    "php": "^8.4",
    "ext-json": "*",
    "ext-pdo": "*",
    "ext-pdo_sqlite": "*",
    "phpexperts/simple-dto": "^3.9",
    "phpexperts/rest-speaker": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

Determine the correct current compatible release constraints before finalizing `composer.json`.

Do not blindly copy the versions above if newer compatible releases exist.

---

# systemd

Provide a user-level systemd service for:

```text
bin/poll-glucose.php
```

Example usage:

```bash
systemctl --user daemon-reload
systemctl --user enable --now libre-glucose.service
```

No root service should be required.

The service should:

* restart after unexpected failure
* use the project's working directory
* invoke the project's configured PHP binary
* preserve logs in the user journal

Then:

```bash
journalctl --user -u libre-glucose -f
```

should show poller logs.

---

# Development Server

Development should be simple.

Something like:

```bash
composer install
cp .env.example .env

php bin/init-db.php
php bin/poll-glucose.php
```

and separately:

```bash
php -S 127.0.0.1:8765 -t public
```

Then:

```text
http://127.0.0.1:8765
```

should display the dashboard.

If a cleaner Composer script helps:

```bash
composer serve
composer poll
composer test
```

add them.

---

# Tests

Use PHPUnit.

At minimum test:

## SimpleDTO

Test that:

```text
GlucoseReadingDTO
LibreLinkUpSessionDTO
```

correctly:

* construct from valid input
* reject invalid types
* serialize correctly
* preserve immutable behavior
* convert timestamps correctly

## LibreLinkUp normalization

Use captured/sanitized fixtures from LibreLinkUp.

Verify that:

```text
Abbott JSON
     ↓
GlucoseReadingDTO
```

is correct.

Do NOT make unit tests depend on live Abbott servers.

## Repository

Test:

* insert
* duplicate timestamp handling
* latest reading
* history range
* DTO reconstruction

Use a temporary SQLite database.

## Authentication

Mock RESTSpeaker/HTTP behavior appropriately.

Test:

* valid login
* session expiration
* regional redirection/discovery
* authentication retry
* 401
* 429
* malformed response

---

# Investigating the LibreLinkUp Protocol

The LibreLinkUp interface is unofficial/private and can change.

Before writing the adapter:

1. Inspect current maintained open-source LibreLinkUp clients.
2. Determine the current login endpoint.
3. Determine required login body fields.
4. Determine required request headers.
5. Determine regional server discovery.
6. Determine token/session semantics.
7. Determine connection lookup.
8. Determine current glucose endpoint/structure.
9. Determine history/graph endpoint/structure.
10. Determine trend mappings.
11. Determine whether client-version headers are currently enforced.

Use existing implementations as protocol references.

Do NOT copy another project's architecture wholesale.

We specifically want our architecture to use:

```text
RESTSpeaker
SimpleDTO
PDO SQLite
```

---

# Important Architectural Boundary

This is the most important design requirement:

```text
              ABBOTT-SPECIFIC WORLD
                      |
                      v
             LibreLinkUpProvider
              RESTSpeaker
                      |
                      | normalization
                      v
              GlucoseReadingDTO
                      |
    ---------------------------------------
    |                  |                  |
    v                  v                  v
 SQLite             local API           tests
```

Nothing below `GlucoseReadingDTO` should care whether Abbott calls a field:

```text
Value
glucoseMeasurement
FactoryTimestamp
TrendArrow
MeasurementColor
```

or anything else.

Only:

```text
LibreLinkUpProvider
```

should know that.

Likewise, the rest of the application should not know:

* Abbott URL paths
* Abbott authentication headers
* LibreView region naming
* account IDs
* connection IDs
* client-version strings

Those belong in:

```text
src/LibreLink/
```

---

# RESTSpeaker Architectural Boundary

Likewise:

```text
RESTSpeaker
```

must not leak outside the LibreLink adapter.

Do not pass a RESTSpeaker instance into:

```text
GlucoseRepository
controllers
frontend-related classes
DTOs
```

RESTSpeaker exists specifically as our Abbott transport layer.

---

# SimpleDTO Architectural Boundary

SimpleDTO should form the clean data-contract boundary.

Prefer:

```php
public function latest(): ?GlucoseReadingDTO
```

over:

```php
public function latest(): ?array
```

Prefer:

```php
public function authenticate(): LibreLinkUpSessionDTO
```

over:

```php
public function authenticate(): array
```

Prefer:

```php
public function save(GlucoseReadingDTO $reading): void
```

over:

```php
public function save(array $reading): void
```

This is exactly the type of application where immutable DTOs make the unofficial upstream API much easier to quarantine.

---

# Avoid Needless Enterprise Architecture

Although the boundaries above matter, this is still a small personal application.

Do not introduce:

```text
CommandBus
EventBus
CQRS
DDD aggregate roots
message queues
dependency-injection containers
ORM
repository factories
service locators
GraphQL
microservices
```

unless an actual implementation requirement appears that cannot reasonably be handled otherwise.

The desired style is:

```text
small
explicit
strongly structured
easy to debug
easy to modify
```

---

# Medical Constraint

This is a personal glucose DISPLAY.

It is NOT an insulin dosing engine.

Do not:

* recommend insulin doses
* recommend medication changes
* extrapolate future glucose
* fabricate missing readings
* interpolate fake measurements
* silently smooth measurements
* alter Abbott values

Display the received glucose values and timestamps accurately.

Chart lines may visually connect real measurements, but no synthetic measurements should be created.

---

# README

Document:

* Arch Linux requirements
* PHP extensions needed
* Composer installation
* configuration
* LibreLinkUp credentials
* Egyptian/LibreLink EG regional discovery
* mock provider
* SQLite database location
* starting the poller
* starting the web server
* systemd user installation
* Chrome PWA installation
* troubleshooting stale readings
* troubleshooting LibreLinkUp authentication
* how sessions are stored
* how to inspect logs
* how to clear/re-authenticate a broken session
* tests
* architecture

Also explain specifically how this project uses:

```text
phpexperts/rest-speaker
phpexperts/simple-dto
```

so future maintainers do not replace them unnecessarily.

---

# Implementation Process

Work autonomously.

Do not merely generate a scaffold.

1. Inspect current LibreLinkUp implementations and protocol behavior.
2. Verify current `phpexperts/rest-speaker` API and use the installed version correctly.
3. Verify current `phpexperts/simple-dto` API and use the installed version correctly.
4. Create the DTO contracts.
5. Build LibreLinkUp authentication using RESTSpeaker + NoAuth.
6. Implement the custom RESTSpeaker LibreLinkUp auth strategy.
7. Implement regional discovery.
8. Retrieve a connection/current reading.
9. Normalize it into `GlucoseReadingDTO`.
10. Implement SQLite persistence.
11. Implement the poller.
12. Implement local REST endpoints.
13. Build the PWA.
14. Add stale-data handling.
15. Add the history graph.
16. Add mock mode.
17. Add PHPUnit tests.
18. Add systemd support.
19. Run Composer validation.
20. Run tests.
21. Run static/lint checks if configured.
22. Fix failures before considering the implementation complete.

---

# Completion Criteria

The project is complete when I can:

```bash
git clone ...
cd libre-dashboard

composer install
cp .env.example .env
```

configure my LibreLinkUp credentials, then run:

```bash
php bin/poll-glucose.php
```

and:

```bash
php -S 127.0.0.1:8765 -t public
```

open:

```text
http://127.0.0.1:8765
```

and see something equivalent to:

```text
              174
             mg/dL

               ↘

        updated 38 sec ago

      [ 3h | 6h | 12h | 24h ]

            glucose graph
```

with the data ultimately flowing:

```text
Libre 2
  ↓
LibreLink EG
  ↓
LibreLinkUp
  ↓
RESTSpeaker
  ↓
LibreLinkUpProvider
  ↓
GlucoseReadingDTO
  ↓
SQLite
  ↓
PHP API
  ↓
Vanilla JavaScript PWA
```

At completion, report:

* architecture
* files created
* Composer dependencies
* exact RESTSpeaker integration used
* exact SimpleDTO classes created
* LibreLinkUp endpoints/protocol behavior discovered
* Egyptian region behavior discovered
* authentication/session behavior
* commands to install
* commands to run
* commands to test
* systemd setup
* any unresolved LibreLinkUp limitations

