#!/usr/bin/env python3
"""Reject missing, stale or incomplete production acceptance evidence."""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import stat
import sys

CHECKS = {'ci', 'local-zfs', 'ssh-zfs', 'cleanup', 'replanning', 'lifecycle', 'flash', 'scale', 'webgui', 'soak'}


def file_hash(path):
    result = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(chunk)
    return result.hexdigest()


def input_digest(root):
    rows = []
    for name in ['source', 'scripts', 'tests', 'VERSION', 'zfs.snapsync.plg.in']:
        base = root / name
        paths = [base, *sorted(base.rglob('*'))] if base.is_dir() else [base]
        for path in paths:
            if '__pycache__' in path.parts or path.suffix == '.pyc':
                continue
            mode = path.lstat().st_mode
            value = os.readlink(path) if path.is_symlink() else file_hash(path) if path.is_file() else None
            canonical_mode = 0o777 if path.is_symlink() else 0o755 if path.is_dir() or mode & 0o111 else 0o644
            rows.append([path.relative_to(root).as_posix(), stat.S_IFMT(mode), canonical_mode, value])
    return hashlib.sha256(json.dumps(rows, separators=(',', ':')).encode()).hexdigest()


def verify(root, report_path, package):
    report = json.loads(report_path.read_text())
    if report.get('version') != (root / 'VERSION').read_text().strip() or report.get('status') != 'passed':
        raise ValueError('Acceptance must pass for the exact release version')
    if report.get('inputDigest') != input_digest(root):
        raise ValueError('Acceptance is stale: implementation, tests or packaging inputs changed')
    if report.get('packageSha256') != file_hash(package):
        raise ValueError('Acceptance belongs to different package bytes')
    checks = report.get('checks', {})
    if not CHECKS.issubset(checks):
        raise ValueError('Required acceptance checks are missing: ' + ', '.join(sorted(CHECKS - checks.keys())))
    for name in CHECKS:
        item = checks[name]
        if item.get('status') != 'passed':
            raise ValueError('Acceptance did not pass: ' + name)
        evidence = item.get('evidence', '')
        path = (root / evidence).resolve()
        if not evidence or not path.is_relative_to(root.resolve()) or not path.is_file():
            raise ValueError('Missing repository acceptance evidence: ' + name)
        if item.get('sha256') != file_hash(path):
            raise ValueError('Acceptance evidence changed: ' + name)
    duration = checks['soak'].get('seconds', 0)
    if type(duration) not in (int, float) or not math.isfinite(duration) or duration < 48 * 3600:
        raise ValueError('A completed 48-hour soak is required')
    platforms = report.get('platforms', {})
    for name in ['unraidMinimum', 'unraidCurrent', 'linuxReceiver']:
        item = platforms.get(name, {})
        if item.get('status') != 'passed' or not item.get('version'):
            raise ValueError('Missing passing platform acceptance: ' + name)
    if platforms['unraidMinimum']['version'] != '6.12.0' or not platforms['unraidCurrent']['version'].startswith('7.'):
        raise ValueError('The agreed Unraid compatibility matrix is incomplete')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--digest', action='store_true')
    parser.add_argument('report', type=Path, nargs='?')
    parser.add_argument('package', type=Path, nargs='?')
    args = parser.parse_args()
    if args.digest:
        print(input_digest(args.root))
        return
    if not args.report or not args.package:
        parser.error('report and package are required unless --digest is used')
    verify(args.root, args.report, args.package)
    print('Verified complete acceptance evidence for these exact release inputs and package bytes')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, TypeError, KeyError) as error:
        sys.exit('Release blocked: ' + str(error))
