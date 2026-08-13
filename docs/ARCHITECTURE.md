# Architecture

## Purpose

Secure File Gateway is an API-first service for accepting untrusted files, isolating them, validating them, scanning them asynchronously and exposing only approved files through controlled, short-lived access.

The project is intentionally a small security-focused system rather than a generic cloud-drive clone.

## V1 architecture

```text
Client
  |
  | HTTPS + Bearer token
  v
Laravel API
  |-- authentication / ownership authorization
  |-- upload policy + MIME/hash verification
  |-- lifecycle metadata
  |-- signed capability issuance
  |
  +------> PostgreSQL
  |
  +------> Redis queue / rate-limit coordination
  |
  +------> Private quarantine storage
                    |
                    | ScanStoredFile job
                    v
               Scan worker
                    |
                    | INSTREAM over internal network
                    v
                  ClamAV
                    |
       +------------+------------+
       |                         |
     clean                    unsafe/error
       |                         |
       v                         v
Private clean storage      REJECTED / retry
       |                         |
       v                         v
   AVAILABLE                SCAN_FAILED
       |
       | owner-authorized issuance
       v
temporary signed application URL
       |
       | signature + expiry + state re-check
       v
private clean stream

Owned lifecycle resource
       |
       | DELETE
       v
    DELETED → private object cleanup
```

## Implemented stack

- **Application:** Laravel 13 API application.
- **Database:** PostgreSQL as the source of truth for file metadata and lifecycle state.
- **Queue/cache coordination:** Redis.
- **Object storage:** two private S3-compatible logical zones; MinIO is used for local development.
- **Authentication:** Laravel Sanctum bearer tokens for normal authenticated API operations.
- **Controlled delivery:** Laravel temporary signed application routes that re-check current lifecycle state before private clean bytes are streamed.
- **Malware scanning:** `MalwareScanner` application contract with a ClamAV `clamd` adapter.
- **Local environment:** Docker Compose with API, dedicated scan worker, PostgreSQL, Redis, MinIO and internal-only ClamAV.
- **API documentation:** OpenAPI is required before V1 is considered complete.

## Core modules

### Identity

Owns bearer-token authentication and the authenticated actor context used by authorization.

### Files

Owns stored-file records, ownership, metadata, lifecycle state and user-visible file operations.

### Ingestion

Owns upload size limits, original-name normalization, extension allowlists, server-side MIME detection, hashing, per-owner duplicate checks, quarantine writes and scan-job handoff.

### Scanning

Owns queued scan jobs, the scanner adapter contract, ClamAV protocol handling, retry policy, clean promotion and fail-closed terminal states.

### Delivery

Owns owner-authorized capability issuance, `AVAILABLE` enforcement, temporary signed URLs, private clean-object streaming, content-response controls and deletion/revocation behavior.

### Audit

Will own append-oriented security events without storing file contents, bearer tokens, credentials or signed URLs.

## File lifecycle

```text
QUARANTINED
   |
   v
SCANNING
  / | \
 /  |  \
clean unsafe final error
 |     |       |
 v     v       v
AVAILABLE REJECTED SCAN_FAILED

QUARANTINED / SCANNING / AVAILABLE / REJECTED / SCAN_FAILED
                         |
                         | owner DELETE
                         v
                      DELETED
```

A file must never become `AVAILABLE` until validation, malware scanning and private clean-storage promotion succeed. State changes are server-controlled. Clients cannot set lifecycle state directly; DELETE is the explicit owner-authorized lifecycle action.

## Data model

### users

Identity required for ownership and authorization. User IDs are UUIDs.

### stored_files

Implemented core fields include:

- `id` — UUID;
- `owner_id`;
- `original_name` — display metadata only;
- `detected_mime_type`;
- `size_bytes`;
- `sha256` — active per-owner duplicate digest, nullable after deletion;
- `deleted_sha256` — internal historical digest retained after deletion;
- `state`;
- `quarantine_object_key` — internal and nullable after cleanup;
- `clean_object_key` — internal and nullable until clean promotion or after deletion cleanup;
- `scan_engine` — internal;
- `scan_signature` — internal and nullable;
- `scan_completed_at`;
- `deleted_at`;
- timestamps.

