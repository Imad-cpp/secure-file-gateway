# Security Model

## Security objective

Treat every uploaded file and every client-supplied metadata field as untrusted until the server has validated it.

The central invariant is:

> **No untrusted object is user-downloadable before it passes the complete validation and scanning pipeline.**

## Assets to protect

- file contents;
- user ownership boundaries;
- authentication tokens;
- object-storage credentials and keys;
- database records and audit history;
- application availability;
- scanner and queue infrastructure;
- signed download capability.

## Trust boundaries

```text
Untrusted client
      |
      v
[ API boundary ]
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
                |
      [ signed-delivery boundary ]
                |
                v
        Authorized client
```

Quarantine is explicitly hostile-content storage even though the bucket itself is controlled by the application. ClamAV is an internal dependency, not a public API boundary.

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
- ClamAV receives streamed bytes through `INSTREAM`, not a client-derived path.

### 4. IDOR / broken object-level authorization

**Threat:** one authenticated user guesses another user's file identifier.

**Controls:**

- UUID identifiers;
- ownership authorization on file reads and future delete/download operations;
- database queries scoped to the authenticated actor;
- foreign-owned file identifiers return `404` to reduce enumeration leakage;
- negative authorization tests.

### 5. Public storage exposure

**Threat:** clean or quarantine objects become reachable without application authorization.

**Controls:**

- private storage zones;
- deny public ACL/policy by design;
- future short-lived signed delivery only after authorization;
- quarantine and clean object keys are not returned by normal APIs.

### 6. Oversized uploads / storage exhaustion

**Threat:** attackers consume bandwidth, memory, queue capacity or object-storage space.

**Controls:**

- V1 maximum object size of 10 MiB;
- independent authentication/upload rate limits;
- streaming-oriented storage/scanner handling;
- bounded scan retries and timeouts;
- rejected/quarantined object cleanup semantics;
- quotas/back-pressure remain deployment hardening concerns.

### 7. Duplicate-detection oracle

**Threat:** global hash deduplication reveals that another user possesses a particular file.

**Controls:**

- duplicate responses are scoped to the authenticated owner only;
- database uniqueness is owner + SHA-256;
- identical bytes may exist for different owners;
- no cross-user `already exists` disclosure.

### 8. Race conditions in lifecycle state

**Threat:** concurrent workers or retries incorrectly make a file downloadable or perform contradictory transitions.

**Controls:**

- server-owned states;
- conditional `QUARANTINED -> SCANNING` claim;
- per-file `WithoutOverlapping` queue middleware;
- terminal states are not rescanned;
- clean object must exist before `AVAILABLE` transition;
- clean-promotion metadata failure removes the newly created clean object on the normal compensation path;
- availability is checked again at future delivery time.

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
- scanner failure never creates clean storage or download capability.

### 11. Queue handoff failure

**Threat:** metadata is persisted but scan work is never queued, leaving a file permanently stranded in processing.

**Controls:**

- scan dispatch is part of the ingestion success path;
- dispatch failure returns `DEPENDENCY_UNAVAILABLE`;
- the normal compensation path removes the new metadata row and quarantine object;
- if metadata compensation itself fails, quarantine is preserved and the row is best-effort moved to `SCAN_FAILED` rather than deleting referenced bytes;
- a transactional outbox remains a potential hardening improvement rather than an unclaimed guarantee.

### 12. Signed URL leakage or reuse

**Threat:** a future valid signed URL is copied and reused by someone else during its lifetime.

**Controls planned for delivery:**

- short expiry;
- generated only after authorization and `AVAILABLE` check;
- avoid logging signed query strings;
- stronger application-proxied delivery can replace direct capabilities if revocation requirements demand it.

### 13. Credential / token leakage

**Threat:** tokens or storage credentials appear in source code, logs or CI output.

**Controls:**

- no committed production secrets;
- environment-based secret injection;
- minimum GitHub Actions permissions;
- checkout credentials not persisted in CI;
- secret-like fixtures are synthetic;
- logging rules prohibit Authorization headers and signed URLs.

### 14. Audit-log data leakage

**Threat:** security logging becomes a second store for sensitive file data.

Audit events may contain actor, action, target, outcome, correlation ID and bounded safe metadata.

Audit events must not contain file bodies, bearer tokens, storage credentials, signed URLs, raw Authorization headers or unnecessary original metadata.

## Authentication and authorization

V1 implements bearer-token authentication using Laravel Sanctum.

Authorization rules:

- unauthenticated actors cannot upload or inspect files;
- normal users can access only their own file resources;
- file state is never client-writable;
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
- least-privilege component permissions;
- short-lived future access;
- bounded resource use;
- safe logs;
- explicit error states instead of silent fallback.

## Security testing required for V1

Automated coverage must include, by V1 completion:

- unauthenticated upload denied;
- user A cannot read/delete/download user B's file;
- disallowed extension rejected;
- extension/MIME mismatch rejected;
- oversized file rejected;
- malicious scanner result never becomes available;
- scanner failure never becomes available;
- terminal/retried scan execution remains safe;
- queue handoff failure does not leave normal-path orphan metadata/storage;
- download denied for every non-`AVAILABLE` state;
- expired/invalid signed access rejected by storage/application boundary;
- original filename cannot affect storage path;
- audit records exclude known sensitive fields.

Current CI covers the implemented identity, ingestion and scanning layers with deterministic scanner test doubles plus ClamAV protocol reply parsing. A real ClamAV service exists in local Compose, but CI does not yet claim an end-to-end real-engine scan test.

## Vulnerability reporting

Before the project is presented as a maintained V1 security portfolio release, it must include `SECURITY.md` with a private reporting route and supported-version policy.

## Non-goals

This project does not claim to make arbitrary files safe. Malware scanning reduces risk; it does not prove absence of malicious behavior. The system's security claim is narrower: **untrusted files are isolated and only released after the configured controls succeed.**
