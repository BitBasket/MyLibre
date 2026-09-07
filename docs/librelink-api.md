# LibreLinkUp and LibreView API history

Research note, 2026-09-07.

## Scope and distinction

LibreLinkUp is the cloud service used for sharing current glucose data. It is hosted under `libreview.io`, but it is a different interface from the LibreView web application. The poller currently uses LibreLinkUp's graph history. LibreView's website/export workflow is a separate possible source for older data.

This note documents the publicly described routes investigated so far. It does not prove that these are the only endpoints Abbott operates.

## Known routes

| Route | Purpose and useful history | Status for this project |
| --- | --- | --- |
| `POST /llu/auth/login` | Authenticate a LibreLinkUp account. | Used to obtain a session/token. |
| `GET /llu/connections` | List connected patients and their current measurement metadata. | Useful for discovering the patient ID. |
| `GET /llu/connections/{patientId}/graph` | Current measurement plus a rolling graph window, commonly about 12 hours. | This is the glucose-history route used by the poller. |
| `GET /llu/connections/{patientId}/logbook` | Individual scans and glucose alarm/events, commonly about 14 days. | Potentially useful for older, sparse observations; it is not a continuous curve. |
| LibreView `POST /auth/login` | Website account authentication in public client implementations. | Separate from the LibreLinkUp login; current authentication requirements need verification. |
| LibreView `GET /glucoseHistory?numPeriods=5&period=7` | Historical period summaries/graph structures. | A separate web route; period semantics and whether raw individual blocks can be obtained are not verified here. |
| LibreView `/export` | Starts a glucose export workflow that can produce historical CSV data. | Strongest candidate for recovering older gaps, but the request/response below comes from archived public code and is not live-verified. |

