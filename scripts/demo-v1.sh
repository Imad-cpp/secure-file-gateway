#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

json_field() {
  local path="$1"
  python3 -c '
import json
import sys

value = json.load(sys.stdin)
for key in sys.argv[1].split("."):
    value = value[key]
print(value)
' "$path"
}

wait_for_state() {
  local file_id="$1"
  local expected_state="$2"
  local token="$3"
  local response state

  for _attempt in $(seq 1 60); do
    response="$(curl --fail --silent --show-error \
      --header 'Accept: application/json' \
      --header "Authorization: Bearer ${token}" \
      "${BASE_URL}/api/v1/files/${file_id}")"
    state="$(printf '%s' "$response" | json_field 'data.state')"

    if [[ "$state" == "$expected_state" ]]; then
      return 0
    fi

    if [[ "$state" == 'REJECTED' || "$state" == 'SCAN_FAILED' || "$state" == 'DELETED' ]]; then
      printf 'Demo file reached unexpected terminal state: %s\n' "$state" >&2
      return 1
    fi

    sleep 2
  done

  printf 'Timed out waiting for demo file to reach %s\n' "$expected_state" >&2
  return 1
}

for command_name in curl python3 cmp; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf 'Missing required command: %s\n' "$command_name" >&2
    exit 1
  fi
done

email="${DEMO_EMAIL:-demo-v1-$(date +%s)-$$@example.test}"
password="${DEMO_PASSWORD:-DemoStrongPass123!}"
name="${DEMO_NAME:-V1 Demo User}"
device_name="${DEMO_DEVICE_NAME:-v1-demo}"
fixture="$WORK_DIR/demo.txt"
downloaded="$WORK_DIR/downloaded-demo.txt"

printf '%s\n' 'Secure File Gateway reproducible V1 demo fixture.' > "$fixture"
expected_sha256="$(python3 - "$fixture" <<'PY'
from hashlib import sha256
from pathlib import Path
import sys

print(sha256(Path(sys.argv[1]).read_bytes()).hexdigest())
PY
)"

printf '[1/6] Register synthetic demo user\n'
register_payload="$(python3 - "$name" "$email" "$password" <<'PY'
import json
import sys

print(json.dumps({
    'name': sys.argv[1],
    'email': sys.argv[2],
    'password': sys.argv[3],
    'password_confirmation': sys.argv[3],
}))
PY
)"

curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data "$register_payload" \
  "${BASE_URL}/api/v1/auth/register" > /dev/null

printf '[2/6] Login and obtain bearer token (not printed)\n'
login_payload="$(python3 - "$email" "$password" "$device_name" <<'PY'
import json
import sys

print(json.dumps({
    'email': sys.argv[1],
    'password': sys.argv[2],
    'device_name': sys.argv[3],
}))
PY
)"

login_response="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data "$login_payload" \
  "${BASE_URL}/api/v1/auth/login")"
token="$(printf '%s' "$login_response" | json_field 'data.token')"

printf '[3/6] Upload synthetic text fixture\n'
upload_response="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  --form "file=@${fixture};filename=demo.txt" \
  "${BASE_URL}/api/v1/files")"
file_id="$(printf '%s' "$upload_response" | json_field 'data.id')"
initial_state="$(printf '%s' "$upload_response" | json_field 'data.state')"

if [[ "$initial_state" != 'QUARANTINED' ]]; then
  printf 'Expected initial state QUARANTINED, got %s\n' "$initial_state" >&2
  exit 1
fi

printf '[4/6] Poll server-controlled lifecycle until AVAILABLE\n'
wait_for_state "$file_id" 'AVAILABLE' "$token"

printf '[5/6] Issue temporary signed capability and verify downloaded bytes\n'
capability_response="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  "${BASE_URL}/api/v1/files/${file_id}/download")"
signed_url="$(printf '%s' "$capability_response" | json_field 'data.url')"

curl --fail --silent --show-error "$signed_url" --output "$downloaded"
cmp "$fixture" "$downloaded"

printf '[6/6] Delete owned file and revoke lifecycle access\n'
delete_status="$(curl --silent --show-error \
  --request DELETE \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  --output /dev/null \
  --write-out '%{http_code}' \
  "${BASE_URL}/api/v1/files/${file_id}")"

if [[ "$delete_status" != '204' ]]; then
  printf 'Expected delete status 204, got %s\n' "$delete_status" >&2
  exit 1
fi

printf 'V1_DEMO=PASS file=%s sha256=%s\n' "$file_id" "$expected_sha256"
