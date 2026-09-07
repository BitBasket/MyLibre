# MyLibre Publication and Product Plan

## Purpose of this document

This document captures the product vision, lived problem, architectural decisions, commercial requirements, privacy model, unresolved questions, and rejected approaches discussed for MyLibre. It is intended to give another engineer or LLM enough context to continue the work without asking the founder to repeat the conversation or reintroducing approaches that have already been rejected.

The central constraint is explicit:

> MyLibre should use ephemeral/serverless workers and temporary encrypted object storage. It should not require a $4-per-user Droplet, a persistent application server, PHP, SQLite, or another conventional database.

## Founder and originating problem

The founder lives during different parts of the year in Dubai, New Cairo, Bogota, and Houston and has endocrinologists in all four cities. Abbott's regional geofencing prevents most of those doctors from viewing the founder's FreeStyle Libre graph. Only the endocrinologist in New Cairo can currently view it without difficulty.

This is therefore not merely a custom dashboard. The motivating problem is continuity of care and patient access to their own health data across borders, regions, clinicians, operating systems, and devices.

The founder has minute-level CGM information throughout each day and wants to use it for:

- Portable records shared with doctors in different countries.
- User-controlled analysis unavailable in LibreLink.
- Deliberate sharing with ChatGPT or other analysis tools.
- A small always-on-top GNOME desktop display.
- A continuously glanceable Xiaomi Smart Band 9 display or notification integration.
- Future custom monitors and interfaces.
- Long-term freedom from a single CGM vendor.

The official LibreLink application remains the Bluetooth receiver. MyLibre currently obtains readings through the unofficial LibreLinkUp HTTP API; it does not communicate with the sensor over BLE.

## Product mission

MyLibre is intended to provide:

1. **Self-sovereignty of data.** Users control their histories, encryption keys, exports, and optional backup storage. The service must not trap their data or require a delayed export request.
2. **Interface freedom.** CGM data should not be geo-locked or platform-locked. A user should be able to build or use browser, desktop, wearable, clinician, and analysis interfaces.
3. **Extensibility.** Minute-level readings should be available through a documented, vendor-neutral format for custom monitoring and analysis.
4. **Vendor independence.** Libre is the first adapter, with eventual support for all practical CGM sources.
5. **Humanitarian usefulness.** The system should restore patient-controlled continuity of care for travelers, migrants, expatriates, international families, and anyone affected by regional or platform restrictions.

The product is a display, portability, and analysis layer. It should not recommend insulin, fabricate missing readings, or represent itself as a replacement for the official CGM application or medical care.

## Current repository

The current prototype is a PHP 8.4 local-first PWA with:

- A LibreLinkUp provider built around `phpexperts/rest-speaker`.
- A mock provider.
- Immutable DTOs.
- A PHP polling process.
- SQLite history and deduplication.
- Static `public/current.json`, daily history JSON, and status JSON snapshots.
- A vanilla JavaScript PWA.
- Unit and integration tests.

That implementation proved the API integration and dashboard behavior. It is not the required publication architecture. The proposed product version can be rewritten without PHP and SQLite.

## Settled target architecture

### Overview

```text
Scheduled ephemeral worker, approximately once per minute
  -> authenticate to the user's CGM cloud account
  -> fetch current reading and available graph history
  -> normalize into a versioned, vendor-neutral payload
  -> encrypt immediately to the user's public key
  -> append an encrypted JSON batch to temporary object storage

User opens MyLibre web app
  -> authenticate the user's device
  -> list and download pending encrypted batches
  -> decrypt in the browser
  -> deduplicate and commit them to browser-local IndexedDB
  -> acknowledge only the batches committed successfully
  -> deletion worker removes acknowledged relay objects

Backstop
  -> any uncollected relay object expires after 30 days
```

No continuously running per-user VM is required. No Droplet is required. No relational database is required. The worker should be suitable for AWS Lambda or an equivalent function platform and should ideally be written in Rust.

### Why the cloud poller is required

A browser cannot call LibreLinkUp directly because of CORS. Browser background execution is also unsuitable for dependable minute-by-minute collection.