Owner + active SHA-256 uniqueness is enforced in the database. Clearing active `sha256` on deletion allows the same owner to upload identical bytes again while retaining the old digest internally for lifecycle evidence.

### failed_jobs

Laravel database-backed failed-job storage records queue failures for operational inspection. Raw failed-job payloads are operational data and are not exposed through normal user APIs.

### audit_events

Planned, not yet implemented. Expected safe fields include actor, action, target, outcome, request correlation data and bounded contextual metadata.

## Storage boundaries

V1 uses two private zones:

1. **Quarantine** — untrusted uploads. Never directly downloadable by users.
2. **Clean** — files that completed validation and a clean scan result.

Original filenames are never used as object keys.

On a clean result, the worker streams the quarantine object into clean storage first. Only after that write succeeds may metadata become `AVAILABLE`. If the metadata transition then fails, the new clean object is removed on the normal compensation path.

On an unsafe verdict, the file becomes `REJECTED`; no clean object is created. The normal path removes the quarantined unsafe object.

On final scanner/job failure, the file becomes `SCAN_FAILED` and its quarantine object remains private for later reconciliation.

Delivery does not make clean storage public. The signed capability addresses an application route, and the application opens the private clean object only after validating the capability and current lifecycle state.

## Upload flow

1. Authenticate request.
2. Apply upload rate limit.
3. Validate request/file presence.
4. Enforce 10 MiB maximum size.
5. Validate extension against the small allowlist.
6. Generate a UUID-backed object key.
7. Store bytes only in private quarantine.
8. Detect MIME server-side and compare it with extension policy.
9. Calculate SHA-256.
10. Perform per-owner duplicate detection; database uniqueness closes concurrent races.
11. Persist owned metadata as `QUARANTINED`.
12. Dispatch `ScanStoredFile` to the `scans` queue.
13. Return `202 Accepted`.

If queue dispatch fails, V1 uses explicit compensation: remove the newly persisted metadata and quarantine object when possible. If metadata compensation cannot complete, quarantine is preserved rather than deleting bytes an existing row may still reference.

## Scan flow

1. Worker receives a stored-file UUID.
2. Terminal states return without another scan.
3. `QUARANTINED` is conditionally claimed as `SCANNING`.
4. Worker reads the private quarantine object as a stream.
5. ClamAV adapter connects to internal `clamd` and uses `INSTREAM`.
6. `CLEAN` result triggers private clean-storage promotion followed by `AVAILABLE`.
7. `FOUND` result triggers `REJECTED` without clean promotion.
8. Protocol errors, timeouts, unavailable scanner/storage or lifecycle failures throw and are retried.
9. After final job failure, `failed()` transitions eligible processing states to `SCAN_FAILED`.

### Queue policy

- queue name: `scans`;
- attempts: 3;
- backoff: 5 seconds, then 30 seconds;
- job timeout: 30 seconds;
- Redis retry-after: 90 seconds;
- per-file `WithoutOverlapping` middleware protects concurrent processing.

The timeout remains below the retry-after horizon.

## ClamAV boundary

The current local adapter uses `clamd` TCP solely inside the Compose network.

- ClamAV TCP/3310 is **not** published to the host.
- The worker uses `INSTREAM`, sending bytes rather than asking the daemon to open a client-derived path.
- Scanner replies are reduced to application outcomes: clean, unsafe/signature, or error.
- Empty, unknown or error replies fail closed.
- Scanner engine/signature metadata is not returned by normal file APIs.

The application contract remains replaceable; the domain does not depend directly on ClamAV protocol types.

## Delivery flow

### Capability issuance

