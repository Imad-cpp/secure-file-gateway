# V1 Release Audit

**V1 release audit: PASS**

This document records the Definition-of-Done review for the first public portfolio release candidate of Secure File Gateway.

The verdict above is **not self-proving**. It is valid only for a commit whose permanent `Application Quality` workflow completes all prerequisite jobs and the dependent `release-audit` job successfully. The `v1.0.0` tag must target that exact verified `main` commit.

## Audit scope

The audit evaluates the repository against `docs/DEFINITION_OF_DONE.md` and the evidence implemented in code, tests, documentation and the permanent CI workflow.

| Area | Verdict | Evidence |
|---|---|---|
| Functional V1 boundary | PASS | Auth, owner-scoped metadata, secure ingestion, asynchronous scanning, controlled delivery, deletion, audit, readiness and targeted reconciliation are implemented and covered by automated tests. |
| Authorization | PASS | Feature tests cover unauthenticated access, owner scoping, cross-owner denial and download/deletion authorization. |
| File security | PASS | Allowlist/size/MIME checks, private quarantine, server-generated keys, SHA-256, fail-closed scanning, signed delivery and deletion revocation are covered by unit/feature/integration evidence. |
| Lifecycle rules | PASS | `FileLifecyclePolicy` makes the documented transition/gating rules explicit and unit-tested; feature tests cover actual scan/deletion behavior. |
| File-policy mapping | PASS | `FileIngestionPolicy` is production-used and unit-tested for the V1 10 MiB limit and PDF/PNG/JPEG/TXT extension-to-MIME allowlist. |
| Duplicate privacy | PASS | Production duplicate queries are explicitly owner + SHA-256 scoped; unit and feature tests cover same-owner rejection and cross-owner isolation. |
| Object naming | PASS | `StorageObjectKey` requires server-owned UUID identifiers and is used for both quarantine and clean object paths. |
| Scanner result mapping | PASS | ClamAV reply parsing and `MalwareScanResult` normalization are unit-tested; the real-container EICAR path proves scanner participation through the application boundary. |
| Audit sanitization | PASS | `AuditMetadataSanitizer` is production-used and unit/feature-tested for recursive sensitive-key removal and bounded metadata. |
| CORS default | PASS | `config/cors.php` grants no origins by default; a negative feature test verifies an untrusted origin receives no CORS access headers. |
| API contract | PASS | `openapi.yaml` is parsed and compared with the Laravel route table in CI. |
| Dependency reproducibility | PASS | `composer.lock` is committed, strictly validated and installed in CI. |
| Static/code quality | PASS | Pint and Larastan/PHPStan level 5 run without a baseline in the permanent quality gate. |
| Dependency security | PASS | Composer audit runs on the resolved dependency graph. |
| Secret hygiene | PASS | A separate full-history Gitleaks job runs with read-only workflow permissions. |
| Supply-chain CI hygiene | PASS | Permanent reusable actions are pinned to full commit SHAs and checkout credentials are not persisted. |
| Real infrastructure | PASS | CI boots the non-root Laravel app with PostgreSQL, Redis, MinIO and ClamAV, runs migrations and requires aggregate readiness. |
| Real scanner lifecycle | PASS | CI exercises both a clean file and a runtime-generated EICAR antivirus fixture through HTTP upload, Redis worker, ClamAV `INSTREAM`, lifecycle transitions and controlled delivery denial/success as appropriate. |
| Reproducible public demo | PASS | `scripts/demo-v1.sh` is documented and executed by CI against the same real-container stack. |
| Release artifacts | PASS | MIT `LICENSE`, `CHANGELOG.md`, prepared `docs/releases/v1.0.0.md`, security policy, evidence ledger and project documentation are present. |

## Definition-of-Done evidence layers

### Unit layer

Focused unit tests cover:

- lifecycle transition and download/scan gates;
- file extension/MIME/size policy;
- scanner-result normalization and ClamAV reply parsing;
- server-generated UUID object-key naming;
- owner + digest duplicate criteria;
- recursive security-audit metadata sanitization.

### Feature/API layer

Feature tests cover authentication, ownership, ingestion rejection/acceptance, queue handoff compensation, scan lifecycle behavior, signed delivery, expiry/tamper denial, deletion/revocation, audit correlation, readiness, reconciliation, OpenAPI drift and CORS deny-by-default behavior.

### Real-container layer

The `infrastructure-integration` job proves the configured application can operate with real PostgreSQL, Redis, MinIO and ClamAV containers. It applies migrations, verifies readiness, runs the Redis-backed scan worker, executes the clean + EICAR application paths and then runs the reproducible public demo.

### Release gate layer

The permanent `release-audit` job depends on all three substantive jobs:

```text
php-quality ───────────────┐
secret-hygiene ────────────┼──→ release-audit
infrastructure-integration ┘
```

It then checks the required V1 artifacts and release boundaries on the same repository snapshot and emits:

```text
V1_RELEASE_AUDIT=PASS
```

This makes the release verdict commit-specific instead of relying on a manual checklist detached from CI.

## Explicit evidence boundaries

**Production readiness is not claimed.**

V1 also does not claim:

- production availability guarantees or production performance benchmarks;
- malware-detection completeness or arbitrary-file safety;
- production monitoring or incident-response maturity;
- a transactional or immutable forensic audit ledger;
- generic bucket-wide orphan discovery.

The repository demonstrates a deliberately bounded security engineering system and its reproducible evidence, not certification of a production service.

## Tagging rule

`v1.0.0` may be created only from the exact `main` commit for which the permanent `Application Quality` workflow reports success for:

1. `php-quality`;
2. `secret-hygiene`;
3. `infrastructure-integration`;
4. dependent `release-audit`.

Until that post-merge result exists, `CHANGELOG.md` remains under `Unreleased` and `docs/releases/v1.0.0.md` remains prepared release-note source material.
