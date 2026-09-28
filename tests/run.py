#!/usr/bin/env python3
"""Run production-path fixtures only in fresh, disposable Docker containers."""
import argparse
import datetime
import json
import os
import runpy
import shutil
import tempfile
from pathlib import Path
import subprocess
import sys
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
IMAGE = os.environ.get('SNAPSYNC_TEST_IMAGE', 'snapsync-test-runtime:production-readiness')
ISOLATED = [
    'retirement_daemon.php', 'retirement_ssh_daemon.php', 'attention_endpoints.php', 'batch_endpoints.php', 'coordinator_auto.php', 'auto_mutation_daemon.php', 'auto_partial_replan_daemon.php',
    'auto_partial_identity_daemon.php',
    'auto_watchdog_recovery.php',
    'coordinator_batch_cancel_endpoint.php', 'coordinator_batch_recovery.php',
    'coordinator_compatibility.php', 'coordinator_delete_adapter.php',
    'coordinator_replan_daemon.php', 'coordinator_schedule_cancel.php',
    'deletion_approval.php', 'replication_inspection_worker.php',
    'shared_cleanup_adapter.php',
    'ssh_receiver_read.php',
    'ssh_receiver_ownership.php',
    'ssh_native_phase.php',
    'ssh_cleanup_execution.php',
    'ssh_source_retention_daemon.php',
    'ssh_cleanup_daemon.php',
    'replication_now_endpoint.php', 'source_retention_endpoints.php',
    'workspace_endpoints.php', 'installation_compatibility.sh', 'auto_log.sh',
]