1. Authenticate the normal API request with Sanctum.
2. Authorize file ownership through `StoredFilePolicy::download`.
3. Require `state = AVAILABLE` and a clean object key.
4. Apply the independent download-capability rate limit.
5. Generate a temporary signed URL to the application content route.
6. Return `url` and `expires_at` with private/no-store response semantics.

Default capability lifetime is 300 seconds. Default issuance limit is 20 requests/minute per owner + IP.

### Signed content access

1. Validate the URL signature and expiry using signed-route middleware.
2. Apply the signed-content limit of 60 requests/minute per file ID + IP by default.
3. Load current file metadata.
4. Require current `state = AVAILABLE` and a clean object key again.
5. Open the private clean object as a stream.
6. Stream it with attachment disposition, detected MIME where available, `nosniff` and private/no-store cache semantics.

The signed content URL is a temporary bearer capability and does not require the original Sanctum token once issued. It can therefore be shared until expiry. The application limits that exposure by short expiry, never logging it by policy, and checking current lifecycle state on every use.

## Deletion flow

1. Authenticate and authorize the owner.
2. Lock the stored-file row in a database transaction.
3. If not already deleted, transition to `DELETED`, copy active SHA-256 into internal `deleted_sha256`, clear active `sha256`, and set `deleted_at`.
4. Capture any quarantine/clean object keys.
5. Delete referenced private objects.
6. Clear object keys only after successful storage cleanup.
7. Return `204 No Content`.

The operation is retry-safe: an already-`DELETED` resource can be deleted again. If storage cleanup fails, the row stays `DELETED` and unresolved keys remain for another retry; the API returns `503 DEPENDENCY_UNAVAILABLE`.

Because delivery requires current `AVAILABLE`, the `DELETED` transition revokes previously issued capabilities before object cleanup completes.

### Scan/deletion race behavior

Deletion and scanning may overlap operationally. The safety property is state-based:

- deletion commits `DELETED` under a row lock;
- scan terminal updates are conditional on their expected processing state;
- a scan that creates a clean object but loses the lifecycle race compensates by removing that clean object on the normal failure path;
- later scan retries see terminal `DELETED` and return without publishing content.

This protects delivery correctness without claiming a fully transactional database/object-storage boundary.

## Initial file policy

- PDF — `.pdf` / `application/pdf`;
- PNG — `.png` / `image/png`;
- JPEG — `.jpg`, `.jpeg` / `image/jpeg`;
- plain text — `.txt` / `text/plain`.

Maximum object size: **10 MiB**.

Expanding the accepted format set requires explicit review because every additional content type expands attack surface.

## Reliability rules

- Scan jobs are terminal-state idempotent.
- Concurrent scans for the same stored-file ID are overlap-protected.
- Scanner failure is never equivalent to a clean result.
- Clean storage must exist before `AVAILABLE` is committed.
- Unsafe objects never enter clean storage.
- Signed delivery re-checks current state at use time.
- Deletion sets the tombstone before private object cleanup so delivery fails closed during partial failure.
- Deletion retries retain unresolved storage keys until cleanup succeeds.
- Partial failures use explicit compensation where practical.
- Orphan reconciliation / transactional-outbox behavior remains hardening work rather than an unclaimed guarantee.

## Observability and readiness

Health endpoints currently remain intentionally conservative. `/health/ready` returns `503` until concrete database, Redis, object-storage and scanner probes are implemented.

Future logs/audit events must not leak secrets, raw tokens, signed URLs, object keys or file contents.

Useful operational signals include upload decisions, scan outcomes, queue failures/retries, state-transition failures, capability issuance/denial, signed-content failures, deletion cleanup outcomes and dependency health.

## Out of scope for V1

- public file sharing;
- anonymous uploads;
- permanent public URLs;
- archive extraction;
- server-side document conversion;
- remote-URL ingestion;
- preview generation;
- collaborative folders;
- antivirus signature-management UI;
- cross-user duplicate disclosure;
- end-to-end encryption with user-managed keys.

These exclusions keep the first release centered on a small, reviewable security boundary.
