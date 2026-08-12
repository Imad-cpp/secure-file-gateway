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
      +--> Quarantine storage  <-- untrusted content boundary
                |
                v
          Scanner worker
                |
                v
          Clean storage
                |
      [ signed-delivery boundary ]
                |
                v
        Authorized client
```

Quarantine is explicitly considered hostile storage content even though the bucket itself is controlled by the application.

## Threats and controls

### 1. Malicious file content

**Threat:** an attacker uploads malware or a crafted file intended to harm users or downstream services.

**Controls:**

- quarantine by default;
- no direct access to quarantine objects;
- asynchronous malware scanning;
- `AVAILABLE` only after a clean result;
- scanner error never treated as clean;
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
- no filesystem path composition from user filename.

### 4. IDOR / broken object-level authorization

**Threat:** one authenticated user guesses another user's file identifier.

**Controls:**

- opaque identifiers;
- ownership authorization on every read/delete/download operation;
- database queries scoped to the authenticated actor unless a privileged policy explicitly allows otherwise;
- negative authorization tests required.

### 5. Public storage exposure

**Threat:** clean or quarantine objects become reachable without application authorization.

**Controls:**

- private buckets/zones;
- deny public ACL/policy by design;
- short-lived signed delivery only after authorization;
- storage configuration documented and tested.

### 6. Oversized uploads / storage exhaustion

**Threat:** attackers consume bandwidth, memory, queue capacity or object-storage space.

**Controls:**

- V1 maximum object size of 10 MiB;
- request/account rate limits;
- streaming-oriented handling where framework/storage integration permits;
- quotas considered before production-style deployment;
- rejected/quarantined object cleanup policy;
- queue back-pressure and monitoring.

### 7. Duplicate-detection oracle

**Threat:** global hash deduplication reveals that another user possesses a particular file.

**Controls:**

- duplicate responses are scoped to the authenticated owner only;
- no cross-user `already exists` disclosure;
- physical cross-user deduplication is out of scope for V1.

### 8. Race conditions in lifecycle state

**Threat:** concurrent workers or retries incorrectly make a file downloadable or perform contradictory transitions.

**Controls:**

- explicit allowed state transitions;
- idempotent scan jobs;
- transactional/locked transition logic where required;
- tests for retries and duplicate job execution;
- availability check at delivery time, not only at URL-generation request start.

### 9. Signed URL leakage or reuse

**Threat:** a valid signed URL is copied and reused by someone else during its lifetime.

**Controls:**

- short expiry;
- generated only after authorization;
- avoid logging the signed query string;
- sensitive environments may replace direct signed URLs with application-proxied delivery if stronger revocation is required.

V1 signed access is capability-based: possession of a still-valid URL grants access until expiry. This limitation must remain explicit.

### 10. Credential / token leakage

**Threat:** tokens or storage credentials appear in source code, logs or CI output.

**Controls:**

- no committed secrets;
- environment-based secret injection;
- masked CI secrets;
- minimum required GitHub Actions permissions;
- secret-like fixture values must be obviously synthetic;
- logging rules prohibit Authorization headers and signed URLs.

### 11. Abuse of scanner errors

**Threat:** attackers intentionally submit files that crash or time out the scanner and exploit fail-open behavior.

**Controls:**

- fail closed;
- bounded retries;
- `SCAN_FAILED` remains non-downloadable;
- operational alerts/metrics for repeated scanner failure;
- scanner time/resource limits considered in implementation.

### 12. Audit-log data leakage

**Threat:** security logging accidentally becomes a second store for sensitive file data.

**Controls:**

Audit events may contain:

- actor id;
- action;
- target id;
- outcome;
- correlation id;
- coarse safe metadata.

Audit events must not contain:

- file body/content;
- bearer tokens;
- storage credentials;
- signed URLs;
- raw Authorization headers;
- unnecessary original metadata.

## Authentication and authorization

V1 plans bearer-token authentication using Laravel Sanctum.

Authorization rules:

- unauthenticated actors cannot upload or inspect files;
- normal users can access only their own file resources;
- file state is never client-writable;
- privileged/admin behavior, if added, must be represented by an explicit policy rather than bypassing ownership checks ad hoc.

## Allowed file policy

Initial V1 policy:

| Extension | Detected MIME | Maximum size |
|---|---|---:|
| `.pdf` | `application/pdf` | 10 MiB |
| `.png` | `image/png` | 10 MiB |
| `.jpg`, `.jpeg` | `image/jpeg` | 10 MiB |
| `.txt` | `text/plain` | 10 MiB |

Adding a new content type requires a security review and tests.

## Secure defaults

- fail closed;
- private by default;
- deny by default authorization;
- generated object names;
- server-owned lifecycle states;
- least-privilege component permissions;
- short-lived access;
- bounded resource use;
- safe logs;
- explicit error states instead of silent fallback.

## Security testing required for V1

At minimum, automated tests must cover:

- unauthenticated upload denied;
- user A cannot read/delete/download user B's file;
- disallowed extension rejected;
- extension/MIME mismatch rejected;
- oversized file rejected;
- malicious scanner result never becomes available;
- scanner failure never becomes available;
- duplicate scan jobs remain safe/idempotent;
- download denied for every non-`AVAILABLE` state;
- expired/invalid signed access rejected by storage/application boundary;
- original filename cannot affect storage path;
- audit records exclude known sensitive fields.

## Vulnerability reporting

Before the project is presented as a maintained public security portfolio repository, it must include `SECURITY.md` with a private reporting route and supported-version policy.

## Non-goals

This project does not claim to make arbitrary files safe. Malware scanning reduces risk; it does not prove absence of malicious behavior. The system's security claim is narrower: **untrusted files are isolated and only released after the configured controls succeed.**
