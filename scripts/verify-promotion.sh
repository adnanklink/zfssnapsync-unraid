#!/bin/bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
version="$(tr -d '[:space:]' < VERSION)"
[[ "$version" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]{2}$ ]] || { echo 'Invalid release version' >&2; exit 1; }
package="dist/zfs-snapsync-${version}-noarch-1.txz"
base="${PROMOTION_BASE:-$(git rev-parse HEAD^)}"
[[ "$base" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid promotion base' >&2; exit 1; }
git cat-file -e "${base}^{commit}"
if git cat-file -e "${base}:${package}" 2>/dev/null; then
  previous="$(mktemp)"
  trap 'rm -f "$previous"' EXIT
  git show "${base}:${package}" > "$previous"
  cmp "$previous" "$package" || { echo 'Published package versions are immutable; choose a new VERSION.' >&2; exit 1; }
fi
./scripts/verify-release.sh "$package"
cmp dist/zfs.snapsync.plg zfs.snapsync.plg
python3 - "$version" "$package" <<'PY'
import hashlib, pathlib, sys, xml.etree.ElementTree as ET
version, package = sys.argv[1:]
manifest = ET.parse('dist/zfs.snapsync.plg').getroot()
base = 'https://raw.githubusercontent.com/adnanklink/zfssnapsync-unraid/main/dist'
assert manifest.attrib['version'] == version, 'Manifest version mismatch'
assert manifest.attrib['pluginURL'] == base + '/zfs.snapsync.plg', 'Wrong production update URL'
checksum = hashlib.md5(pathlib.Path(package).read_bytes()).hexdigest()
files = [file for file in manifest.findall('FILE') if file.attrib.get('Name', '').endswith(pathlib.Path(package).name)]
assert len(files) == 1, 'Missing or duplicate package file'
assert (files[0].findtext('MD5') or '').strip() == checksum, 'Package checksum mismatch'
assert (files[0].findtext('URL') or '').strip() == base + '/' + pathlib.Path(package).name, 'Wrong package URL'
PY
python3 scripts/verify-acceptance.py --allow-experimental "docs/releases/${version}.json" "$package"
