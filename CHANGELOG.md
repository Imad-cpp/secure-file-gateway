# Changelog

All notable changes to Secure File Gateway are documented in this file.

The project follows semantic versioning once tagged releases begin. Until `v1.0.0` is created, the release-candidate work remains under **Unreleased**.

## [Unreleased]

### V1 release candidate

The current release candidate establishes the first complete public portfolio boundary for the gateway. The tag is intentionally not created until the exact release commit passes the final Definition-of-Done audit and post-merge quality gate.

### Added

- Laravel 13 API with Sanctum bearer-token authentication and UUID-backed public identifiers.
- Owner-scoped file metadata reads, listings, deletion and download-capability issuance.
- Private quarantine and clean S3-compatible storage zones with server-generated object keys.
- V1 allowlist for PDF, PNG, JPEG and plain-text files with a 10 MiB limit.
- Server-side MIME inspection, SHA-256 integrity metadata and owner-scoped duplicate protection.
- Redis-backed asynchronous scan jobs and a ClamAV `INSTREAM` infrastructure adapter.
- Fail-closed lifecycle states: `QUARANTINED`, `SCANNING`, `AVAILABLE`, `REJECTED`, `SCAN_FAILED` and `DELETED`.
- Short-lived signed application capabilities for private clean-content delivery.
- Server-generated request correlation IDs and bounded, sanitized security audit events.
- Concrete readiness checks for PostgreSQL, Redis, both private storage zones and ClamAV.
- Targeted deleted-object reconciliation through `files:reconcile-deleted`.
- OpenAPI 3.0.3 contract with route-drift validation.
- Reproducible Docker Compose development topology using PostgreSQL, Redis, MinIO and ClamAV.
- Executable synthetic V1 demo covering registration → login → upload → lifecycle polling → signed delivery → byte verification → deletion.
- Real-container CI evidence for both a clean application path and runtime-generated EICAR detection through the normal upload/worker/scanner boundary.
- Full-history secret scanning, locked dependency installation, static analysis, formatting checks, automated tests and dependency audit in GitHub Actions.
- Public security policy, architecture, security model, API map, engineering decisions, Definition of Done and evidence ledger.

### Security

- Original filenames never become storage keys.
- Quarantine and clean storage remain private.
- Scanner errors fail closed and never promote content to `AVAILABLE`.
- Signed content URLs expire and re-check current file state before streaming bytes.
- Deletion revokes previously issued capabilities by moving the resource out of `AVAILABLE` before storage cleanup.
- Cross-user duplicate disclosure is avoided by scoping duplicate checks to the owner.
- Audit metadata excludes credentials, bearer tokens, signatures, signed URLs, file bodies and private object keys.
- Permanent GitHub Actions permissions remain read-only and reusable actions are pinned to immutable commit SHAs.

### Evidence boundary

The V1 evidence demonstrates the documented behavior against the repository's configured local/container integration environment. It does **not** claim production-readiness, production availability, malware-detection completeness, arbitrary-file safety, production monitoring maturity, incident-response maturity, a transactional forensic audit ledger or generic bucket-wide orphan discovery.

### License

- Project source is licensed under the MIT License. See `LICENSE`.
