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

    if [[ "$state" == 'SCAN_FAILED' || "$state" == 'DELETED' ]]; then
      printf 'Unexpected terminal state for %s: %s\n' "$file_id" "$state" >&2
      printf '%s\n' "$response" >&2
      return 1
    fi

    sleep 2
  done

  printf 'Timed out waiting for %s to reach %s\n' "$file_id" "$expected_state" >&2
  return 1
}

printf '%s' 'real ClamAV clean-path fixture' > "$WORK_DIR/clean.txt"

python3 - "$WORK_DIR/eicar.txt" <<'PY'
from pathlib import Path
import sys

parts = [
    'X5O!P%@AP[4',
    '\\PZX54(P^)7CC)7}$',
    'EICAR-STANDARD-ANTIVIRUS-',
    'TEST-FILE!$H+H*',
]
Path(sys.argv[1]).write_bytes(''.join(parts).encode('ascii'))
PY

register_payload='{"name":"Real Engine E2E","email":"real-engine-e2e@example.test","password":"StrongPass123!","password_confirmation":"StrongPass123!"}'
login_payload='{"email":"real-engine-e2e@example.test","password":"StrongPass123!","device_name":"real-clamav-e2e"}'

curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data "$register_payload" \
  "${BASE_URL}/api/v1/auth/register" > /dev/null

login_response="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data "$login_payload" \
  "${BASE_URL}/api/v1/auth/login")"
token="$(printf '%s' "$login_response" | json_field 'data.token')"

clean_upload="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  --form "file=@${WORK_DIR}/clean.txt;filename=clean.txt" \
  "${BASE_URL}/api/v1/files")"
clean_id="$(printf '%s' "$clean_upload" | json_field 'data.id')"
wait_for_state "$clean_id" 'AVAILABLE' "$token"

capability_response="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  "${BASE_URL}/api/v1/files/${clean_id}/download")"
clean_url="$(printf '%s' "$capability_response" | json_field 'data.url')"

curl --fail --silent --show-error "$clean_url" --output "$WORK_DIR/downloaded-clean.txt"
cmp "$WORK_DIR/clean.txt" "$WORK_DIR/downloaded-clean.txt"

curl --fail --silent --show-error \
  --request DELETE \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  "${BASE_URL}/api/v1/files/${clean_id}" \
  --output /dev/null

unsafe_upload="$(curl --fail --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  --form "file=@${WORK_DIR}/eicar.txt;filename=eicar.txt" \
  "${BASE_URL}/api/v1/files")"
unsafe_id="$(printf '%s' "$unsafe_upload" | json_field 'data.id')"
wait_for_state "$unsafe_id" 'REJECTED' "$token"

unsafe_body="$WORK_DIR/unsafe-download.json"
unsafe_status="$(curl --silent --show-error \
  --request POST \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${token}" \
  --output "$unsafe_body" \
  --write-out '%{http_code}' \
  "${BASE_URL}/api/v1/files/${unsafe_id}/download")"

if [[ "$unsafe_status" != '409' ]]; then
  printf 'Expected rejected file download capability to return 409, got %s\n' "$unsafe_status" >&2
  cat "$unsafe_body" >&2
  exit 1
fi

unsafe_code="$(cat "$unsafe_body" | json_field 'error.code')"
if [[ "$unsafe_code" != 'FILE_NOT_AVAILABLE' ]]; then
  printf 'Expected FILE_NOT_AVAILABLE, got %s\n' "$unsafe_code" >&2
  cat "$unsafe_body" >&2
  exit 1
fi

printf 'REAL_CLAMAV_E2E=PASS clean=%s unsafe=%s\n' "$clean_id" "$unsafe_id"