More importantly, LibreLinkUp exposes only limited historical data. The founder has observed that leaving the laptop offline for more than roughly eight hours can make older readings unrecoverable. The cloud worker must therefore continue polling while the browser and laptop are offline.

### Temporary relay, not permanent cloud history

The default cloud storage is a delivery buffer, not the user's enduring health record.

- It contains encrypted JSON batches only.
- It accumulates data while the user's browser is offline.
- It deletes a batch after the browser has downloaded, decrypted, and durably committed that batch locally.
- Merely opening the page must not trigger deletion before a verified local commit.
- Uncollected batches expire after 30 days.
- If the user is offline longer than the relay window, data older than the window may be lost unless optional backup is enabled.
- Payloads must not leak into function logs, error trackers, dead-letter queues, access logs, analytics, or support systems.

S3 object versioning is not a product feature and was never part of the founder's proposal. Do not turn it into a product discussion. Internally, the implementation must simply ensure that the stated deletion behavior is real across objects, logs, retries, replicas, and any provider-specific recovery copies.

### Object format and synchronization

Do not overwrite a single `graph.json` every minute if doing so would discard offline history. A safe design is an append-only series of immutable encrypted batches, for example:

```text
pending/<opaque-batch-id>.json.enc
```

Each plaintext payload should include a schema version and enough provenance to deduplicate safely on the client. Candidate canonical fields include:

- CGM provider and source account/device identifiers where needed.
- Sensor timestamp and received timestamp.
- Glucose value and unit.
- Trend value/arrow.
- Quality, stale, and gap indicators.
- Stable reading identity or deterministic deduplication material.
- Raw-adapter schema version and optional preserved source payload.

For the simplest stateless first implementation, the worker may encrypt the entire overlapping graph response on every poll and allow the browser to deduplicate it. A later optimization may emit only newly observed readings. Avoid adding server-side state merely to save a small amount of encrypted object storage unless measurements prove that it is necessary.

The browser should keep a local synchronization checkpoint. After an IndexedDB transaction succeeds, it sends a signed acknowledgement containing the imported batch identifiers. A narrow deletion API deletes only those acknowledged objects. The browser must not receive broad cloud credentials or unrestricted object-storage permissions.

### Minimal cloud components

A provider-neutral deployment requires equivalents of:

- A one-minute scheduler.
- A short-lived Rust polling function.
- Private object storage for encrypted relay batches.
- A narrowly scoped secret store for the CGM credential/session token.
- A small listing/download/acknowledgement API, possibly another Rust function.
- Identity and authorization tied to a user-controlled device key.
- A hard 30-day expiration policy.
- Payload-free operational health monitoring.

A database should not be introduced unless a specific requirement proves that object storage, signed device state, and the provider's secret store are insufficient.

## Browser-local durable storage

The default permanent history lives in IndexedDB or an equivalent browser-owned persistent store.

The product must communicate the consequence plainly:

> Your glucose history is stored on this device in this browser's site/app data. If you erase that data without a backup, it cannot be recovered after the temporary relay copies have been deleted.

Use the browser persistence API where supported, but do not promise that browser storage is infallible. Provide an immediate encrypted export and a clear warning before any in-app destructive reset.

## Optional paid backup

Permanent cloud backup is a separate, optional service. It must not be confused with the default ephemeral relay.

The intended model resembles WhatsApp backup to Google Drive:

- The user chooses to enable persistent backup.
- The browser encrypts the durable history before upload.
- The private decryption key remains under the user's control.
- The persistent bucket or storage allocation should be in the user's name/account where possible.
- The storage may be provisioned, billed, or managed through MyLibre on the user's behalf if a provider supports that commercial arrangement.
- Disabling the paid backup must not prevent access to the local history or immediate export.

Google Drive, OneDrive, Dropbox, and user-controlled S3-compatible storage are possible backup targets. Google Drive's application-data OAuth flow is attractive because many consumers already have an account and quota, but this does not solve ownership of the polling function itself.

## Encryption and the precise zero-knowledge claim

### Keys

- Generate the user's encryption keypair on the user's device.
- Send only the public encryption key to the polling environment.
- Keep the private key on the user's devices, encrypted locally with a user password or protected through suitable platform key storage.
- Provide an explicit user-controlled recovery-key option. Without recovery material, loss of the password/private key must honestly mean permanent loss.
- Pin or otherwise authenticate the public key so a compromised service cannot silently substitute a different key for future payloads.

