# API Map

## API principles

- Base path: `/api/v1`.
- JSON for metadata and errors.
- Multipart upload for V1 file ingestion.
- Bearer-token authentication for normal user API operations.
- Temporary signed application URLs for controlled file-content delivery.
- Opaque public identifiers.
- Server-owned lifecycle states.
- Error responses use stable machine-readable codes.
- No endpoint returns quarantine or clean object keys.

This document defines the V1 contract alongside implementation. Exact response envelopes may be refined when the OpenAPI specification is written, but security semantics should not drift silently.

## Implementation status

Implemented:

- `POST /api/v1/auth/register`;
- `POST /api/v1/auth/login`;
- `POST /api/v1/auth/logout`;
- `GET /api/v1/me`;
- `POST /api/v1/files` with quarantine + scan-job handoff;
- asynchronous scan-driven transitions through `SCANNING`, `AVAILABLE`, `REJECTED` and `SCAN_FAILED`;
- `GET /api/v1/files`;
- `GET /api/v1/files/{file}`;
- `POST /api/v1/files/{file}/download`;
- temporary signed `GET /api/v1/files/{file}/content`;
- `DELETE /api/v1/files/{file}` with `DELETED` tombstone semantics.

Still planned for later layers:

- audit-event hardening;
- concrete dependency readiness probes;
- final OpenAPI contract and release evidence.

No implemented normal-user endpoint exposes quarantine object keys, clean object keys, raw scanner output, internal malware signatures, deleted historical digests or cross-user duplicate information.

## Authentication

### `POST /api/v1/auth/register`

Creates a local demo user for the portfolio application.

Inputs:

- `name`;
- `email`;
- `password`;
- `password_confirmation`.

Implemented security notes:

- email is normalized to lowercase and uniqueness is enforced;
- password requires at least 12 characters with mixed case, numbers and symbols;
- password is hashed by the model and never returned by the API;
- authentication surfaces use the named auth rate limiter.

### `POST /api/v1/auth/login`

Exchanges credentials for a bearer token.

Implemented behavior:

- invalid credentials return the same `UNAUTHENTICATED` response regardless of whether the email exists;
- the default authentication throttle is 5 attempts per minute keyed by normalized email + IP;
- the token is returned exactly once;
- tokens expire after 720 minutes (12 hours) by default.

### `POST /api/v1/auth/logout`

Revokes the current token and returns `204 No Content`.

**Auth required.**

### `GET /api/v1/me`

Returns minimal current-user metadata.

**Auth required.**

## Files

### `POST /api/v1/files`

**Status:** Implemented through scan-job handoff.

Accepts one `file` field as `multipart/form-data`.

**Auth required + upload rate limit.**

Implemented request/processing order:

1. authentication;
2. upload rate limit;
3. request/file presence;
4. 10 MiB size limit;
5. extension allowlist;
6. private quarantine write with a server-generated extensionless object name;
7. server-side MIME detection from file bytes;
8. extension/MIME agreement check;
9. SHA-256 calculation;
10. per-owner duplicate check;
11. file-record persistence with owner + SHA-256 database uniqueness;
12. scan-job dispatch to the `scans` queue;
13. return `202 Accepted` with state `QUARANTINED`.

If queue dispatch fails after metadata persistence, the normal compensation path removes the new metadata row and quarantine object. If metadata compensation cannot complete, quarantine is preserved rather than deleting bytes that an existing row may still reference.

Implemented V1 allowlist:

| Extension | Accepted detected MIME |
|---|---|
| `.pdf` | `application/pdf` |
| `.png` | `image/png` |
| `.jpg`, `.jpeg` | `image/jpeg` |
| `.txt` | `text/plain` |

All types share a 10 MiB maximum.

Success response shape:

```json
{
  "data": {
    "id": "uuid",
    "original_name": "report.pdf",
    "detected_mime_type": "application/pdf",
    "size_bytes": 120034,
    "sha256": "...",
    "state": "QUARANTINED",
    "created_at": "...",
    "deleted_at": null
  }
}
```

The response deliberately omits owner IDs, storage keys and internal scanner/deletion metadata.

