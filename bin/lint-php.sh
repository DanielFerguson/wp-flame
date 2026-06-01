#!/usr/bin/env bash
set -euo pipefail

# Syntax-check production and test PHP files without requiring extra tooling.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

files=(
    "wp-flame.php"
    "uninstall.php"
)

while IFS= read -r -d '' file; do
    files+=("$file")
done < <(find src mu-plugin tests -type f -name '*.php' -print0 | sort -z)

for file in "${files[@]}"; do
    php -l "$file" >/dev/null
done

echo "PHP syntax OK (${#files[@]} files)"