Modern audited authenticated encryption suitable for Rust and browser WebCrypto should be preferred over exposing ordinary users to manual GPG key management.

### Important limitation

LibreLinkUp does not encrypt its response to the user's MyLibre public key. Therefore, the polling worker necessarily sees each response briefly in readable memory before encrypting it. It also needs access to the user's LibreLinkUp credential or reusable session token.

The accurate claim for a cloud-poller deployment is therefore along the lines of:

> We do not retain readable glucose history. Relay and backup objects are encrypted to keys controlled by the user.

Do not claim that the polling environment never processes plaintext.

If the worker and object storage run in a cloud account truly owned and controlled by the user, and MyLibre has no standing administrative access, telemetry, logs, or credentials, the company may be only a software publisher rather than a processor of the glucose readings. That legal distinction depends on actual ownership, access, and determination of processing means, not on marketing language or who reimburses the bill.

If resources run in MyLibre's cloud account or MyLibre retains administrative access, the company should assume it processes health data even if humans never inspect it.

### Web-code trust limitation

Browser-side decryption code served dynamically from MyLibre's Hetzner server could theoretically be modified by a compromised server to capture a password or key. Mitigations include:

- No third-party scripts or analytics on authenticated/decrypting pages.
- Strict Content Security Policy.
- Fully published source.
- Signed, versioned releases and reproducible builds.
- A locally installed PWA/static bundle with controlled updates.
- An optional signed Tauri/native client for the strongest trust boundary.

## Interfaces and extensibility

Planned or desired consumers of the local canonical history include:

- The existing full browser dashboard.
- A small GNOME always-on-top window showing current value, trend, age, and unmistakable stale/offline status.
- An Android companion that can decrypt the latest synchronized value and send a compact notification to Xiaomi Smart Band 9.
- Experimental Smart Band watchface/application integration where the platform permits it.
- Immediate JSON and CSV export.
- A concise clinician-oriented report.
- A deliberate "Export for AI analysis" bundle containing only the period and context selected by the user.
- Future public/local APIs for custom monitors and analysis.

Wearable display must be opt-in because sending a reading through Android, Bluetooth, Mi Fitness, and a wearable expands the privacy boundary beyond the browser.

The provider layer should be adapter-based from the beginning:

```text
Libre adapter ----\
Dexcom adapter ----> canonical CGM events -> encrypted relay -> user interfaces
Other CGM adapter -/
```

Do not erase provider-specific provenance during normalization.

## Licensing and trust

The founder has explicitly chosen a **100% source-available** model. Do not relabel it as open source or lecture the founder about terminology they did not use.

The intended licensing uses the founder's Small Business License and Creative Commons No-Derivatives terms as selected by the founder. Repository reference:

```text
https://github.com/AutonomoAI/SmallBusinessLicense
```

Source availability is part of the trust proposition: users and auditors should be able to inspect collection, encryption, deletion, export, and telemetry behavior. Commercial revenue comes from easy provisioning, licensing, updates, maintenance, monitoring, interfaces, backup integration, and support—not from selling, profiling, or trapping patient data.

## Business and payment requirements

### Desired experience

The product should feel like WHMCS or a commercial one-click application:

- The user pays once through a simple consumer-friendly flow.
- MyLibre receives recurring revenue.
- The ephemeral function, secret storage, and object-storage allocation are technically the user's resources where possible.
- MyLibre provisions and maintains them on the user's behalf.
- Resources should be geographically local to the user's residence where practical, with EU hosting as the fallback preference.
- The user should not have to understand IAM, Lambda, S3, CloudFormation, cloud regions, or API keys.
- There must be a real exit: export, revoke management access, migrate, and delete without MyLibre holding the data hostage.

The remaining central business/technology question is:

> Which cloud or reseller platform permits MyLibre to sell and provision tiny scheduled functions plus temporary object storage into a resource boundary genuinely owned or controlled by the user, while presenting one simple payment path and avoiding a painful cloud-provider signup?

### Approaches investigated

