"""Candidate-global cooperative lifecycle lease; import has no side effects."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import stat


MAX_DOCUMENT_BYTES = 1024
_REPARSE_ATTRIBUTE = 0x400


class LeaseRefused(Exception):
    """Fixed lease refusal codes only; never includes paths or OS details."""


def _identity(value):
    return value.st_dev, value.st_ino


def _path_stat(path):
    try:
        value = path.lstat()
        if stat.S_ISLNK(value.st_mode) \
                or bool(getattr(value, "st_file_attributes", 0) & _REPARSE_ATTRIBUTE):
            raise LeaseRefused("lease_invalid")
        return value
    except LeaseRefused:
        raise
    except Exception:
        raise LeaseRefused("lease_invalid") from None


def _canonical_directory(value, code):
    try:
        path = Path(value)
        if not path.is_absolute() or "\0" in str(path):
            raise LeaseRefused(code)
        resolved = path.resolve(strict=True)
        info = _path_stat(path)
        if resolved != path.absolute() or not stat.S_ISDIR(info.st_mode):
            raise LeaseRefused(code)
        return resolved, _identity(info)
    except LeaseRefused:
        raise
    except Exception:
        raise LeaseRefused(code) from None


def _canonical_path(value):
    path, _ = _canonical_directory(value, "lease_path")
    return os.path.normcase(str(path)).replace("\\", "/")


def lease_path(coordinator_directory, run_directory):
    _canonical_directory(coordinator_directory, "lease_path")
    run, _ = _canonical_directory(run_directory, "lease_path")
    return run / ".checkout-coordinator.lease"


if os.name == "nt":
    import msvcrt

    def _lock_descriptor(descriptor):
        os.lseek(descriptor, 0, os.SEEK_SET)
        msvcrt.locking(descriptor, msvcrt.LK_NBLCK, 1)

    def _unlock_descriptor(descriptor):
        os.lseek(descriptor, 0, os.SEEK_SET)
        msvcrt.locking(descriptor, msvcrt.LK_UNLCK, 1)
else:
    import fcntl

    def _lock_descriptor(descriptor):
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def _unlock_descriptor(descriptor):
        fcntl.flock(descriptor, fcntl.LOCK_UN)


class CheckoutCoordinatorLease:
    """Hold one kernel/advisory lock for a canonical candidate directory."""

    @classmethod
    def acquire(cls, *, coordinator_directory, run_directory):
        coordinator, coordinator_identity = _canonical_directory(
            coordinator_directory, "lease_path"
        )
        run, run_identity = _canonical_directory(run_directory, "lease_path")
        try:
            coordinator.relative_to(run)
            raise LeaseRefused("lease_scope")
        except ValueError:
            pass
        normalized_run = os.path.normcase(str(run)).replace("\\", "/")
        binding = hashlib.sha256(normalized_run.encode("utf-8")).hexdigest()
        document = {
            "version": 1,
            "run": normalized_run,
            "leaseBinding": binding,
        }
        raw = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode("ascii")
        if len(raw) > MAX_DOCUMENT_BYTES:
            raise LeaseRefused("lease_invalid")
        path = run / ".checkout-coordinator.lease"
        descriptor = None
        locked = False
        created = False
        try:
            flags = os.O_RDWR | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0)
            try:
                descriptor = os.open(path, flags | os.O_CREAT | os.O_EXCL, 0o600)
                created = True
            except FileExistsError:
                descriptor = os.open(path, flags)
            os.set_inheritable(descriptor, False)
            try:
                _lock_descriptor(descriptor)
                locked = True
            except OSError:
                raise LeaseRefused("lease_held") from None
            if created:
                view = memoryview(raw)
                while view:
                    written = os.write(descriptor, view)
                    if written <= 0:
                        raise OSError("short write")
                    view = view[written:]
                os.fsync(descriptor)
            lease = cls(
                coordinator=coordinator,
                coordinator_identity=coordinator_identity,
                run=normalized_run,
                run_directory=run,
                run_identity=run_identity,
                path=path,
                descriptor=descriptor,
                raw=raw,
            )
            lease.validate()
            descriptor = None
            locked = False
            return lease
        except LeaseRefused:
            raise
        except Exception:
            raise LeaseRefused("lease_write" if created else "lease_invalid") from None
        finally:
            if descriptor is not None:
                if locked:
                    try:
                        _unlock_descriptor(descriptor)
                    except BaseException:
                        pass
                try:
                    os.close(descriptor)
                except BaseException:
                    pass

    def __init__(self, *, coordinator, coordinator_identity, run, run_directory,
                 run_identity, path, descriptor, raw):
        self.directory = coordinator
        self.directory_identity = coordinator_identity
        self.run = run
        self.run_directory = run_directory
        self.run_identity = run_identity
        self.path = path
        self.descriptor = descriptor
        self.__descriptor = descriptor
        self.binding = hashlib.sha256(run.encode("utf-8")).hexdigest()
        self._raw = raw
        self._descriptor_identity = _identity(os.fstat(descriptor))
        self.descriptor_identity = self._descriptor_identity
        self._operational_identity = (
            self.directory, self.directory_identity, self.run, self.run_directory,
            self.run_identity, self.path,
            self.__descriptor, self.binding, self.descriptor_identity,
        )
        self._released = False

    def _validate_directory(self):
        try:
            value = _path_stat(self.directory)
            if not stat.S_ISDIR(value.st_mode) or _identity(value) != self.directory_identity \
                    or self.directory.resolve(strict=True) != self.directory.absolute():
                raise LeaseRefused("lease_invalid")
        except LeaseRefused:
            raise
        except Exception:
            raise LeaseRefused("lease_invalid") from None

    def _validate_run_directory(self):
        try:
            value = _path_stat(self.run_directory)
            if not stat.S_ISDIR(value.st_mode) or _identity(value) != self.run_identity \
                    or self.run_directory.resolve(strict=True) != self.run_directory.absolute():
                raise LeaseRefused("lease_invalid")
        except LeaseRefused:
            raise
        except Exception:
            raise LeaseRefused("lease_invalid") from None

    def validate(self):
        if self._released:
            raise LeaseRefused("lease_released")
        try:
            if (
                self.directory, self.directory_identity, self.run, self.run_directory,
                self.run_identity, self.path,
                self.descriptor, self.binding, self.descriptor_identity,
            ) != self._operational_identity:
                raise LeaseRefused("lease_invalid")
            self._validate_directory()
            self._validate_run_directory()
            opened = os.fstat(self.__descriptor)
            linked = _path_stat(self.path)
            if not stat.S_ISREG(opened.st_mode) or not stat.S_ISREG(linked.st_mode) \
                    or _identity(opened) != self.descriptor_identity \
                    or not os.path.samestat(opened, linked) \
                    or not 1 <= opened.st_size <= MAX_DOCUMENT_BYTES:
                raise LeaseRefused("lease_invalid")
            os.lseek(self.__descriptor, 0, os.SEEK_SET)
            raw = os.read(self.__descriptor, MAX_DOCUMENT_BYTES + 1)
            after = os.fstat(self.__descriptor)
            after_linked = _path_stat(self.path)
            if raw != self._raw or not os.path.samestat(opened, after) \
                    or not os.path.samestat(after, after_linked):
                raise LeaseRefused("lease_invalid")
            document = json.loads(raw.decode("ascii"))
            if document != {
                "leaseBinding": hashlib.sha256(self.run.encode("utf-8")).hexdigest(),
                "run": self.run,
                "version": 1,
            }:
                raise LeaseRefused("lease_invalid")
            self._validate_directory()
            self._validate_run_directory()
            return dict(document)
        except LeaseRefused:
            raise
        except Exception:
            raise LeaseRefused("lease_invalid") from None

    def release(self, terminal_state):
        if terminal_state != "lifecycle_finished":
            raise LeaseRefused("lease_terminal")
        primary = None
        closed = False
        try:
            self.validate()
        except BaseException as error:
            primary = error
        try:
            _unlock_descriptor(self.__descriptor)
        except BaseException as error:
            if primary is None:
                primary = error
        try:
            os.close(self.__descriptor)
            closed = True
        except BaseException as error:
            if primary is None:
                primary = error
        self._released = closed
        if primary is not None:
            if isinstance(primary, Exception):
                raise LeaseRefused("lease_release") from None
            raise primary