The rolling graph response is the most useful source after a sleep gap while its points remain available. Abbott describes the approximately 12-hour graph and two-week event log in its [reconnection FAQ](https://www.support.freestyle.abbott/hc/en-us/articles/45388516151825-When-my-phone-or-my-Connection-s-phone-isn-t-connected-to-the-Internet-for-a-while-like-on-a-flight-what-will-my-app-and-notifications-look-like-once-we-re-back-on-the-network). The exact window and sampling returned for an account should be measured rather than inferred.

## Illustrative responses

The following examples are abbreviated and use invented values. They show the approximate shape, not a guaranteed current schema or sampling frequency.

### Graph

```json
{
  "status": 0,
  "data": {
    "connection": {
      "patientId": "example-patient-id",
      "glucoseMeasurement": {
        "FactoryTimestamp": "9/7/2026 9:00:00 AM",
        "ValueInMgPerDl": 112,
        "TrendArrow": 3
      }
    },
    "graphData": [
      {
        "FactoryTimestamp": "9/6/2026 9:05:00 PM",
        "ValueInMgPerDl": 105
      },
      {
        "FactoryTimestamp": "9/7/2026 3:00:00 AM",
        "ValueInMgPerDl": 98
      },
      {
        "FactoryTimestamp": "9/7/2026 8:45:00 AM",
        "ValueInMgPerDl": 110
      }
    ]
  }
}
```

`connection.glucoseMeasurement` is the latest reading; `graphData` contains the historical points returned by the service.

### Logbook

```json
{
  "status": 0,
  "data": [
    {
      "FactoryTimestamp": "8/26/2026 2:15:00 PM",
      "ValueInMgPerDl": 185
    },
    {
      "FactoryTimestamp": "9/2/2026 4:30:00 AM",
      "ValueInMgPerDl": 68
    }
  ]
}
```

Logbook entries are events or scans. They cannot reconstruct the missing readings between entries.

## LibreView export candidate

Archived public LibreView export code describes an approximate request like this:

```http
POST https://api-{region}.libreview.io/export
Authorization: Bearer <token>
Content-Type: application/json
```

```json
{
  "captchaResponse": "<completed CAPTCHA token>",
  "type": "glucose"
}
```

An abbreviated response starts an export workflow rather than returning readings directly:

```json
{
  "status": 0,
  "data": {
    "url": "https://hub-fr.libreview.io/channel/example"
  },
  "ticket": {
    "token": "<token>",
    "expires": 1234567890
  }
}
```

This contract appears in comments in a repository archived in January 2024; its executable code fetches `glucoseHistory`, not the export. No export date parameters are documented there. It has not been tested against a current authenticated account. The supported web workflow includes human verification, as described in [Glooko's LibreView CSV download instructions](https://support.glooko.com/hc/en-us/articles/4531230605843-How-to-import-your-blood-glucose-data-from-an-Abbott-FreeStyle-Libre-1-FreeStyle-Libre-2-or-FreeStyle-Libre-3). The eventual artifact is a CSV, which could be imported into this project.

Abbott documentation says data older than 90 days remains accessible in LibreView and documents downloading raw glucose data ([history FAQ](https://pro.freestyle.abbott/uk-en/help-support/faq/question-answer.html?q=FreeStyle+LibreLinkquestion-103), [download instructions](https://www.support.freestyle.abbott/hc/en-us/articles/14806679954199-Can-I-download-my-data-with-the-FreeStyle-Libre-3-app)). That does not guarantee that every account's export retains every point, or that resolution and retention match the graph data.

## Project implications

The [provider's `getHistory` path](../src/LibreLink/LibreLinkUpProvider.php) already reads all returned `graphData` points and the latest measurement, and the [poller launcher](../bin/poll-glucose.php) enables history persistence (`persistHistory: true`). The graph therefore can backfill a laptop sleep gap only for points still inside the service's rolling window and only if the phone collected and uploaded them. An eight-hour outage may be recoverable; after a longer outage, the oldest portion may have rolled off. These are conditional scenarios, not guarantees.

The README describes a roughly 15-minute history window, which conflicts with the commonly documented approximately 12-hour graph window. The response's oldest/newest timestamps, point count, and spacing should be logged to resolve that discrepancy. The poller's cadence-based gap check can report `BACKFILL INCOMPLETE` when returned historical points are less frequent than its expected polling cadence, even when all available points were saved.

A practical future feature is a user-assisted LibreView CSV importer for historical repair. No importer or automatic export integration is implemented by this note.

Other integrations do not currently establish a better automatic source: Glooko's LibreView connection is described as syncing only after linking (not an arbitrary pre-link backlog), and Terra's November 2025 changelog says its Libre integration was disabled, with no confirmed restoration.

## Sources and remaining verification

- [PyLibreLinkUp client](https://raw.githubusercontent.com/robberwick/pylibrelinkup/main/src/pylibrelinkup/pylibrelinkup.py) and its [connection models](https://raw.githubusercontent.com/robberwick/pylibrelinkup/main/src/pylibrelinkup/models/connection.py) document the LibreLinkUp response shapes.
- [Archived LibreView export code](https://github.com/YtoTech/libreview-data-export/blob/master/export-data.py) documents the export workflow described above.
- [LibreView website client](https://github.com/InventivetalentDev/LibreViewApi/blob/master/index.js) implements website authentication and `glucoseHistory`; a [separate C# sample](https://dotnetfiddle.net/OWr8wO) models historical periods and export progress but leaves export unimplemented. Its `data.blocks` elements are untyped, so these sources do not establish a raw-reading recovery contract.
- [Glooko LibreView connection](https://support.glooko.com/hc/en-us/articles/4416896049555-How-do-I-connect-my-FreeStyle-Libre-systems-account-to-Glooko-US-only) and [Terra changelog](https://docs.tryterra.co/changelog/2025/november-update-2025) describe the integration limitations.

To finish verification, use an authenticated test account to record the current graph window, call the logbook route, inspect the current LibreView history/export behavior, and compare CSV timestamps and resolution with the poller's stored readings. In particular, the `glucoseHistory` period semantics, raw block availability, export CAPTCHA flow, and actual retention must be verified against the live service before implementation.