#### AWS Marketplace and customer-owned AWS accounts

AWS Marketplace can charge customers for commercial software and pay the seller. SaaS Quick Launch and CloudFormation can deploy resources into the buyer's account. Technically this could create Lambda, EventBridge, S3, secret-storage, IAM, and API resources owned by the buyer.

This path is currently rejected as the default because AWS account creation, login, billing, IAM, and deployment are extremely difficult and intimidating even for experienced engineers. It is unacceptable as a general consumer onboarding experience.

It may remain an advanced bring-your-own-cloud option.

#### DigitalOcean commercial Droplets

DigitalOcean has a much simpler interface and supports commercial Marketplace licenses/SaaS Add-Ons attached to 1-Click Droplet deployments. This offers one cloud billing relationship and seller revenue.

However, a dedicated DigitalOcean Droplet currently costs approximately $4 per user per month. **The target design explicitly seeks to avoid that fixed per-user Droplet cost. Do not recommend returning to a $4 Droplet as the primary architecture.**

DigitalOcean Functions would be closer to the desired architecture, but its scheduled-trigger capabilities were documented as private preview with restrictive trigger limits during this investigation. Re-evaluate only if the product becomes generally available and supports commercial Marketplace provisioning and billing.

#### PikaPods

PikaPods offers simple isolated application hosting at low prices and has a developer revenue-sharing model. It could be a distribution or experimental hosting partner. Its pods are hosted by PikaPods rather than being a cloud account legally owned by the user, and its geographic coverage is limited. It therefore offers portability and isolation rather than literal infrastructure sovereignty.

#### Vultr and Akamai/Linode

Both have application marketplaces and simpler infrastructure interfaces than AWS. Current research did not establish a clear, supported combination of:

- Commercial seller revenue.
- Serverless scheduled workers.
- Object storage.
- One consumer-friendly bill.
- Resources technically owned by the user.

These should be investigated directly with their marketplace/partner teams rather than assumed to satisfy the model.

#### Cloudflare Workers and R2

Workers, cron triggers, and R2 are technically well suited to this workload, but the consumer still needs a Cloudflare account/payment method for customer-owned resources, and no confirmed Marketplace flow was found that combines MyLibre licensing with customer-owned Workers/R2 in one simple purchase. A reseller, agency, or platform partnership might change this and warrants direct investigation.

#### MyLibre-owned shared infrastructure

A shared serverless deployment in MyLibre's account is likely the cheapest and easiest consumer experience. Tenant payloads can be immediately encrypted and retained only as an ephemeral relay. However, the infrastructure would not technically belong to each user, and the cloud worker would still process plaintext responses momentarily.

This may be an honest managed-service tier if described accurately, but it does not fully achieve the strongest infrastructure-sovereignty objective.

### Fundamental tension to solve

Ordinary cloud contracts make it difficult to obtain all three simultaneously:

1. The resources legally/technically belong to the user.
2. The user never creates or authorizes a provider account.
3. MyLibre invisibly provisions, maintains, and bills everything through one consumer checkout.

Potential ways forward include a true reseller/white-label agreement, provider Marketplace billing, transferable subaccounts, billing-transfer arrangements that do not grant administrative control, or redefining sovereignty primarily through encryption and immediate portability rather than literal ownership of compute resources.

Do not assume this tension is impossible without first speaking directly to provider partner/marketplace teams. Public documentation may not describe reseller arrangements available through commercial negotiation.

## Cost characteristics

The workload is exceptionally small:

- One outbound CGM request approximately every minute per active user.
- A few milliseconds to seconds of compute per invocation depending on network latency and authentication.
- A small Rust memory footprint.
- Roughly 43,200 scheduled polls per 30-day month per continuously active user.
- Encrypted temporary storage that can remain small if payloads contain only readings or compact batches rather than repeatedly duplicated graphs.
- Low bandwidth relative to ordinary media or web applications.

The desired billing model should therefore be usage-based serverless compute and object storage, not a fixed VM minimum per customer. Operational support, payment fees, compliance, incident response, and development will cost more than the raw compute.

## GDPR posture

The architecture is strongly aligned with GDPR principles, but architecture alone does not establish compliance.

Positive characteristics include:

