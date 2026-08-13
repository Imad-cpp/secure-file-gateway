#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
  printf 'V1_RELEASE_AUDIT=FAIL reason=%s\n' "$1" >&2
  exit 1
}

required_files=(
  README.md
  SECURITY.md
  LICENSE
  CHANGELOG.md
  openapi.yaml
  docs/ARCHITECTURE.md
  docs/SECURITY_MODEL.md
  docs/API_MAP.md
  docs/DECISIONS.md
  docs/DEFINITION_OF_DONE.md
  docs/V1_DEMO.md
  docs/V1_EVIDENCE.md
  docs/V1_RELEASE_AUDIT.md
  docs/releases/v1.0.0.md
  scripts/demo-v1.sh
  scripts/real-clamav-e2e.sh
)

for file in "${required_files[@]}"; do
  [[ -f "$file" ]] || fail "missing:$file"
done

grep -Fq 'MIT License' LICENSE || fail 'license-not-mit'
grep -Fq '## [Unreleased]' CHANGELOG.md || fail 'changelog-not-unreleased'
grep -Fq 'The `v1.0.0` tag and GitHub release do not exist yet.' docs/releases/v1.0.0.md || fail 'release-notes-tag-boundary-missing'
grep -Fq 'V1 release audit: PASS' docs/V1_RELEASE_AUDIT.md || fail 'audit-verdict-missing'
grep -Fq 'Production readiness is not claimed.' docs/V1_RELEASE_AUDIT.md || fail 'production-boundary-missing'
grep -Fq 'malware-detection completeness' docs/V1_RELEASE_AUDIT.md || fail 'scanner-boundary-missing'
grep -Fq 'generic bucket-wide orphan discovery' docs/V1_RELEASE_AUDIT.md || fail 'orphan-boundary-missing'

for temporary_workflow in \
  .github/workflows/sync-v1-demo-evidence.yml \
  .github/workflows/sync-v1-release-artifacts.yml \
  .github/workflows/generate-composer-lock.yml \
  .github/workflows/sync-v1-docs.yml; do
  [[ ! -e "$temporary_workflow" ]] || fail "temporary-workflow-present:$temporary_workflow"
done

printf 'V1_RELEASE_AUDIT=PASS\n'
