# API Map

## API principles

- Base path: `/api/v1`.
- JSON for metadata and errors.
- Multipart upload for V1 file ingestion.
- Bearer-token authentication.
- Opaque public identifiers.
- Server-owned lifecycle states.
- Error responses use stable machine-readable codes.
- No endpoint returns a quarantine object URL.

This document defines the V1 contract alongside implementation. Exact response envelopes may be refined when the OpenAPI specification is written, but security semantics should not drift silently.

## Implementation status

Implemented:

- `POST /api/v1/auth/register`;
- `POST /api/v1/auth/login`;
- `POST /api/v1/auth/logout`;
- `GET /api/v1/me`;
- `POST /api/v1/files` through the `QUARANTINED` state;
- `GET /api/v1/files`;
- `GET /api/v1/files/{file}`.

Still planned for later layers:

- scan job enqueue and scan-driven lifecycle transitions;
- `DELETE /api/v1/files/{file}`;
- `POST /api/v1/files/{file}/download`.

No implemented endpoint exposes quarantine object keys, clean object keys or cross-user duplicate information.

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

**Status:** Implemented through quarantine. Malware scanning is not implemented yet.

Accepts one `file` field as `multipart/form-data`.

**Auth required + upload rate limit.**

Implemented validation/processing order:

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
12. return `202 Accepted` with state `QUARANTINED`.

The scanning layer will later enqueue scan work and advance the lifecycle from `QUARANTINED` to `SCANNING`.

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

The response deliberately omits owner IDs and storage keys.

Cleanup semantics in the current ingestion layer:

- disallowed extension and oversize failures occur before quarantine write;
- MIME mismatch, same-owner duplicate rejection and metadata persistence failure remove the newly written quarantine object on the normal failure path;
- cleanup reconciliation for an unavailable storage backend remains hardening work.

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
- no global file search exists.

State filtering remains planned for lifecycle refinement.

### `GET /api/v1/files/{file}`

**Status:** Implemented.

Returns metadata for one owned file.

**Auth required + ownership policy.**

Foreign-owned file identifiers are denied as `404` to reduce resource-enumeration leakage.

The response does not expose:

- owner ID;
- quarantine object key;
- clean object key;
- raw scanner output;
- storage credentials.

### `DELETE /api/v1/files/{file}`

**Status:** Planned for controlled-delivery/lifecycle work.

Deletes/revokes an owned file resource according to lifecycle policy.

**Auth required + ownership policy.**

Deletion behavior must be idempotent from the API consumer's perspective where practical.

Implementation must define cleanup behavior for quarantine/clean objects and audit the deletion outcome.

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

The file resource itself is the source of truth for user-visible scan state. A separate public `/scans` resource is not planned for V1.

Current ingestion stops at `QUARANTINED`. Once scanning exists, clients poll `GET /api/v1/files/{file}` until the file reaches a terminal state or becomes `AVAILABLE`.

## Audit endpoint

### `GET /api/v1/audit-events`

Not part of the normal-user V1 API by default.

If an administrative role is introduced for the portfolio demo, this endpoint may expose sanitized security events through explicit authorization. It must never become a shortcut around file ownership boundaries.

## Health endpoints

### `GET /health/live`

Process liveness only. Must not expose environment details.

### `GET /health/ready`

Dependency readiness suitable for local/container orchestration.

Response may summarize dependency state without credentials, host secrets, bucket names or internal stack traces.

## Lifecycle states

Externally readable V1 states:

- `QUARANTINED` — currently emitted by successful ingestion;
- `SCANNING` — planned scanning layer;
- `AVAILABLE` — planned after a clean scanner result;
- `REJECTED` — planned unsafe result;
- `SCAN_FAILED` — planned scanner failure/error;
- `DELETED` — planned lifecycle work.

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

Implemented error codes relevant to the current API:

- `UNAUTHENTICATED`;
- `VALIDATION_FAILED`;
- `RATE_LIMITED`;
- `FILE_TOO_LARGE`;
- `FILE_TYPE_NOT_ALLOWED`;
- `FILE_TYPE_MISMATCH`;
- `DUPLICATE_FILE`;
- `DEPENDENCY_UNAVAILABLE` for ingestion storage/metadata dependency failures.

Reserved for later layers:

- `FORBIDDEN` where disclosure is acceptable;
- `FILE_NOT_AVAILABLE`;
- `SCAN_FAILED`.

User-facing messages must not expose object keys, filesystem paths, stack traces or whether another user's matching hash exists.

## HTTP semantics

| Situation | Status |
|---|---:|
| registration success | `201` |
| login success | `200` |
| logout success | `204` |
| asynchronous upload accepted | `202` |
| metadata read success | `200` |
| invalid input / file policy rejection | `422` |
| unauthenticated | `401` |
| unauthorized/not-owned file resource | `404` |
| per-owner duplicate | `409` |
| rate limit | `429` |
| temporary dependency failure | `503` |
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
