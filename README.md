# Secure File Gateway

Security-focused file upload and delivery API built around **validation, quarantine, asynchronous malware scanning, auditability and controlled access**.

> **Status:** Scanning pipeline implemented. Authenticated uploads are validated, isolated in private quarantine, queued for ClamAV scanning and advanced through fail-closed lifecycle states. Clean files are promoted into private clean storage. Signed delivery, deletion lifecycle, audit hardening and the final OpenAPI/release evidence are still in progress; no production-readiness claim is made.

## Why this project exists

File upload looks simple until the input has to be treated as hostile.

This project demonstrates the engineering boundary between **receiving an untrusted object** and **releasing a controlled clean object**. It focuses on ownership, MIME spoofing, storage-path safety, quarantine, malware-scanner failure, lifecycle races, private storage, signed access, safe logging and testable failure semantics.

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
SCANNING
  ┌──────────────┼──────────────┐
  ↓              ↓              ↓
clean          unsafe          error
  ↓              ↓              ↓
Private clean  REJECTED     retry → SCAN_FAILED
storage
  ↓
AVAILABLE
  ↓
Authorized short-lived access   ← next layer
```

The flow is now implemented through `AVAILABLE`, `REJECTED` and `SCAN_FAILED`. Controlled download capability and deletion are the next lifecycle layer.

## Security invariants

- Every upload is untrusted by default.
- Quarantine objects are never directly downloadable by users.
- Client-provided filenames and MIME values do not control storage behavior.
- Original filenames are display metadata only; object names are server-generated.
- A file becomes `AVAILABLE` only after validation and a clean scanner result.
- Scanner failure **fails closed**.
- Unsafe files never enter clean storage.
- Every file read/delete/download operation is ownership-authorized.
- Clean storage remains private; future access is short-lived and explicitly authorized.
- Duplicate detection is scoped to the same owner so it does not become a cross-user presence oracle.
- Audit/logging must not contain file bodies, bearer tokens, credentials or signed URLs.

## V1 stack

`Laravel 13` · `PHP 8.3+` · `PostgreSQL` · `Redis` · `S3-compatible private storage` · `Laravel Sanctum` · `ClamAV` · `Docker Compose` · `OpenAPI`

The application currently wires bearer-token authentication, UUID-backed ownership metadata, private quarantine/clean storage zones, Redis-backed scan jobs and a ClamAV scanner adapter.

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
- Metadata responses never expose owner IDs, quarantine keys, clean-storage keys or internal scanner signatures.

## Secure ingestion

`POST /api/v1/files` implements the untrusted-file boundary through quarantine and queue handoff.

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
Scan job dispatch
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
- New quarantine objects are removed when MIME verification, duplicate checks or metadata persistence reject the upload.
- If scan-job dispatch fails, the implementation compensates by removing the new metadata row and quarantine object when possible. If metadata compensation itself fails, the private quarantine object is preserved rather than creating a metadata pointer to deleted bytes.
- Upload creation has an independent default limit of 10 requests per minute per authenticated owner + IP.

## Malware scanning pipeline

A successful ingestion dispatches `ScanStoredFile` to the dedicated `scans` queue.

```text
QUARANTINED
    ↓
SCANNING
    ↓
ClamAV adapter / INSTREAM
    ├── CLEAN  → copy to private clean storage → AVAILABLE
    ├── FOUND  → REJECTED
    └── error  → retry → final SCAN_FAILED
```

Security/reliability behavior:

- Domain code depends on a `MalwareScanner` interface; ClamAV is the current infrastructure adapter.
- The adapter streams quarantine bytes with ClamAV `INSTREAM`; it does not ask `clamd` to open a user-controlled path.
- Local Docker Compose does **not** publish the ClamAV TCP port to the host. Only the internal worker network reaches it.
- A scan job has 3 attempts, backoff of 5 then 30 seconds, a 30-second job timeout and per-file overlap protection.
- Clean bytes are copied into private clean storage **before** the database state becomes `AVAILABLE`.
- If clean promotion fails after creating the clean object, the new clean object is removed on the normal compensation path and the job fails closed.
- Unsafe results become `REJECTED`; no clean object is created.
- Final scanner/job failure becomes `SCAN_FAILED`; the quarantine object remains private for later operational reconciliation.
- Terminal states are not scanned again.
- Scanner engine/signature metadata stays internal and is not returned by normal file APIs.

The CI suite uses deterministic scanner test doubles for lifecycle tests and a unit-tested ClamAV reply parser. Docker Compose provides the real ClamAV service for local integration; a dedicated real-engine integration test is not yet claimed by CI.

## Local development

Docker Compose provides:

- Laravel API;
- dedicated `scans` queue worker;
- PostgreSQL;
- Redis;
- MinIO with private `sfg-quarantine` and `sfg-clean` buckets;
- ClamAV on the internal Compose network.

```bash
cp .env.example .env
docker compose up --build
```

The API is available at `http://localhost:8000` and the MinIO console at `http://localhost:9001`. ClamAV intentionally has no host port mapping.

The credentials in `.env.example` are deliberately local-only examples. They must never be reused in shared, staging or production environments.

Useful checks:

```bash
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app php artisan test
```

## Health semantics

- `GET /health/live` returns `200` when Laravel can boot and serve a request.
- `GET /health/ready` currently returns `503` deliberately. Readiness will turn healthy only after concrete PostgreSQL, Redis, object-storage and scanner dependency probes exist.

This keeps the application fail-closed instead of pretending dependencies are ready before they are actually verified.

## Initial V1 file policy

| Type | Allowed extension | Server-detected MIME | Maximum size |
|---|---|---|---:|
| PDF | `.pdf` | `application/pdf` | 10 MiB |
| PNG | `.png` | `image/png` | 10 MiB |
| JPEG | `.jpg`, `.jpeg` | `image/jpeg` | 10 MiB |
| Plain text | `.txt` | `text/plain` | 10 MiB |

The allowlist starts small on purpose. Adding content types expands attack surface and therefore requires explicit review and tests.

## Lifecycle

```text
QUARANTINED → SCANNING → AVAILABLE
                  ├────→ REJECTED
                  └────→ SCAN_FAILED

AVAILABLE → DELETED   ← delivery/deletion layer next
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

# Planned next layer
DELETE /api/v1/files/{file}
POST   /api/v1/files/{file}/download

GET    /health/live
GET    /health/ready
```

Uploads return `202 Accepted`. Clients poll the owned file resource as asynchronous scanning advances its server-controlled state.

## Quality gate

Pull requests and pushes to `main` run the `Application Quality` workflow. The gate validates the Composer manifest, installs dependencies, boots the application, enforces Pint formatting, runs the complete feature/unit test suite and audits resolved Composer dependencies.

Coverage includes identity/ownership controls, ingestion policy, MIME mismatch rejection, quarantine cleanup, SHA-256 duplicate isolation, upload throttling, queue handoff compensation, scan-job dispatch, clean promotion, unsafe rejection, fail-closed scanner errors, terminal-state idempotency and ClamAV reply parsing.

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
5. **Scanning pipeline** — queue worker, scanner adapter and fail-closed state transitions. **✓**
6. **Controlled delivery** — signed access and deletion behavior. **← next**
7. **Hardening** — audit events, rate limits, health/readiness probes and dependency/CI controls.
8. **V1 evidence** — OpenAPI, reproducible demo, final documentation and tagged release.

## Repository principle

This project will not claim production maturity before the implementation, tests and operational evidence support it. Documentation is treated as an engineering contract and changes with the code when accepted assumptions change.
