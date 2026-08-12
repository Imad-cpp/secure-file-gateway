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

This document defines the V1 contract before implementation. Exact response envelopes may be refined when the OpenAPI specification is written, but security semantics should not drift silently.

## Implementation status

Implemented in the identity + ownership layer:

- `POST /api/v1/auth/register`;
- `POST /api/v1/auth/login`;
- `POST /api/v1/auth/logout`;
- `GET /api/v1/me`;
- `GET /api/v1/files`;
- `GET /api/v1/files/{file}`.

Still planned for later layers:

- `POST /api/v1/files`;
- `DELETE /api/v1/files/{file}`;
- `POST /api/v1/files/{file}/download`.

The implemented file endpoints expose ownership-safe metadata only. No upload, quarantine, scan or delivery capability is implied by the existence of a stored-file metadata row.

## Authentication

### `POST /api/v1/auth/register`

Creates a local demo user for the portfolio application.

**V1 purpose:** make the repository runnable end-to-end without requiring an external identity provider.

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

**Status:** Planned for secure-ingestion layer.

Accepts a file as `multipart/form-data`.

**Auth required.**

V1 validation order should fail as early as practical:

1. authentication;
2. rate limit;
3. request/file presence;
4. size limit;
5. extension allowlist;
6. quarantine write with generated name;
7. server-side MIME detection;
8. SHA-256 calculation;
9. per-owner duplicate check;
10. file-record persistence;
11. scan job enqueue.

Planned success status: `202 Accepted` because clean availability is asynchronous.

Example response shape:

```json
{
  "data": {
    "id": "...",
    "original_name": "report.pdf",
    "detected_mime_type": "application/pdf",
    "size_bytes": 120034,
    "sha256": "...",
    "state": "QUARANTINED",
    "created_at": "..."
  }
}
```

UUID identifiers are now the accepted V1 implementation choice; their opacity does not replace authorization.

### `GET /api/v1/files`

**Status:** Implemented.

Lists only files owned by the authenticated user.

**Auth required.**

Current behavior:

- ownership is scoped in the database query;
- pagination defaults to 20 items;
- no global file search exists.

State filtering remains planned for the ingestion/lifecycle layer.

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

Possible response:

```json
{
  "data": {
    "url": "https://storage.example/...signed...",
    "expires_at": "..."
  }
}
```

A direct `GET` redirect may be considered later, but V1 documentation starts with an explicit capability response because it makes expiry semantics obvious.

## Scan status

The file resource itself is the source of truth for user-visible scan state. A separate public `/scans` resource is not planned for V1.

Clients poll `GET /api/v1/files/{file}` until the file reaches a terminal state or becomes `AVAILABLE` once scanning exists.

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

Externally readable state values planned for V1:

- `QUARANTINED`;
- `SCANNING`;
- `AVAILABLE`;
- `REJECTED`;
- `SCAN_FAILED`;
- `DELETED`.

`RECEIVED` may remain an internal transient state if implementation does not need to expose it.

Clients cannot submit a desired state.

## Error contract

Implemented identity-layer error envelope follows the stable shape:

```json
{
  "error": {
    "code": "UNAUTHENTICATED",
    "message": "Authentication required."
  }
}
```

As later layers arrive, request correlation may add `request_id` without changing the core error-code contract.

Initial stable error codes include or plan:

- `UNAUTHENTICATED` — implemented;
- `VALIDATION_FAILED` — implemented;
- `RATE_LIMITED` — implemented for authentication;
- `FORBIDDEN` — reserved where disclosure is acceptable;
- `FILE_TOO_LARGE` — planned;
- `FILE_TYPE_NOT_ALLOWED` — planned;
- `FILE_TYPE_MISMATCH` — planned;
- `DUPLICATE_FILE` — planned;
- `FILE_NOT_AVAILABLE` — planned;
- `SCAN_FAILED` — planned;
- `DEPENDENCY_UNAVAILABLE` — planned.

User-facing messages must not expose object keys, filesystem paths, stack traces or whether another user's matching hash exists.

## HTTP semantics

| Situation | Status |
|---|---:|
| registration success | `201` |
| login success | `200` |
| logout success | `204` |
| asynchronous upload accepted | `202` |
| metadata read success | `200` |
| download capability issued | `200` |
| deletion success | `204` or idempotent equivalent |
| invalid input | `422` |
| unauthenticated | `401` |
| unauthorized/not-owned file resource | `404` |
| per-owner duplicate | `409` |
| rate limit | `429` |
| temporary dependency failure | `503` |

Exact deletion semantics will be frozen in OpenAPI before V1 release.

## Rate-limit surfaces

Implemented:

- authentication surfaces: 5 attempts per minute by normalized email + IP by default.

Still to be selected and tested independently:

- upload creation;
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
