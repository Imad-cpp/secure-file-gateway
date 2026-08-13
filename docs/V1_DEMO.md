# Reproducible V1 Demo

This demo exercises the public clean-file journey through the running Secure File Gateway application.

It is intentionally separate from the EICAR security integration test. The demo uses only synthetic text content and exists to make the normal V1 user journey easy to reproduce and review.

## What the demo proves

The script performs the following public API flow:

```text
register synthetic user
        ↓
login
        ↓
receive bearer token
        ↓
upload synthetic text
        ↓
QUARANTINED
        ↓
poll owned file metadata
        ↓
AVAILABLE
        ↓
issue temporary signed capability
        ↓
download private clean content
        ↓
verify downloaded bytes == uploaded bytes
        ↓
delete owned file
        ↓
204 No Content
```

The script does not print the bearer token or signed URL.

A passing demo is evidence that this documented public journey works against the configured environment. It is not a production-readiness, availability or malware-detection-completeness claim.

## Prerequisites

Required host commands:

- Docker with Compose support;
- `curl`;
- `python3`;
- `cmp`.

The repository's `.env.example` contains local-only development values. Do not reuse those credentials in shared, staging or production environments.

## Start the local environment

From the repository root:

```bash
cp .env.example .env
docker compose up -d --build postgres redis minio minio-init clamav app
```

Apply the real PostgreSQL migrations:

```bash
docker compose exec -T app php artisan migrate --force
```

Wait for aggregate readiness:

```bash
curl --fail --silent --show-error http://127.0.0.1:8000/health/ready
```

Expected public response once the dependency boundary is ready:

```json
{"status":"ready"}
```

Start the dedicated scan worker:

```bash
docker compose up -d worker
```

## Run the demo

```bash
bash scripts/demo-v1.sh
```

The script creates a unique synthetic `example.test` account by default, so repeated runs against the same local database do not reuse one identity.

Expected progress shape:

```text
[1/6] Register synthetic demo user
[2/6] Login and obtain bearer token (not printed)
[3/6] Upload synthetic text fixture
[4/6] Poll server-controlled lifecycle until AVAILABLE
[5/6] Issue temporary signed capability and verify downloaded bytes
[6/6] Delete owned file and revoke lifecycle access
V1_DEMO=PASS file=<uuid> sha256=<fixture-digest>
```

The UUID and digest are expected to vary where applicable. The pass marker and lifecycle behavior are the stable evidence.

## Optional target override

The script defaults to:

```text
http://127.0.0.1:8000
```

To exercise another explicitly authorized environment, set `BASE_URL`:

```bash
BASE_URL="https://authorized-example.invalid" bash scripts/demo-v1.sh
```

The script should only be run against environments you are authorized to test. Do not place real credentials or personal data in demo variables or fixtures.

## Cleanup

To remove the disposable local dependency data after the demo:

```bash
docker compose down -v --remove-orphans
```

## CI evidence

The permanent `Application Quality` workflow runs the same `scripts/demo-v1.sh` against the real Dockerized Laravel/PostgreSQL/Redis/MinIO/ClamAV stack after migrations, readiness and worker startup succeed.

This makes the documentation executable evidence rather than a hand-written sequence that can silently drift from the application.
