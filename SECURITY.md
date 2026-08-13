# Security Policy

## Supported versions

Secure File Gateway is still in pre-V1 development and is not presented as a production service.

Security fixes are applied to the current `main` development line. There is no supported historical release branch or production-support SLA yet. A versioned support table will be added when the first tagged V1 release exists.

## Report a vulnerability privately

Please do **not** open a public GitHub issue for a suspected security vulnerability.

Send the initial report to **Imadeddine.essebaiy@gmail.com** with the subject:

`[secure-file-gateway security] <short summary>`

The first message should contain only the information needed to understand and reproduce the issue safely:

- affected commit/tag if known;
- vulnerability class and expected impact;
- minimal reproduction steps;
- sanitized request/response details;
- a request ID (`X-Request-ID`) when it helps correlate behavior;
- suggested remediation if you have one.

Do not include bearer tokens, passwords, storage credentials, signed download URLs, real personal data or file bodies in the initial report. If sensitive material is genuinely required to reproduce the issue, coordinate a safer transfer method first.

## What is useful to report

Examples include:

- authentication or object-level authorization bypass;
- ability to obtain a download capability for another user's file;
- signed-capability validation, expiry or lifecycle-revocation bypass;
- access to quarantine or clean objects outside the intended application boundary;
- upload-policy or MIME-validation bypass;
- scanner fail-open behavior;
- lifecycle races that can publish unsafe or deleted content;
- secret, token, signed-URL, object-key or sensitive audit/log disclosure;
- a readiness or error response that exposes sensitive infrastructure details.

## Responsible testing

Please use accounts, files and infrastructure you control. Do not access another user's data, degrade shared services, perform destructive testing against third-party systems or publish exploitable details before a fix can be evaluated.

The repository intentionally uses synthetic/local-development data and does not claim a public production deployment.

## Handling and disclosure

Reports are reviewed on a best-effort basis during pre-V1 development. There is no guaranteed response or remediation SLA yet.

When a report is confirmed, the goal is to:

1. reproduce the issue with the smallest safe test case;
2. define the affected security invariant;
3. add a regression/negative test where practical;
4. implement and review the fix;
5. update affected security documentation;
6. coordinate disclosure after the fix is available.

Credit can be included in release notes when requested and appropriate.

## Security boundaries to keep in mind

- Malware scanning reduces risk; it does not prove arbitrary content is safe.
- Signed content URLs are short-lived bearer capabilities after owner-authorized issuance.
- PostgreSQL and object storage do not share one transaction; the design uses fail-closed lifecycle state, compensating cleanup and targeted reconciliation.
- Audit persistence is intentionally best-effort in V1 and is not a transactional forensic ledger.
- Production infrastructure, operational monitoring and production incident response are outside the current repository's claims.