- Data minimization.
- Purpose limitation.
- Short default retention.
- Encryption.
- Privacy by design and by default.
- Immediate machine-readable portability.
- No advertising, profiling, brokerage, or secondary use.
- User-controlled optional backup.
- Geographic processing choices.

Remaining organizational/legal work includes:

- Determine controller/processor roles for every deployment model.
- Establish an Article 6 lawful basis and an Article 9 condition for health data, likely involving explicit informed consent.
- Separate consent/authorization for doctor, AI, and wearable sharing.
- Complete a DPIA before public operation; large-scale systematic health-data processing is likely to require one.
- Assess whether a DPO becomes required at scale.
- Execute appropriate processor agreements with infrastructure, payment, email, and support providers.
- Document international transfers and safeguards.
- Maintain processing records and a breach-response plan.
- Implement user access, deletion, correction, restriction, and export workflows.
- Address children and parental authorization because many CGM users are minors.
- Verify deletion across objects, logs, queues, retries, dead-letter paths, backups, and support systems.

Hosting the static web frontend on the founder's Hetzner server in Frankfurt is favorable for EU delivery but does not by itself determine GDPR compliance. The complete path and actual control of each component matter.

## Safety and product integrity

- Preserve readings exactly; do not invent missing points.
- Make stale, disconnected, delayed, and gap states unmistakable.
- Preserve source timestamps and time zones, especially for international travel.
- Clearly distinguish measured data, derived summaries, and user annotations.
- Do not give insulin-dosing advice without a separately validated and appropriately regulated product path.
- Never make privacy promises stronger than the implemented trust boundary.
- Treat credential exposure and silent data gaps as critical incidents.

## Suggested positioning

Potential concise positioning:

> Your CGM data, freed from regional and platform locks. MyLibre continuously collects it, encrypts it to your key, delivers it to your devices, and gets out of the way.

For a deployment in genuinely user-controlled cloud resources:

> Your worker, your storage, your keys, your data. We make it effortless to install and maintain.

For a MyLibre-owned managed relay, use narrower and accurate language:

> Your history lives on your device. We retain only encrypted delivery packets, delete them after successful synchronization, and expire anything uncollected after 30 days.

## Immediate next decisions

Another LLM or product architect should focus on these questions in order:

1. Identify providers or commercial reseller programs that support customer-owned scheduled functions and object storage with one simple MyLibre-mediated payment flow.
2. Compare the legal and technical meaning of ownership under provider subaccounts, organizations, projects, namespaces, and billing-transfer arrangements.
3. Determine whether literal infrastructure ownership is essential for the default tier, or whether cryptographic control plus instant portability is sufficient, with bring-your-own-cloud offered separately.
4. Produce a provider-neutral Rust serverless design and cost model at 100, 10,000, and 1,000,000 active users.
5. Design browser key generation, recovery, device enrollment, signed acknowledgements, and multi-device synchronization.
6. Specify the versioned canonical CGM event/envelope format for future provider adapters.
7. Define exact retention behavior and a testable deletion guarantee.
8. Design the optional user-owned backup flow, initially considering Google Drive and S3-compatible targets.
9. Prepare a DPIA/data-flow inventory before selecting production subprocessors.
10. Plan the migration from the current PHP/SQLite prototype to Rust workers and browser-owned history without discarding the tested LibreLinkUp behavior.

## Instructions for anyone continuing this plan

- Maintain the distinction between the **ephemeral relay** and **optional permanent backup**.
- Do not recommend a $4-per-user Droplet as the target solution.
- Do not reintroduce SQLite or PHP without a demonstrated requirement.
- Do not assume the browser must remain open; continuous collection is the worker's purpose.
- Do not claim that cloud-worker processing is absolutely zero-knowledge; the worker receives plaintext from the CGM API before encryption.
- Do not confuse source-available with open source. The founder explicitly said source-available.
- Do not characterize encrypted temporary storage as permanent health-record hosting.
- Do not treat server geography alone as GDPR compliance or noncompliance.
- Do not propose delayed, gated, or paid access to the user's own exports.
- Preserve the founder's central objective: patient autonomy through simple, affordable, private, portable access to continuous CGM data.