Duplicate semantics:

- duplicate detection is SHA-256 based and scoped to the authenticated owner;
- owner + SHA-256 uniqueness is enforced in the database to protect against concurrent races;
- identical bytes for different owners are allowed and do not produce a cross-user presence signal;
- deletion clears active `sha256`, allowing the same owner to upload identical bytes again later while preserving the historical digest internally as `deleted_sha256`.

### `GET /api/v1/files`

**Status:** Implemented.

Lists only files owned by the authenticated user.

**Auth required.**

Current behavior:

- ownership is scoped in the database query;
- pagination defaults to 20 items;
- state reflects asynchronous scan/deletion progress;
- no global file search exists.

### `GET /api/v1/files/{file}`

**Status:** Implemented.

Returns metadata for one owned file and is the polling surface for asynchronous state.

**Auth required + ownership policy.**

Foreign-owned file identifiers are denied as `404` to reduce resource-enumeration leakage.

The response does not expose:

- owner ID;
- quarantine object key;
- clean object key;
- scanner engine;
- malware signature;
- raw scanner output;
- deleted historical SHA-256;
- storage credentials.

### `POST /api/v1/files/{file}/download`

**Status:** Implemented.

Issues controlled short-lived access to an owned clean file.

**Auth required + ownership policy + `state = AVAILABLE` + download-capability rate limit.**

Implemented behavior:

1. authorize ownership;
2. require `AVAILABLE` and a clean object key;
3. create an application-signed URL for the file-content route;
4. expire the capability after 300 seconds by default;
5. return the URL and `expires_at` using `private, no-store` response semantics.

Default issuance rate limit: **20 requests per minute per authenticated owner + IP**.

Success response:

```json
{
  "data": {
    "url": "http://localhost:8000/api/v1/files/uuid/content?expires=...&signature=...",
    "expires_at": "..."
  }
}
```

The returned URL is a temporary bearer capability. It must not be logged, persisted as a public share URL or assumed to require the original bearer token when used.

### `GET /api/v1/files/{file}/content`

**Status:** Implemented temporary signed capability route.

This is not a normal bearer-authenticated metadata endpoint. Possession of a currently valid signed URL is the capability.

Implemented checks/behavior:

1. `signed` middleware verifies signature and expiry before content handling;
2. per-file + IP content throttling is applied;
3. the current database row is loaded;
4. current state must still be `AVAILABLE` and a clean object key must exist;
5. bytes are opened from private clean storage and streamed as a download.

Response controls include:

- attachment `Content-Disposition` with a sanitized display filename;
- detected MIME `Content-Type` where available;
- `X-Content-Type-Options: nosniff`;
- `private, no-store, max-age=0` cache semantics;
- `Content-Length` when known.

Default content limit: **60 requests per minute per file + IP**.

A file deleted after capability issuance no longer streams because the content route re-checks current state and requires `AVAILABLE`.

### `DELETE /api/v1/files/{file}`

**Status:** Implemented.

Deletes/revokes an owned file resource using retry-safe tombstone semantics.

**Auth required + ownership policy.**

Implemented behavior:

1. lock the file row inside a transaction;
2. transition to `DELETED` if not already deleted;
3. preserve the previous SHA-256 internally as `deleted_sha256` and clear active `sha256`;
4. set `deleted_at`;
5. delete any referenced quarantine and clean objects;
6. clear private object keys after successful storage cleanup;
7. return `204 No Content`.

Repeated DELETE requests are idempotent from the API consumer's perspective.

If storage cleanup fails, the API returns `503 DEPENDENCY_UNAVAILABLE`; the row remains `DELETED` and unresolved object keys remain for retry. Because delivery requires current state `AVAILABLE`, deletion revokes already-issued capabilities before cleanup finishes.

Foreign-owned deletion attempts return `404` and do not alter metadata or storage.

## Scan status

The file resource itself is the source of truth for user-visible scan state. There is no normal-user `/scans` resource in V1.

Implemented scan behavior:

