# Secure File Gateway

Security-focused file upload and delivery API built around **validation, quarantine, asynchronous scanning, auditability and controlled access**.

> **Status:** Secure ingestion implemented. Laravel 13, local infrastructure, bearer-token authentication, owner-scoped metadata and private quarantine ingestion are in place. Malware scanning and clean delivery are not implemented yet; no production-readiness claim is made.

## Why this project exists

File upload looks simple until the input has to be treated as hostile.

This project is designed to demonstrate the engineering boundary between **receiving an untrusted object** and **releasing a controlled clean object**. It focuses on the backend/security decisions that are easy to hide in a generic upload demo: ownership, MIME spoofing, path safety, quarantine, scanner failure, lifecycle races, signed access, safe logging and auditability.

It is intentionally **not** a cloud-drive clone.

## Core flow

```text
Authenticate
    ↓
Upload
    ↓
Size + extension checks
    ↓
Private quarantine
    ↓
Server-side MIME + SHA-256
    ↓
Queue scan
    ↓
┌──────────── clean ────────────┐
│                               ↓
│                         Private clean storage
│                               ↓
│                      Authorized short-lived access
│
└── unsafe / scanner error → non-downloadable state
```

The flow is implemented through `QUARANTINED`. Queue scanning and clean delivery are the next layers.

## Security invariants

- Every upload is untrusted by default.
- Quarantine objects are never directly downloadable by users.
- Client-provided filenames and MIME values do not control storage behavior.
- Original filenames are display metadata only; object names are server-generated.
- A file becomes `AVAILABLE` only after validation and a clean scanner result.
- Scanner failure **fails closed**.
- Every file read/delete/download operation is ownership-authorized.
- Clean storage remains private; access is short-lived and explicitly authorized.
- Duplicate detection is scoped to the same owner so it does not become a cross-user presence oracle.
- Audit/logging must not contain file bodies, bearer tokens, credentials or signed URLs.

## V1 stack

`Laravel 13` · `PHP 8.3+` · `PostgreSQL` · `Redis` · `S3-compatible private storage` · `Laravel Sanctum` · `Docker Compose` · `OpenAPI`

The application now wires Laravel Sanctum bearer tokens, PostgreSQL/Redis configuration, UUID-backed ownership metadata, private quarantine ingestion and two private S3-compatible storage zones. The malware-scanner adapter arrives with the scanning layer rather than being claimed before use.

## Identity + ownership

Implemented API security boundary:

```text
POST /api/v1/auth/register
POST /api/v1/auth/login
POST /api/v1/auth/logout
GET  /api/v1/me
GET  /api/v1/files
GET  /api/v1/files/{file}
```

- User and stored-file public identifiers use UUIDs.
- Registration normalizes email addresses and requires a 12+ character password with mixed case, numbers and symbols.
- Login returns a Sanctum bearer token with a default 12-hour lifetime.
- Authentication attempts are limited to 5 per minute per normalized email + IP pair by default.
- Logout revokes only the current bearer token.
- File listings are owner-scoped at the query boundary.
- Reading another user's file identifier returns `404` to reduce resource-enumeration leakage.
- Metadata responses never expose owner IDs, quarantine keys or clean-storage keys.

## Secure ingestion

`POST /api/v1/files` now implements the untrusted-file boundary through quarantine.

```text
Authenticated multipart upload
    ↓
10 MiB size limit
    ↓
Extension allowlist
    ↓
Server-generated object key
    ↓
Private quarantine write
    ↓
Server-side MIME detection
    ↓
Extension/MIME agreement
    ↓
SHA-256
    ↓
Per-owner duplicate check
    ↓
Metadata persistence
    ↓
202 Accepted / QUARANTINED
```

Implemented controls:

- V1 accepts PDF, PNG, JPEG and plain text only.
- Client-declared MIME is not trusted; PHP Fileinfo inspects the uploaded bytes.
- Storage keys are generated from server-owned UUIDs and never reuse the original filename.
- Quarantine storage stays private.
- SHA-256 duplicate detection is scoped to one owner.
- PostgreSQL enforces owner + SHA-256 uniqueness so concurrent duplicate requests cannot bypass the application pre-check.
- The same bytes may exist for different owners; the API does not reveal cross-user duplicate information.
- A new quarantine object is removed when MIME verification, duplicate checks or metadata persistence reject the upload.
- Upload creation has an independent default limit of 10 requests per minute per authenticated owner + IP.
- Successful ingestion returns `202 Accepted` and remains `QUARANTINED`; scanning is not claimed yet.

## Local development

The development stack uses Docker Compose with PostgreSQL, Redis and MinIO. The MinIO initializer creates two private buckets: `sfg-quarantine` and `sfg-clean`.

