#!/usr/bin/env bash
# TET v2 テストランナー。tests/*_test.php を順に実行し、1つでも失敗したら非0で終わる。
# 各テストはschemaから合成DBを生成するため、本番DBにも本番dataにも依存しない。
set -u
cd "$(dirname "$0")"

fail=0
total=0
for t in *_test.php; do
  [ -e "$t" ] || continue
  total=$((total + 1))
  echo "──────── $t ────────"
  if php "$t"; then
    :
  else
    echo "  ✗ FAILED: $t"
    fail=$((fail + 1))
  fi
  echo
done

echo "════════════════════════════"
if [ "$fail" -eq 0 ]; then
  echo "✅ 全 $total テストファイル PASS"
  exit 0
else
  echo "❌ $fail / $total テストファイルが失敗"
  exit 1
fi