1. upload returns `QUARANTINED`;
2. the queued job claims `SCANNING`;
3. the ClamAV adapter streams private quarantine bytes using `INSTREAM`;
4. clean result copies bytes into private clean storage, then transitions to `AVAILABLE`;
5. malware detection transitions to `REJECTED` without creating a clean object;
6. scanner/worker errors are retried and final job failure transitions to `SCAN_FAILED`;
7. terminal states are not scanned again.

Normal API consumers see only the lifecycle state. Scanner signatures and engine details are internal metadata.

## Audit endpoint

### `GET /api/v1/audit-events`

Not part of the normal-user V1 API by default.

If an administrative role is introduced for the portfolio demo, this endpoint may expose sanitized security events through explicit authorization. It must never become a shortcut around file ownership boundaries.

## Health endpoints

### `GET /health/live`

Process liveness only. Must not expose environment details.

### `GET /health/ready`

Dependency readiness suitable for local/container orchestration.

Current behavior remains fail-closed (`503`) until concrete PostgreSQL, Redis, object-storage and scanner probes are implemented. Future response details must not disclose credentials, bucket names, internal stack traces or sensitive topology.

## Lifecycle states

Externally readable V1 states:

- `QUARANTINED` — accepted into private quarantine and queued;
- `SCANNING` — scan job has claimed processing;
- `AVAILABLE` — clean result and private clean-storage promotion completed;
- `REJECTED` — malware/unsafe result;
- `SCAN_FAILED` — final scan/worker failure;
- `DELETED` — deletion requested and all delivery is revoked; storage cleanup may be complete or retry-pending.

Clients cannot submit arbitrary desired states. DELETE is the explicit lifecycle action that can move an owned file to `DELETED`.

## Error contract

Stable error envelope:

```json
{
  "error": {
    "code": "FILE_TYPE_NOT_ALLOWED",
    "message": "The uploaded file extension is not allowed."
  }
}
```

Implemented error codes relevant to the HTTP API:

- `UNAUTHENTICATED`;
- `VALIDATION_FAILED`;
- `RATE_LIMITED`;
- `FILE_TOO_LARGE`;
- `FILE_TYPE_NOT_ALLOWED`;
- `FILE_TYPE_MISMATCH`;
- `DUPLICATE_FILE`;
- `FILE_NOT_AVAILABLE`;
- `INVALID_DOWNLOAD_SIGNATURE`;
- `DEPENDENCY_UNAVAILABLE` for storage, metadata, queue-handoff or deletion-cleanup failures.

`SCAN_FAILED` is primarily a resource state rather than an immediate upload HTTP error because scanning is asynchronous.

User-facing messages must not expose object keys, filesystem paths, stack traces, scanner signatures, signed URLs beyond the successful capability response or whether another user's matching hash exists.

## HTTP semantics

| Situation | Status |
|---|---:|
| registration success | `201` |
| login success | `200` |
| logout success | `204` |
| asynchronous upload accepted | `202` |
| metadata / scan-state read success | `200` |
| download capability issued | `200` |
| signed file content streamed | `200` |
| deletion success / idempotent retry | `204` |
| invalid input / file policy rejection | `422` |
| unauthenticated normal API request | `401` |
| unauthorized/not-owned file resource | `404` |
| file not currently available for delivery | `409` |
| per-owner duplicate | `409` |
| invalid or expired signed capability | `403` |
| rate limit | `429` |
| temporary dependency / cleanup / queue handoff failure | `503` |

## Rate-limit surfaces

Implemented independently:

- authentication surfaces: 5 attempts per minute by normalized email + IP by default;
- upload creation: 10 attempts per minute by authenticated owner + IP by default;
- download-capability issuance: 20 attempts per minute by authenticated owner + IP by default;
- signed content: 60 attempts per minute by file ID + IP by default.

General authenticated API read throttling remains a hardening decision.

## OpenAPI requirement

Before V1 release, `openapi.yaml` must define:

- every public endpoint and the temporary signed-content capability route;
- authentication scheme;
- request/response schemas;
- error envelope;
- lifecycle enum;
- upload content type and size documentation;
- signed-capability semantics and expiry;
- example responses;
- authorization expectations where representable.

OpenAPI and implementation must be checked for drift in CI or by an explicit test/review step.
