#!/usr/bin/env python3
"""Synthetic acceptance records test the gate; these are not release evidence."""
import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('acceptance', Path(__file__).resolve().parents[2] / 'scripts/verify-acceptance.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class AcceptanceGate(unittest.TestCase):
    def test_missing_stale_incomplete_and_changed_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ['source', 'scripts', 'tests']:
                (root / name).mkdir()
            (root / 'source/worker').write_text('original')
            (root / 'VERSION').write_text('2026.09.30.01\n')
            (root / 'zfs.snapsync.plg.in').write_text('fixture')
            package = root / 'package.txz'
            package.write_bytes(b'synthetic test package')
            evidence = root / 'evidence.txt'
            evidence.write_text('synthetic fixture, not an acceptance attestation')
            report = root / 'report.json'
            data = {'version': '2026.09.30.01', 'status': 'passed',
                    'inputDigest': gate.input_digest(root), 'packageSha256': gate.file_hash(package),
                    'checks': {name: {'status': 'passed', 'evidence': 'evidence.txt', 'sha256': gate.file_hash(evidence)} for name in gate.CHECKS},
                    'platforms': {name: {'status': 'passed', 'version': version} for name, version in
                                  [('unraidMinimum', '6.12.0'), ('unraidCurrent', '7.fixture'), ('linuxReceiver', 'fixture')]}}
            data['checks']['soak']['seconds'] = 172800
            report.write_text(json.dumps(data))
            gate.verify(root, report, package)
            for case in ['missing', 'failed', 'short-soak', 'wrong-package', 'wrong-source', 'wrong-platform', 'outside-evidence']:
                invalid = copy.deepcopy(data)
                if case == 'missing': del invalid['checks']['ssh-zfs']
                if case == 'failed': invalid['checks']['flash']['status'] = 'skipped'
                if case == 'short-soak': invalid['checks']['soak']['seconds'] -= 1
                if case == 'wrong-package': invalid['packageSha256'] = '0' * 64
                if case == 'wrong-source': invalid['inputDigest'] = '0' * 64
                if case == 'wrong-platform': invalid['platforms']['unraidMinimum']['version'] = '6.12.1'
                if case == 'outside-evidence': invalid['checks']['ci']['evidence'] = '../outside'
                report.write_text(json.dumps(invalid))
                with self.subTest(case=case), self.assertRaises(ValueError):
                    gate.verify(root, report, package)
            report.write_text(json.dumps(data))
            evidence.write_text('changed')
            with self.assertRaises(ValueError): gate.verify(root, report, package)
            self.assertNotEqual(data['inputDigest'], self.changed_input(root))

    def test_experimental_is_explicit_and_not_production_acceptance(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ['source', 'scripts', 'tests']:
                (root / name).mkdir()
            (root / 'VERSION').write_text('2026.09.28.01')
            (root / 'zfs.snapsync.plg.in').write_text('fixture')
            (root / 'README.md').write_text('Experimental release')
            package = root / 'package.txz'
            package.write_bytes(b'fixture')
            report = root / 'report.json'
            data = {'version': '2026.09.28.01', 'status': 'experimental',
                    'hostAcceptance': 'pending', 'releasePolicy': 'Experimental main',
                    'inputDigest': gate.input_digest(root), 'packageSha256': gate.file_hash(package)}
            report.write_text(json.dumps(data))
            gate.verify_experimental(root, report, package)
            with self.assertRaises(ValueError): gate.verify(root, report, package)
            for key, value in [('hostAcceptance', 'passed'), ('version', 'wrong'),
                               ('inputDigest', 'wrong'), ('packageSha256', 'wrong'), ('releasePolicy', '')]:
                invalid = dict(data, **{key: value})
                report.write_text(json.dumps(invalid))
                with self.assertRaises(ValueError): gate.verify_experimental(root, report, package)

    @staticmethod
    def changed_input(root):
        (root / 'source/worker').write_text('changed')
        return gate.input_digest(root)


if __name__ == '__main__': unittest.main()
