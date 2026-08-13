# Security Model

## Security objective

Treat every uploaded file and every client-supplied metadata field as untrusted until the server has validated it.

The central invariant is:

> **No untrusted object is user-downloadable before it passes the complete validation and scanning pipeline.**

A second delivery invariant is:

> **A previously approved object is served only while its current lifecycle state remains `AVAILABLE` and the presented temporary capability is valid.**

## Assets to protect

- file contents;
- user ownership boundaries;
- authentication tokens;
- object-storage credentials and keys;
- database records and audit history;
- application availability;
- scanner and queue infrastructure;
- signed download capabilities.

## Trust boundaries

```text
Untrusted client
      |
      v
[ authenticated API boundary ]
      |
      +--> PostgreSQL
      +--> Redis
      +--> Quarantine storage  <-- hostile-content boundary
                |
                v
          Scan worker
                |
      [ internal scanner boundary ]
                |
                v
             ClamAV
                |
                v
          Clean storage
                ^
                |
      [ signed-capability boundary ]
                |
                v
        Capability holder
```

Quarantine is explicitly hostile-content storage even though the bucket itself is controlled by the application. ClamAV is an internal dependency, not a public API boundary. A signed content URL is a temporary bearer capability after owner-authorized issuance; possession of it is sufficient until expiry unless lifecycle state changes first.

## Threats and controls

### 1. Malicious file content

**Threat:** an attacker uploads malware or a crafted file intended to harm users or downstream services.

**Controls:**

- private quarantine by default;
- no direct access to quarantine objects;
- asynchronous ClamAV scanning behind an adapter;
- `AVAILABLE` only after a clean result and clean-storage promotion;
- unsafe result becomes `REJECTED`;
- scanner/job failure becomes `SCAN_FAILED` and remains non-downloadable;
- no preview/conversion pipeline in V1.

### 2. Extension and MIME spoofing

**Threat:** a file is named like an allowed format while containing another type.

**Controls:**

- extension allowlist;
- server-side MIME detection;
- explicit extension/MIME mapping;
- mismatch rejection;
- original filename never determines storage behavior.

### 3. Path traversal / object-key manipulation

**Threat:** a client supplies names such as `../../target` or crafted object keys.

**Controls:**

- generated storage names only;
- original name stored as display metadata;
- client never supplies quarantine or clean object keys;
- no filesystem path composition from user filename;
- ClamAV receives streamed bytes through `INSTREAM`, not a client-derived path;
- download filenames are reduced to a basename and control characters are removed before response disposition.

### 4. IDOR / broken object-level authorization

**Threat:** one authenticated user guesses another user's file identifier.

**Controls:**

- UUID identifiers;
- ownership authorization on metadata reads, delete operations and download-capability issuance;
- database queries scoped to the authenticated actor where applicable;
- foreign-owned file identifiers return `404` to reduce enumeration leakage;
- negative authorization tests.

The signed content route is different: after capability issuance it relies on a valid temporary signature, expiry and current lifecycle state rather than the original user's bearer token.

### 5. Public storage exposure

**Threat:** clean or quarantine objects become reachable without application control.

**Controls:**

- private storage zones;
- deny public ACL/policy by design;
- controlled delivery uses an application-signed route rather than making an object public;
- quarantine and clean object keys are not returned by normal APIs;
- content is streamed only from private clean storage after signature and state checks.

### 6. Oversized uploads / storage exhaustion

**Threat:** attackers consume bandwidth, memory, queue capacity or object-storage space.

**Controls:**

- V1 maximum object size of 10 MiB;
- independent authentication, upload, capability-issuance and signed-content rate limits;
- streaming-oriented storage/scanner/delivery handling;
- bounded scan retries and timeouts;
- rejected/deleted object cleanup semantics;
- quotas/back-pressure remain deployment hardening concerns.

### 7. Duplicate-detection oracle

**Threat:** global hash deduplication reveals that another user possesses a particular file.

**Controls:**