def suites(group):
    if group in ('ci', 'all', 'unit'):
        yield 'standard', ['bash', 'tests/stage1/run.sh']
        yield 'standard', ['bash', 'tests/reliability/run.sh']
    if group in ('ci', 'all', 'endpoints'):
        for name in ISOLATED:
            yield 'standard', ['php' if name.endswith('.php') else 'bash', 'tests/reliability/' + name]
    if group in ('ci', 'all', 'browser'):
        for path in sorted((ROOT / 'tests/reliability').glob('*browser.cjs')):
            yield 'standard', ['node', str(path.relative_to(ROOT))]
    if group in ('ci', 'all', 'syntax'):
        yield 'standard', ['bash', 'tests/syntax.sh']
    if group in ('ci', 'all', 'package'):
        yield 'standard', ['python3', 'tests/reliability/package_content.py']
        yield 'standard', ['python3', 'tests/reliability/release_acceptance.py']
        yield 'standard', ['bash', 'tests/reliability/package_build.sh']
    if group in ('all', 'flash'):
        yield 'readonly', ['bash', 'tests/reliability/ram_runtime.sh']
        yield 'readonly', ['php', 'tests/reliability/coordinator_flash.php']
        yield 'mount', ['php', 'tests/reliability/source_retention_daemon.php']
    if group in ('all', 'zfs'):
        for name in ['zfs_integration.sh', 'source_retention_zfs.sh', 'replication_recovery_zfs.sh', 'coordinator_refresh_zfs.sh']:
            yield 'zfs', ['bash', 'tests/reliability/' + name]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('group', choices=['ci', 'unit', 'endpoints', 'browser', 'syntax', 'package', 'flash', 'zfs', 'all'])
    parser.add_argument('--output', type=Path)
    parser.add_argument('--filter', default='', help='Run only commands containing this substring; report remains scoped.')
    args = parser.parse_args()
    jobs = [(profile, command) for profile, command in suites(args.group) if args.filter in ' '.join(command)]
    if not jobs:
        parser.error('No matching suites')
    if any(profile == 'zfs' for profile, _ in jobs) and os.environ.get('ZFSAS_DISPOSABLE_POOL_TEST') != '1':
        parser.error('Real ZFS requires ZFSAS_DISPOSABLE_POOL_TEST=1 on a dedicated disposable-pool host.')
    pool_root = None
    if any(profile == 'zfs' for profile, _ in jobs):
        configured = os.environ.get('ZFSAS_POOL_FIXTURE_ROOT', '')
        if not configured or not Path(configured).is_absolute() or not Path(configured).is_dir():
            parser.error('Real ZFS requires an existing absolute ZFSAS_POOL_FIXTURE_ROOT on the dedicated host.')
        pool_root = Path(configured).resolve()
    image_id = subprocess.check_output(['docker', 'image', 'inspect', IMAGE, '--format', '{{.Id}}'], text=True).strip()
    output = (args.output or Path('/tmp') / ('snapsync-tests-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S') + '-' + uuid.uuid4().hex[:6])).resolve()
    output.mkdir(parents=True, exist_ok=False)
    report = {'group': args.group, 'filter': args.filter, 'image': image_id,
              'revision': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
              'dirty': bool(subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT, text=True)), 'results': []}
    digest = runpy.run_path(str(ROOT / 'scripts/verify-acceptance.py'))['input_digest']
    before = digest(ROOT)
    # Freeze the complete checkout once; every isolated suite sees identical bytes.
    frozen = tempfile.TemporaryDirectory(prefix='snapsync-test-input-')
    input_root = Path(frozen.name) / 'checkout'
    shutil.copytree(ROOT, input_root, symlinks=True, ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
    report['inputDigest'] = digest(input_root)
    if before != report['inputDigest'] or before != digest(ROOT):
        frozen.cleanup()
        raise RuntimeError('Inputs changed while capturing the test checkout; rerun with stable inputs.')
    print('Results: ' + str(output), flush=True)
    for index, (profile, command) in enumerate(jobs):
        name = 'snapsync-test-' + uuid.uuid4().hex[:12]
        flags = ['--rm', '--name', name, '--network', 'none', '--shm-size', '256m', '-v', str(input_root) + ':/work:ro', '-w', '/work']
        if profile == 'readonly':
            flags += ['-v', str(input_root / 'tests/runtime/boot') + ':/boot:ro']
        if profile in ('mount', 'zfs'):
            flags += ['--cap-add', 'SYS_ADMIN', '--security-opt', 'apparmor=unconfined']
        fixture_root = None
        if profile == 'zfs':
            # ZFS opens file vdev paths in the host kernel's namespace.
            fixture_root = Path(tempfile.mkdtemp(prefix='snapsync-pools-', dir=pool_root))
            flags += ['--device', '/dev/zfs', '-e', 'ZFSAS_DISPOSABLE_POOL_TEST=1',
                      '-e', 'ZFSAS_POOL_FIXTURE_ROOT=' + str(fixture_root),
                      '-v', str(fixture_root) + ':' + str(fixture_root)]
        log = output / ('%02d-%s.log' % (index + 1, Path(command[-1]).stem))
        started = time.monotonic()
        print('RUN ' + ' '.join(command), flush=True)
        try:
            with log.open('w') as stream:
                result = subprocess.run(['docker', 'run', *flags, IMAGE, 'bash', '-c',
                    'set -e; cp -a /work/source/. /; exec "$@"', 'test', *command],
                    stdout=stream, stderr=subprocess.STDOUT, timeout=1200)
            code = result.returncode
        except subprocess.TimeoutExpired:
            code = 124
        finally:
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        report['results'].append({'command': command, 'profile': profile, 'exitCode': code,
                                  'seconds': round(time.monotonic() - started, 2), 'log': log.name,
                                  'poolFixtureRoot': str(fixture_root) if fixture_root else None})
        if fixture_root and code == 0 and not any(fixture_root.iterdir()):
            fixture_root.rmdir()
        (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n')
        print(('PASS' if code == 0 else 'FAIL (%s)' % code) + ' ' + str(log), flush=True)
        if code:
            print(log.read_text()[-5000:], flush=True)
    frozen.cleanup()
    return int(any(row['exitCode'] != 0 for row in report['results']))


if __name__ == '__main__':
    sys.exit(main())
