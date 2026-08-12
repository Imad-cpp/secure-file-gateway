# Architecture

## Purpose

Secure File Gateway is an API-first service for accepting untrusted files, isolating them, validating them, scanning them asynchronously and exposing only approved files through controlled, short-lived access.

The project is intentionally designed as a small security-focused system rather than a generic cloud-drive clone.

## V1 architecture

```text
Client
  |
  | HTTPS + Bearer token
  v
Laravel API
  |-- authentication / authorization
  |-- upload validation
  |-- metadata + state transitions
  |-- audit events
  |
  +------> PostgreSQL
  |
  +------> Redis queue / rate-limit coordination
  |
  +------> Private quarantine storage
                    |
                    v
              Scan worker
                    |
           clean? --+-- unsafe/error?
             |                |
             v                v
      Private clean       rejected/failed
        storage              state
             |
             v
      short-lived signed
       download access
```

## Planned stack

- **Application:** Laravel 13 modular API application.
- **Database:** PostgreSQL as the source of truth for file metadata, states and audit records.
- **Queue/cache coordination:** Redis.
- **Object storage:** private S3-compatible storage; MinIO is suitable for local development.
- **Authentication:** bearer-token authentication using Laravel Sanctum.
- **Malware scanning:** scanner adapter behind an application interface so the domain does not depend on one scanner implementation.
- **Local environment:** Docker Compose is planned for application dependencies.
- **API documentation:** OpenAPI specification is required before V1 is considered complete.

The scanner implementation is intentionally not fixed in the foundation. The application contract is `scan(file) -> clean | unsafe | error`; infrastructure-specific scanner choice remains replaceable.

## Core modules

### Identity

Owns token authentication and the authenticated actor context used by authorization and audit events.

### Files

Owns file records, ownership, metadata, lifecycle state and user-visible file operations.

### Ingestion

Owns upload size limits, filename normalization, extension allowlists, server-side MIME detection, hashing and quarantine writes.

### Scanning

Owns asynchronous malware-scan jobs, scanner adapters, retry policy and final scan result handling.

### Delivery

Owns authorization checks and creation of short-lived signed access to files that are in the `AVAILABLE` state only.

### Audit

Owns append-only security-relevant events without storing file contents, bearer tokens or secrets.

## File lifecycle

```text
RECEIVED
   |
   v
QUARANTINED
   |
   v
SCANNING
  / | \
 /  |  \
clean unsafe scanner error
 |     |       |
 v     v       v
AVAILABLE  REJECTED  SCAN_FAILED
   |
   v
DELETED
```

Validation failures do not enter clean storage. A file must never become `AVAILABLE` until validation and scanning both succeed.

State changes are server-controlled. Clients cannot set lifecycle state directly.

## Data model — foundation

### users

Identity required for ownership and authorization. Exact registration UX is secondary to the gateway domain.

### files

Planned fields:

- `id` — opaque UUID/ULID-style public identifier;
- `owner_id`;
- `original_name` — display metadata only, never a storage path;
- `storage_name` — generated server-side;
- `extension`;
- `detected_mime_type`;
- `size_bytes`;
- `sha256`;
- `state`;
- `quarantine_object_key` — nullable after cleanup;
- `clean_object_key` — nullable until approved;
- `scan_completed_at`;
- timestamps.

### file_scan_results

Stores scanner outcome and operational metadata without exposing sensitive raw scanner output to normal users.

### audit_events

Planned fields:

- actor;
- action;
- target type/id;
- outcome;
- request correlation identifier;
- safe contextual metadata;
- timestamp.

## Storage boundaries

V1 uses two logical private zones:

1. **Quarantine** — contains untrusted uploads. Files here are never directly downloadable by users.
2. **Clean** — contains only files that passed validation and scanning.

Production-style deployment should prefer separate bucket/prefix permissions so a component receives only the minimum storage access it needs.

Original filenames must never be used as object keys.

## Upload flow

1. Authenticate request.
2. Apply request and account rate limits.
3. Enforce maximum upload size before processing more data than necessary.
4. Validate extension against the allowlist.
5. Generate an opaque storage name.
6. Store only in quarantine.
7. Detect real MIME type server-side and compare it with the allowed extension/MIME mapping.
8. Calculate SHA-256.
9. Perform per-owner duplicate detection without revealing whether another user's identical file exists.
10. Persist metadata and enqueue scan work.
11. Return the file resource in a non-downloadable processing state.
12. Worker scans the quarantined object.
13. On a clean result, move/copy the object into clean private storage and transition to `AVAILABLE` atomically.
14. On unsafe or scanner-error outcomes, keep the file unavailable and record the correct terminal/retry state.

## Delivery flow

1. Authenticate request.
2. Authorize ownership or privileged access.
3. Require `state = AVAILABLE`.
4. Generate a short-lived signed URL or equivalent controlled response.
5. Record a safe audit event.

A storage object must never be made public permanently.

## Initial file policy

V1 intentionally starts with a small allowlist:

- PDF — `.pdf` / `application/pdf`;
- PNG — `.png` / `image/png`;
- JPEG — `.jpg`, `.jpeg` / `image/jpeg`;
- plain text — `.txt` / `text/plain`.

Initial maximum object size: **10 MiB**.

These limits are product/security decisions, not hard promises for future versions. Expanding the accepted format set requires explicit review because every additional parser/content type increases attack surface.

## Reliability rules

- Scan jobs must be idempotent.
- State transitions must reject impossible transitions.
- Retried jobs must not publish the same object twice.
- Database state and storage operations must be designed for partial-failure recovery.
- Scanner failure is not equivalent to a clean result.
- Cleanup of rejected/quarantined objects must be explicit and testable.

## Observability

V1 should expose structured logs and health checks that do not leak secrets, raw tokens, object keys or file contents.

Useful signals include:

- upload accepted/rejected counts;
- scan outcomes;
- queue failures/retries;
- state-transition failures;
- signed-download authorization failures;
- storage/scanner dependency health.

## Out of scope for V1

- public file sharing;
- anonymous uploads;
- permanent public URLs;
- archive extraction;
- server-side document conversion;
- remote-URL ingestion;
- image/document preview generation;
- collaborative folders;
- antivirus signature management UI;
- cross-user duplicate disclosure;
- end-to-end encryption with user-managed keys.

These exclusions keep the first release centered on a small, reviewable security boundary.