- duplicate responses are scoped to the authenticated owner only;
- database uniqueness is owner + active SHA-256;
- identical bytes may exist for different owners;
- no cross-user `already exists` disclosure;
- deletion clears active SHA-256 so a later same-owner re-upload is possible without deleting historical evidence stored internally as `deleted_sha256`.

### 8. Race conditions in lifecycle state

**Threat:** concurrent scanning, delivery or deletion incorrectly makes a file downloadable or performs contradictory transitions.

**Controls:**

- server-owned states;
- conditional `QUARANTINED -> SCANNING` claim;
- per-file `WithoutOverlapping` scan middleware;
- terminal states are not rescanned;
- clean object must exist before `AVAILABLE` transition;
- clean-promotion metadata failure removes the newly created clean object on the normal compensation path;
- delivery checks current `AVAILABLE` again when the signed URL is used;
- deletion commits `DELETED` under a row lock before private object cleanup;
- scan terminal updates remain conditional on their expected processing state;
- a scan that loses a deletion race cannot publish `AVAILABLE` and compensates created clean storage on the normal failure path.

### 9. Scanner protocol exposure / abuse

**Threat:** the malware scanner is exposed as an unauthenticated network service or receives attacker-controlled filesystem paths.

**Controls:**

- local Compose does not publish ClamAV TCP/3310 to the host;
- only the internal scan worker connects to `clamd`;
- scanning uses `INSTREAM` bytes, not path-based commands;
- empty, unknown, error and timeout responses fail closed;
- scanner engine/signature details remain internal metadata.

### 10. Scanner errors and resource abuse

**Threat:** crafted input or dependency outages crash/time out the scanner and exploit fail-open behavior.

**Controls:**

- fail closed;
- 3 job attempts;
- 5s then 30s backoff;
- 30s job timeout below the 90s Redis retry-after horizon;
- final failure -> `SCAN_FAILED`;
- failed jobs are recorded for operations;
- scanner failure never creates a usable download capability.

### 11. Queue handoff failure

**Threat:** metadata is persisted but scan work is never queued, leaving a file permanently stranded in processing.

**Controls:**

- scan dispatch is part of the ingestion success path;
- dispatch failure returns `DEPENDENCY_UNAVAILABLE`;
- the normal compensation path removes the new metadata row and quarantine object;
- if metadata compensation itself fails, quarantine is preserved and the row is best-effort moved to `SCAN_FAILED` rather than deleting referenced bytes;
- a transactional outbox remains a potential hardening improvement rather than an unclaimed guarantee.

### 12. Signed capability leakage or reuse

**Threat:** a valid signed content URL is copied, logged or shared and reused by someone other than the owner during its lifetime.

**Controls:**

- capability issuance itself requires authentication, ownership and current `AVAILABLE`;
- default expiry is 300 seconds;
- signed middleware rejects tampered and expired URLs;
- current `AVAILABLE` is checked again at use time;
- deletion moves the resource to `DELETED`, revoking already-issued URLs before storage cleanup completes;
- capability responses and content use private/no-store semantics;
- policy prohibits logging signed query strings;
- default issuance throttle is 20/minute per owner + IP;
- default content throttle is 60/minute per file + IP.

**Known limitation:** the URL is intentionally a bearer capability after issuance. Anyone possessing a still-valid URL can use it until expiry unless lifecycle state changes. It is not a public sharing feature and provides no per-recipient identity binding.

### 13. Signed capability tampering / expiry bypass

**Threat:** an attacker modifies file identifiers, expiry values or signatures to access another object or extend capability lifetime.

**Controls:**

- the complete signed route parameters are covered by the application signature;
- invalid or expired signatures are rejected with `403` before content handling;
- tests cover signature tampering and expiry;
- the content handler still requires current `AVAILABLE`.

### 14. Deletion partial failure

**Threat:** database and object-storage operations fail at different points, leaving content still downloadable or impossible to reconcile.

**Controls:**

