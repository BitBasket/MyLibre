# Privacy

Assumptions: a self-hosted droplet, poller run as a CLI (stderr is the terminal, not a journal unless you put it under systemd), and `PGP_USER_PUBLIC_KEY_PATH` set so published files go to the user’s public key. Abbott login secrets in `.env` are out of scope here.

The **user private key is not on the server**. The extra key on the droplet is a second, server-owned pair, used only so the poller can decrypt its own Abbott token file.

## Terms

| Term | Meaning here |
| --- | --- |
| Health data | Glucose mg/dL, trend, sensor timestamps |
| Identifier | Account UUID, patient id, regional host, anything that says *who* |
| Secret | Bearer token, server PGP private key + its passphrase |
| Identifiable health data | Health data in a context that is already one person’s (this VM, this token file, this user key). A log line `174` is not an identifier. On a single-tenant droplet it is still that user’s glucose. |

HIPAA “PHI” is a covered-entity term. A droplet you run for yourself is usually not that. This document is about **what a disk snapshot, root SSH, or process dump can see**, not about a legal label.

## On disk

| Artifact | Cleartext? | What’s in it | Who can read it |
| --- | --- | --- | --- |
| `public/current.json.asc`, `public/b/<bucket>.json.asc` | No. Encrypted to **user public key** (if `data/keys/user-public.asc` exists). | Latest reading; history batches (mg/dL, trend, timestamp) | Only someone with the **user private key**. Droplet root cannot. |
| `public/status.json.asc` | No. Same recipient. | `bucketSeconds`, earliest/latest reading times, provider name. No mg/dL. | Same as above. |
| `data/libre-session.json.asc` | No. Encrypted to the **server** keypair from `init-pgp.php`. | Abbott **Bearer token**, expiry, regional `baseUri`, account UUID, patient id | The droplet can, because it holds that private key and `PGP_PASSPHRASE`. Not the user’s GPG key. |
| `data/keys/private.asc` + `PGP_PASSPHRASE` | Private key is passphrase-wrapped; passphrase is on the host. | Server key, **not** the user’s | Host admin / volume snapshot. Exists so the token file can be opened again. |
| `data/keys/user-public.asc` | Yes (it’s a public key). | Recipient for glucose files | Anyone with disk. Cannot decrypt. |
| `data/poll-state.json` | Yes. `0600`. | ~2000 sensor timestamps + first-seen time. **No glucose values.** | Host admin. Not in `.gitignore`. |
| CLI stderr | Not a file unless you redirect it. | `Stored glucose reading N mg/dL`; sensor-loss and gap **timestamps**; Abbott **region** on login redirect | Whoever watches that terminal. Not written to disk by this code. `systemd/libre-glucose.service` *would* capture stderr in the journal if you enable it. |

If `data/keys/user-public.asc` is **missing**, glucose files are encrypted to the **server** key instead. Then the same host that has `private.asc` can decrypt all history. That is the fallback in `App::recipientCrypto()`, not user-custody mode.

## In the running process (unavoidable for a poller)

| In RAM | Why |
| --- | --- |
| Current graph: mg/dL, trend, timestamps | Must parse LibreLinkUp, then encrypt |
| `$pending` readings until the 5-minute bucket closes | Open bucket is built in memory |
| Bearer token, account UUID, patient id | Needed for the next GET |
| Server passphrase | Needed to read/write the token file |

Root on the droplet, `gdb`, or a core dump can see this. DigitalOcean **disk** snapshots do not include RAM. A hypervisor-level adversary is a different threat (confidential VMs), not this GPG design.

PHP does not reliably wipe strings after use.

## How secure this is

| Goal | Status |
| --- | --- |
| Glucose **files** unreadable without the user’s private key | **Yes**, when user public key is configured. That is the custody story. |
| User private key never on the droplet | **Yes.** |
| Host cannot read glucose **at rest** | **Yes** for `public/*`, same condition. |
| Host cannot see glucose **at all** | **No.** The worker sees each reading in RAM. A poller that talks to Abbott cannot avoid that. |
| Host cannot see **who** the Abbott account is | **No.** Token file decrypts on this machine: UUID, patient id, region. |
| Token stolen from disk without the server key | **No** — the file is GPG. With droplet root, **yes** — root has the server private key. |

## Bottom line

For a self-hosted droplet whose **published history** must be user-custody, encrypt-to-user-public-key is the right design and this tree does it — **if** that public key is configured.

What remains on the host:

1. **Working set** — plaintext glucose in RAM while polling (cannot remove without an enclave).
2. **Abbott token file** — still GPG’d to a **server** private key, because the poller must read it back. That is why `init-pgp.php` still matters.
3. **Timestamps** in `poll-state.json`.

(1) is the technical ceiling. (2) and (3) are extra copies you could still drop or shrink. They are not glucose history, and they are not the user’s GPG private key.
