#!/usr/bin/env python3
"""Compare a release archive with source without extracting untrusted members."""
import hashlib
import os
from pathlib import Path, PurePosixPath
import stat
import sys
import tarfile


def digest(stream):
    result = hashlib.sha256()
    for chunk in iter(lambda: stream.read(1024 * 1024), b''):
        result.update(chunk)
    return result.digest()


def verify(package, source):
    expected = {}
    for base, dirs, files in os.walk(source, followlinks=False):
        for name in dirs + files:
            path = Path(base) / name
            expected[path.relative_to(source).as_posix()] = path
    seen = set()
    with tarfile.open(package, 'r:xz') as archive:
        for member in archive:
            name = member.name
            if name.startswith('./'):
                name = name[2:]
            if name in ('', '.') and member.isdir():
                if member.mode != 0o755 or member.uid != 0 or member.gid != 0:
                    raise ValueError('Archive root must be root-owned mode 0755')
                continue
            path_parts = PurePosixPath(name).parts
            if name.startswith('/') or any(part in ('.', '..') for part in name.split('/')) or not path_parts:
                raise ValueError('Unsafe archive path: ' + member.name)
            if name in seen:
                raise ValueError('Duplicate archive entry: ' + name)
            seen.add(name)
            if name not in expected:
                raise ValueError('Unexpected archive entry: ' + name)
            path = expected[name]
            mode = path.lstat().st_mode
            expected_mode = 0o777 if stat.S_ISLNK(mode) else 0o755 if stat.S_ISDIR(mode) or mode & 0o111 else 0o644
            if expected_mode != member.mode:
                raise ValueError('Permission mismatch: ' + name)
            if member.uid != 0 or member.gid != 0:
                raise ValueError('Archive ownership must be root: ' + name)
            if stat.S_ISLNK(mode):
                if not member.issym() or member.linkname != os.readlink(path):
                    raise ValueError('Symlink target/type mismatch: ' + name)
            elif stat.S_ISDIR(mode):
                if not member.isdir():
                    raise ValueError('Directory type mismatch: ' + name)
            elif stat.S_ISREG(mode):
                if not member.isfile() or member.size != path.stat().st_size:
                    raise ValueError('File type/size mismatch: ' + name)
                with path.open('rb') as source_stream, archive.extractfile(member) as package_stream:
                    if digest(source_stream) != digest(package_stream):
                        raise ValueError('File content mismatch: ' + name)
            else:
                raise ValueError('Unsupported source type: ' + name)
    missing = expected.keys() - seen
    if missing:
        raise ValueError('Missing archive entries: ' + ', '.join(sorted(missing)))


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3:
            raise ValueError('Usage: verify-package-content.py PACKAGE SOURCE')
        verify(Path(sys.argv[1]), Path(sys.argv[2]))
        print('Verified package bytes, types, modes, ownership and symlink targets')
    except (ValueError, OSError, tarfile.TarError) as error:
        sys.exit(str(error))