- metadata becomes `DELETED` before object cleanup;
- delivery requires `AVAILABLE`, so content access fails closed immediately after the tombstone commits;
- unresolved quarantine/clean keys remain stored if object deletion fails, enabling retry;
- object keys are cleared only after successful storage deletion;
- repeated DELETE is idempotent;
- storage cleanup failure returns `503 DEPENDENCY_UNAVAILABLE` instead of pretending deletion completed operationally.

**Known boundary:** PostgreSQL and object storage do not share a transaction. Reconciliation automation remains hardening work.

### 15. Credential / token leakage

**Threat:** tokens, signed capabilities or storage credentials appear in source code, logs or CI output.

**Controls:**

- no committed production secrets;
- environment-based secret injection;
- minimum GitHub Actions permissions;
- checkout credentials not persisted in CI;
- secret-like fixtures are synthetic;
- logging rules prohibit Authorization headers and signed URLs.

### 16. Audit-log data leakage

**Threat:** security logging becomes a second store for sensitive file data.

Audit events may contain actor, action, target, outcome, correlation ID and bounded safe metadata.

Audit events must not contain file bodies, bearer tokens, storage credentials, signed URLs, raw Authorization headers or unnecessary original metadata.

## Authentication and authorization

V1 implements bearer-token authentication using Laravel Sanctum for normal authenticated API operations.

Authorization rules:

- unauthenticated actors cannot upload, list, inspect, delete or issue download capabilities;
- normal users can manage only their own file resources;
- file state is never arbitrarily client-writable;
- a valid signed content URL is the temporary authorization capability for content access after issuance;
- privileged/admin behavior, if added, must use explicit policy rather than bypass ownership checks ad hoc.

## Allowed file policy

| Extension | Detected MIME | Maximum size |
|---|---|---:|
| `.pdf` | `application/pdf` | 10 MiB |
| `.png` | `image/png` | 10 MiB |
| `.jpg`, `.jpeg` | `image/jpeg` | 10 MiB |
| `.txt` | `text/plain` | 10 MiB |

Adding a new content type requires security review and tests.

## Secure defaults

- fail closed;
- private by default;
- deny by default authorization;
- generated object names;
- server-owned lifecycle states;
- internal-only scanner endpoint;
- short-lived signed capabilities;
- current-state revalidation at content access;
- deletion revocation before storage cleanup;
- bounded resource use;
- safe logs;
- explicit error states instead of silent fallback.

## Security testing required for V1

Automated coverage must include, by V1 completion:

- unauthenticated upload denied;
- user A cannot read/delete/issue download access for user B's file;
- disallowed extension rejected;
- extension/MIME mismatch rejected;
- oversized file rejected;
- malicious scanner result never becomes available;
- scanner failure never becomes available;
- terminal/retried scan execution remains safe;
- queue handoff failure does not leave normal-path orphan metadata/storage;
- download capability denied for every non-`AVAILABLE` state;
- invalid and expired signed access rejected;
- valid signed access streams only private clean content;
- deletion revokes an already-issued capability;
- deletion is idempotent and removes referenced clean/quarantine objects on the normal path;
- foreign-owner deletion cannot alter metadata or storage;
- deleted records release active duplicate hashes for same-owner re-upload;
- original filename cannot affect storage path;
- audit records exclude known sensitive fields.

Current CI covers the implemented identity, ingestion, scanning, controlled-delivery and deletion layers with deterministic scanner test doubles plus ClamAV protocol reply parsing. A real ClamAV service exists in local Compose, but CI does not yet claim an end-to-end real-engine scan test. Audit-event tests remain pending because the audit layer is not implemented yet.

## Vulnerability reporting

Before the project is presented as a maintained V1 security portfolio release, it must include `SECURITY.md` with a private reporting route and supported-version policy.

## Non-goals

This project does not claim to make arbitrary files safe. Malware scanning reduces risk; it does not prove absence of malicious behavior. The system's security claim is narrower: **untrusted files are isolated and only released after the configured controls succeed, and approved files remain releasable only while their current lifecycle state permits it.**
