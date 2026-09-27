#!/bin/bash
set -euo pipefail
[[ -f /.dockerenv ]] || { echo 'Run through tests/run.py.' >&2; exit 1; }
while IFS= read -r -d '' file; do php -l "$file" > /dev/null; done < <(find source tests -name '*.php' -print0)
while IFS= read -r -d '' file; do node --check "$file"; done < <(find source tests -type f \( -name '*.js' -o -name '*.cjs' \) -print0)
while IFS= read -r -d '' file; do
  if [[ "$file" == *.sh ]] || head -1 "$file" | grep -qE '^#!.*(bash|/sh)'; then
    bash -n "$file"
    shellcheck --severity=error "$file"
  fi
done < <(find source scripts tests -type f ! -path '*/screenshots/*' -print0)
python3 - <<'PY'
import ast
from pathlib import Path
for root in ('tests', 'scripts'):
    for path in Path(root).rglob('*.py'):
        ast.parse(path.read_text(), filename=str(path))
PY
printf '%s\n' 'PASS: PHP, JavaScript, Python, Bash syntax and ShellCheck error checks'
