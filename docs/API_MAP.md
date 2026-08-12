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

## Authentication

### `POST /api/v1/auth/register`

Creates a local demo user for the portfolio application.

**V1 purpose:** make the repository runnable end-to-end without requiring an external identity provider.

Planned inputs:

- `name`;
- `email`;
- `password`;
- `password_confirmation`.

Security notes:

- email uniqueness enforced;
- password never returned/logged;
- production-style verification requirements can be added later without changing file ownership semantics.

### `POST /api/v1/auth/login`

Exchanges credentials for a bearer token.

Planned response includes the token exactly once.

### `POST /api/v1/auth/logout`

Revokes the current token.

**Auth required.**

### `GET /api/v1/me`

Returns minimal current-user metadata.

**Auth required.**

## Files

### `POST /api/v1/files`

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
    "id": "01...",
    "original_name": "report.pdf",
    "detected_mime_type": "application/pdf",
    "size_bytes": 120034,
    "sha256": "...",
    "state": "QUARANTINED",
    "created_at": "..."
  }
}
```

The example is illustrative, not a promise of ULID specifically.

### `GET /api/v1/files`

Lists only files owned by the authenticated user.

**Auth required.**

Planned filters:

- `state`;
- pagination.

No global file search exists in V1.

### `GET /api/v1/files/{file}`

Returns metadata for one owned file.

**Auth required + ownership policy.**

Must not expose:

- quarantine object key;
- clean object key;
- raw scanner output;
- storage credentials;
- another user's existence through distinct authorization errors.

### `DELETE /api/v1/files/{file}`

Deletes/revokes an owned file resource according to lifecycle policy.

**Auth required + ownership policy.**

Deletion behavior must be idempotent from the API consumer's perspective where practical.

Implementation must define cleanup behavior for quarantine/clean objects and audit the deletion outcome.

### `POST /api/v1/files/{file}/download`

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

Clients poll `GET /api/v1/files/{file}` until the file reaches a terminal state or becomes `AVAILABLE`.

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

Planned error envelope:

```json
{
  "error": {
    "code": "FILE_TYPE_NOT_ALLOWED",
    "message": "The uploaded file type is not allowed.",
    "request_id": "..."
  }
}
```

Initial stable error codes should include:

- `UNAUTHENTICATED`;
- `FORBIDDEN`;
- `VALIDATION_FAILED`;
- `FILE_TOO_LARGE`;
- `FILE_TYPE_NOT_ALLOWED`;
- `FILE_TYPE_MISMATCH`;
- `DUPLICATE_FILE`;
- `FILE_NOT_AVAILABLE`;
- `RATE_LIMITED`;
- `SCAN_FAILED`;
- `DEPENDENCY_UNAVAILABLE`.

User-facing messages must not expose object keys, filesystem paths, stack traces or whether another user's matching hash exists.

## HTTP semantics

Planned status usage:

| Situation | Status |
|---|---:|
| asynchronous upload accepted | `202` |
| metadata read success | `200` |
| download capability issued | `200` |
| deletion success | `204` or idempotent equivalent |
| invalid input | `422` |
| unauthenticated | `401` |
| unauthorized/not-owned resource | `404` preferred where it avoids resource enumeration |
| per-owner duplicate | `409` |
| rate limit | `429` |
| temporary dependency failure | `503` |

Exact deletion semantics will be frozen in OpenAPI before V1 release.

## Rate-limit surfaces

Separate rate-limit policies should exist for:

- authentication attempts;
- upload creation;
- download-capability issuance;
- general authenticated API reads.

Numbers will be selected during implementation and tested rather than invented in the foundation document.

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