```bash
cp .env.example .env
docker compose up --build
```

The API is then available at `http://localhost:8000` and the MinIO console at `http://localhost:9001`.

The credentials in `.env.example` are deliberately local-only examples. They must never be reused in shared, staging or production environments.

Useful checks:

```bash
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app php artisan test
```

## Health semantics

- `GET /health/live` returns `200` when Laravel can boot and serve a request.
- `GET /health/ready` currently returns `503` deliberately. Readiness will turn healthy only after concrete PostgreSQL, Redis and object-storage dependency probes exist.

This keeps the application fail-closed instead of pretending dependencies are ready before they are actually verified.

## Initial V1 file policy

| Type | Allowed extension | Server-detected MIME | Maximum size |
|---|---|---|---:|
| PDF | `.pdf` | `application/pdf` | 10 MiB |
| PNG | `.png` | `image/png` | 10 MiB |
| JPEG | `.jpg`, `.jpeg` | `image/jpeg` | 10 MiB |
| Plain text | `.txt` | `text/plain` | 10 MiB |

The allowlist starts small on purpose. Adding content types expands attack surface and therefore requires explicit review and tests.

## Planned lifecycle

```text
RECEIVED → QUARANTINED → SCANNING → AVAILABLE
                              ├──→ REJECTED
                              └──→ SCAN_FAILED

AVAILABLE → DELETED
```

State transitions are server-controlled. Clients cannot mark a file clean or available.

## Foundation documents

- [Architecture](docs/ARCHITECTURE.md) — system boundaries, storage zones, modules, lifecycle and failure rules.
- [Security Model](docs/SECURITY_MODEL.md) — assets, trust boundaries, threats, controls and required negative tests.
- [API Map](docs/API_MAP.md) — V1 endpoints, implementation status, errors and HTTP semantics.
- [Engineering Decisions](docs/DECISIONS.md) — accepted choices and intentionally deferred decisions.
- [Definition of Done](docs/DEFINITION_OF_DONE.md) — the quality/security bar required before V1 is considered portfolio-ready.

## V1 API surface

```text
# Implemented
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/me
POST   /api/v1/files
GET    /api/v1/files
GET    /api/v1/files/{file}

# Planned next layers
DELETE /api/v1/files/{file}
POST   /api/v1/files/{file}/download

GET    /health/live
GET    /health/ready
```

A successful upload returns `202 Accepted`; it stays non-downloadable in `QUARANTINED` until the scanning layer is implemented and returns a clean result.

## Quality gate

Pull requests and pushes to `main` run the `Application Quality` workflow. The gate validates the Composer manifest, installs dependencies, boots the application, enforces Pint formatting, runs the complete feature/unit test suite and audits resolved Composer dependencies.

Coverage includes identity/ownership controls plus ingestion authentication, size/extension policy, server-side MIME mismatch rejection, quarantine cleanup, SHA-256 duplicate isolation and upload throttling.

GitHub Actions permissions are read-only, checkout credentials are not persisted, and reusable actions are pinned to full commit SHAs.

## What V1 will prove

When complete, this repository should provide public evidence of:

- API and backend architecture;
- authorization and object-level security;
- secure file-ingestion design;
- queues and failure-aware state transitions;
- PostgreSQL data modelling;
- private object storage and signed delivery;
- auditability and safe operational logging;
- automated negative/security tests;
- OpenAPI discipline;
- GitHub CI and traceable engineering decisions.

## Deliberately out of scope

V1 does not include public sharing, anonymous uploads, archive extraction, remote-URL ingestion, document conversion, preview generation, collaborative folders, microservices, Kubernetes or AI features.

The goal is to make the core security boundary **small enough to understand and strong enough to test**.

## Roadmap

1. **Foundation** — architecture, threat model, API contract and Definition of Done. **✓**
2. **Application scaffold** — Laravel structure, local dependencies and quality tooling. **✓**
3. **Identity + ownership** — token auth and object-level authorization. **✓**
4. **Secure ingestion** — quarantine, file policy, MIME detection, hashing and duplicate handling. **✓**
5. **Scanning pipeline** — queue worker, scanner adapter and fail-closed state transitions. **← next**
6. **Controlled delivery** — clean storage, signed access and deletion behavior.
7. **Hardening** — audit events, rate limits, security tests and dependency/CI controls.
8. **V1 evidence** — OpenAPI, reproducible demo, final documentation and tagged release.

## Repository principle

This project will not claim production maturity before the implementation, tests and operational evidence support it. Documentation is treated as an engineering contract and changes with the code when accepted assumptions change.
