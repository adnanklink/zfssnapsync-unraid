#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import io
import os
import tarfile
import tempfile
import sys
sys.dont_write_bytecode = True
import unittest

spec = importlib.util.spec_from_file_location('verify', Path(__file__).resolve().parents[2] / 'scripts/verify-package-content.py')
verify = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verify)


class PackageContent(unittest.TestCase):
    def test_content_permissions_links_and_unsafe_members(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source'
            source.mkdir()
            program = source / 'worker'
            program.write_bytes(b'original')
            program.chmod(0o755)
            (source / 'alias').symlink_to('worker')
            package = root / 'test.txz'

            def build(change=None):
                with tarfile.open(package, 'w:xz') as archive:
                    for path in sorted(source.iterdir()):
                        member = archive.gettarinfo(str(path), path.name)
                        member.uid = member.gid = 0
                        content = path.read_bytes() if member.isfile() else None
                        if change:
                            member, content = change(member, content)
                        archive.addfile(member, io.BytesIO(content) if content is not None else None)

            build()
            verify.verify(package, source)
            for case in ('content', 'mode', 'link', 'path', 'owner'):
                def mutate(member, content):
                    if case == 'content' and member.isfile(): content = b'modified'
                    if case == 'mode' and member.isfile(): member.mode = 0o644
                    if case == 'link' and member.issym(): member.linkname = 'elsewhere'
                    if case == 'path': member.name = '../' + member.name
                    if case == 'owner': member.uid = 1000
                    return member, content
                build(mutate)
                with self.subTest(case=case), self.assertRaises(ValueError):
                    verify.verify(package, source)
            build()
            program.write_bytes(b'changed!')
            with self.assertRaises(ValueError): verify.verify(package, source)


if __name__ == '__main__': unittest.main()
