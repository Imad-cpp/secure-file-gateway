# API Map

## API principles

- Base path: `/api/v1`.
- JSON for metadata and errors.
- Multipart upload for V1 file ingestion.
- Bearer-token authentication.
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
- `GET /api/v1/files/{file}`.

Still planned for later layers:

- `DELETE /api/v1/files/{file}`;
- `POST /api/v1/files/{file}/download`;
- audit/readiness hardening;
- final OpenAPI contract.

No implemented endpoint exposes quarantine object keys, clean object keys, raw scanner output, internal malware signatures or cross-user duplicate information.

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
    "created_at": "..."
  }
}
```

The response deliberately omits owner IDs, storage keys and internal scanner metadata.

Duplicate semantics:

- duplicate detection is SHA-256 based and scoped to the authenticated owner;
- owner + SHA-256 uniqueness is enforced in the database to protect against concurrent races;
- identical bytes for different owners are allowed and do not produce a cross-user presence signal.

### `GET /api/v1/files`

**Status:** Implemented.

Lists only files owned by the authenticated user.

**Auth required.**

Current behavior:

- ownership is scoped in the database query;
- pagination defaults to 20 items;
- state reflects asynchronous scan progress;
- no global file search exists.

State filtering remains planned for lifecycle refinement.

### `GET /api/v1/files/{file}`

**Status:** Implemented.

Returns metadata for one owned file and is the polling surface for asynchronous scan state.

**Auth required + ownership policy.**

Foreign-owned file identifiers are denied as `404` to reduce resource-enumeration leakage.

The response does not expose:

- owner ID;
- quarantine object key;
- clean object key;
- scanner engine;
- malware signature;
- raw scanner output;
- storage credentials.

### `DELETE /api/v1/files/{file}`

**Status:** Planned for controlled-delivery/lifecycle work.

Deletes/revokes an owned file resource according to lifecycle policy.

**Auth required + ownership policy.**

Deletion behavior must be idempotent from the API consumer's perspective where practical. Implementation must define cleanup behavior for quarantine/clean objects and audit the deletion outcome.

### `POST /api/v1/files/{file}/download`

**Status:** Planned for controlled-delivery layer.

Requests controlled access to an owned clean file.

**Auth required + ownership policy + `state = AVAILABLE`.**

Planned behavior:

- server authorizes access;
- server creates a short-lived signed URL or equivalent controlled capability;
- response does not cache as a permanent public location;
- audit event records successful/denied request without storing the signed URL.

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
- `DELETED` — planned controlled-lifecycle work.

Clients cannot submit a desired state.

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

Implemented error codes relevant to the current HTTP API:

- `UNAUTHENTICATED`;
- `VALIDATION_FAILED`;
- `RATE_LIMITED`;
- `FILE_TOO_LARGE`;
- `FILE_TYPE_NOT_ALLOWED`;
- `FILE_TYPE_MISMATCH`;
- `DUPLICATE_FILE`;
- `DEPENDENCY_UNAVAILABLE` for storage, metadata or queue-handoff failures.

Reserved/next-layer HTTP errors:

- `FORBIDDEN` where disclosure is acceptable;
- `FILE_NOT_AVAILABLE`;
- explicit delivery/deletion errors as OpenAPI is frozen.

`SCAN_FAILED` is primarily a resource state rather than an immediate upload HTTP error because scanning is asynchronous.

User-facing messages must not expose object keys, filesystem paths, stack traces, scanner signatures or whether another user's matching hash exists.

## HTTP semantics

| Situation | Status |
|---|---:|
| registration success | `201` |
| login success | `200` |
| logout success | `204` |
| asynchronous upload accepted | `202` |
| metadata / scan-state read success | `200` |
| invalid input / file policy rejection | `422` |
| unauthenticated | `401` |
| unauthorized/not-owned file resource | `404` |
| per-owner duplicate | `409` |
| rate limit | `429` |
| temporary dependency / queue handoff failure | `503` |
| download capability issued | `200` planned |
| deletion success | `204` or idempotent equivalent planned |

## Rate-limit surfaces

Implemented independently:

- authentication surfaces: 5 attempts per minute by normalized email + IP by default;
- upload creation: 10 attempts per minute by authenticated owner + IP by default.

Still to be selected and tested independently:

- download-capability issuance;
- general authenticated API reads.

## OpenAPI requirement

Before V1 release, `openapi.yaml` must define:

- every public endpoint;
- authentication scheme;
- request/response schemas;
- error envelope;
- lifecycle enum;
- upload content type and size documentation;
- example responses;
- authorization expectations where representable.

OpenAPI and implementation must be checked for drift in CI or by an explicit test/review step.
