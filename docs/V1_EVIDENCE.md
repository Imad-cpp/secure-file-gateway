# V1 Evidence Ledger

This document records what the public repository currently proves and what it deliberately does not claim yet.

## Machine-checked contract

- `openapi.yaml` is an OpenAPI 3.0.3 contract for every public V1 HTTP operation, the temporary signed-content capability route and health endpoints.
- `OpenApiContractTest` parses the specification through `league/openapi-psr7-validator` and compares documented methods/paths with the Laravel route table.
- Composer dependencies are committed in `composer.lock`; CI validates the manifest/lock pair strictly and installs the locked graph.
- Larastan/PHPStan runs at level 5 with no baseline.

## Security and hygiene evidence

The `Application Quality` workflow includes an independent full-history Gitleaks job.

Current evidence:

- repository history is scanned for secret-like material;
- GitHub Actions permissions remain read-only for the permanent quality workflow;
- checkout credentials are not persisted;
- reusable actions are pinned to immutable commit SHAs;
- Composer's resolved dependency graph is audited on every quality run.

A passing scanner is evidence that its configured rules found no matching secret in the scanned history. It is not a proof that arbitrary sensitive information can never exist.

## Real infrastructure evidence

The integration job starts the actual local V1 dependency topology in GitHub Actions:

```text
Laravel app
  ├── PostgreSQL
  ├── Redis
  ├── MinIO
  │   ├── private quarantine bucket
  │   └── private clean bucket
  └── ClamAV
```

The job:

1. builds the non-root PHP application image;
2. starts PostgreSQL, Redis, MinIO, bucket initialization, ClamAV and Laravel;
3. applies the real PostgreSQL migrations;
4. calls `/health/ready` until the application verifies PostgreSQL, Redis, both storage zones and ClamAV;
5. requires the aggregate response to become `{"status":"ready"}`;
6. tears down the disposable environment.

This proves that the dependency wiring and readiness probes operate against real local containers in CI rather than only mocks.

## What ordinary tests prove

The feature/unit suite covers, among other boundaries:

- authentication and owner isolation;
- upload policy, MIME mismatch and size rejection;
- private quarantine behavior and compensating cleanup;
- per-owner SHA-256 duplicate isolation;
- queue handoff behavior;
- clean / unsafe / scanner-error lifecycle handling through deterministic scanner adapters;
- signed-capability issuance, tamper/expiry denial and current-state revocation;
- private clean streaming;
- tombstone deletion and partial cleanup failure;
- request-correlation spoofing resistance;
- recursive audit metadata sanitization;
- fail-closed health response semantics;
- targeted deleted-object reconciliation.

## Explicitly not proven yet

The repository does **not** yet claim all of the following as machine-verified release evidence:

- a complete real-engine upload → ClamAV verdict → lifecycle transition → controlled download scenario;
- a harmless known-signature rejection scenario against real ClamAV in CI;
- production deployment maturity, production monitoring or incident-response maturity;
- a transactional/immutable forensic audit ledger;
- generic bucket-wide orphan discovery;
- production-grade availability or performance benchmarks.

These boundaries remain explicit so the portfolio does not inflate local/container evidence into production claims.

## Release-candidate gate

Before tagging public `v1.0.0`, the remaining evidence should include:

1. a deterministic real-ClamAV end-to-end check using a harmless antivirus test signature rather than real malware;
2. a reproducible documented demo flow from registration through upload/state polling and controlled delivery;
3. final changelog/release notes and a license decision;
4. a complete Definition-of-Done review against the exact release commit;
5. green post-merge CI on the release candidate.
