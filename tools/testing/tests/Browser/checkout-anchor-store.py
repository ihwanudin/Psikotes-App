"""Pure coordinator-owned persistence for checkout ownership-journal anchors."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat as stat_module


MAX_DOCUMENT_BYTES = 1024
MAX_GENERATION = 1024
_SESSION = re.compile(r"checkout-[a-f0-9]{32}")
_DIGEST = re.compile(r"[a-f0-9]{64}")
_REPARSE_ATTRIBUTE = 0x400


class AnchorStoreRefused(Exception):
    """Fixed refusal codes only; paths and stored values are never exposed."""


def _is_reparse(path: Path) -> bool:
    try:
        stat = path.lstat()
    except OSError:
        raise AnchorStoreRefused("anchor_path") from None
    return path.is_symlink() or bool(getattr(stat, "st_file_attributes", 0) & _REPARSE_ATTRIBUTE)


def _identity(stat):
    return stat.st_dev, stat.st_ino


def _path_stat(path: Path):
    try:
        value = path.lstat()
    except OSError:
        raise AnchorStoreRefused("anchor_path") from None
    if stat_module.S_ISLNK(value.st_mode) \
            or bool(getattr(value, "st_file_attributes", 0) & _REPARSE_ATTRIBUTE):
        raise AnchorStoreRefused("anchor_path")
    return value


def canonical_path(value) -> str:
    try:
        path = Path(value)
        if not path.is_absolute() or "\0" in str(path) or not path.exists() or _is_reparse(path):
            raise AnchorStoreRefused("anchor_path")
        resolved = path.resolve(strict=True)
        if resolved != path.absolute():
            raise AnchorStoreRefused("anchor_path")
        return os.path.normcase(str(resolved)).replace("\\", "/")
    except AnchorStoreRefused:
        raise
    except Exception:
        raise AnchorStoreRefused("anchor_path") from None


def _strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise AnchorStoreRefused("anchor_document")
        result[key] = value
    return result


class CheckoutAnchorStore:
    """Persist one supervisor journal anchor under coordinator-controlled storage."""

    def __init__(self, *, coordinator_directory, run_directory, session, config_binding):
        if not isinstance(session, str) or _SESSION.fullmatch(session) is None \
                or not isinstance(config_binding, str) or _DIGEST.fullmatch(config_binding) is None:
            raise AnchorStoreRefused("anchor_binding")

        try:
            coordinator = Path(coordinator_directory)
            run = Path(run_directory)
            canonical_path(coordinator)
            run_value = canonical_path(run)
            coordinator_resolved = coordinator.resolve(strict=True)
            run_resolved = run.resolve(strict=True)
            coordinator_stat = _path_stat(coordinator_resolved)
            if not stat_module.S_ISDIR(coordinator_stat.st_mode) \
                    or not stat_module.S_ISDIR(_path_stat(run_resolved).st_mode):
                raise AnchorStoreRefused("anchor_path")
            try:
                coordinator_resolved.relative_to(run_resolved)
                raise AnchorStoreRefused("anchor_scope")
            except ValueError:
                pass
        except AnchorStoreRefused:
            raise
        except Exception:
            raise AnchorStoreRefused("anchor_path") from None

        self.directory = coordinator_resolved
        self.directory_identity = _identity(coordinator_stat)
        self.run = run_value
        self.session = session
        self.config_binding = config_binding
        binding = json.dumps({
            "run": self.run,
            "session": self.session,
            "configBinding": self.config_binding,
        }, sort_keys=True, separators=(",", ":")).encode("ascii")
        identity = hashlib.sha256(binding).hexdigest()
        self.path = self.directory / ("checkout-anchor-" + identity + ".json")
        self.lock_path = self.directory / ("checkout-anchor-" + identity + ".lock")

    def _validate_directory(self):
        try:
            value = _path_stat(self.directory)
            if not stat_module.S_ISDIR(value.st_mode) or _identity(value) != self.directory_identity \
                    or self.directory.resolve(strict=True) != self.directory.absolute():
                raise AnchorStoreRefused("anchor_path")
        except AnchorStoreRefused:
            raise
        except Exception:
            raise AnchorStoreRefused("anchor_path") from None

    @staticmethod
    def _validate_anchor(anchor):
        if not isinstance(anchor, dict) or set(anchor) != {"generation", "digest"} \
                or type(anchor["generation"]) is not int \
                or not 1 <= anchor["generation"] <= MAX_GENERATION \
                or not isinstance(anchor["digest"], str) or _DIGEST.fullmatch(anchor["digest"]) is None:
            raise AnchorStoreRefused("anchor_shape")
        return {"generation": anchor["generation"], "digest": anchor["digest"]}

    def _document(self, anchor):
        return {
            "version": 1,
            "run": self.run,
            "session": self.session,
            "configBinding": self.config_binding,
            "anchor": dict(anchor),
        }

    def _read(self, *, missing_ok=False):
        self._validate_directory()
        if not os.path.lexists(self.path):
            self._validate_directory()
            if missing_ok:
                return None
            raise AnchorStoreRefused("anchor_missing")
        try:
            _path_stat(self.path)
            flags = os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0)
            descriptor = os.open(self.path, flags)
            try:
                opened = os.fstat(descriptor)
                linked = _path_stat(self.path)
                if not stat_module.S_ISREG(opened.st_mode) or not stat_module.S_ISREG(linked.st_mode) \
                        or not os.path.samestat(opened, linked):
                    raise AnchorStoreRefused("anchor_path")
                if not 1 <= opened.st_size <= MAX_DOCUMENT_BYTES:
                    raise AnchorStoreRefused("anchor_document")
                chunks = []
                remaining = MAX_DOCUMENT_BYTES + 1
                while remaining:
                    chunk = os.read(descriptor, remaining)
                    if not chunk:
                        break
                    chunks.append(chunk)
                    remaining -= len(chunk)
                raw = b"".join(chunks)
                after_opened = os.fstat(descriptor)
                after_linked = _path_stat(self.path)
                if not os.path.samestat(opened, after_opened) or not os.path.samestat(after_opened, after_linked):
                    raise AnchorStoreRefused("anchor_path")
            finally:
                os.close(descriptor)
            if not raw or len(raw) > MAX_DOCUMENT_BYTES:
                raise AnchorStoreRefused("anchor_document")
            self._validate_directory()
            document = json.loads(raw.decode("ascii"), object_pairs_hook=_strict_object)
        except AnchorStoreRefused:
            raise
        except Exception:
            raise AnchorStoreRefused("anchor_document") from None
        if not isinstance(document, dict) or set(document) != {
            "version", "run", "session", "configBinding", "anchor"
        } or type(document["version"]) is not int or document["version"] != 1:
            raise AnchorStoreRefused("anchor_document")
        if document["run"] != self.run or document["session"] != self.session \
                or document["configBinding"] != self.config_binding:
            raise AnchorStoreRefused("anchor_provenance")
        document["anchor"] = self._validate_anchor(document["anchor"])
        self._validate_directory()
        return document

    @staticmethod
    def _sync_directory(path: Path):
        if os.name == "nt":
            return
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)

    def publish(self, anchor):
        anchor = self._validate_anchor(anchor)
        self._validate_directory()
        lock_descriptor = None
        try:
            flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_BINARY", 0) \
                | getattr(os, "O_NOFOLLOW", 0)
            lock_descriptor = os.open(self.lock_path, flags, 0o600)
            lock_opened = os.fstat(lock_descriptor)
            lock_linked = _path_stat(self.lock_path)
            if not stat_module.S_ISREG(lock_opened.st_mode) or not stat_module.S_ISREG(lock_linked.st_mode) \
                    or not os.path.samestat(lock_opened, lock_linked):
                raise AnchorStoreRefused("anchor_lock")
            os.write(lock_descriptor, b"LOCK\n")
            os.fsync(lock_descriptor)
        except FileExistsError:
            raise AnchorStoreRefused("anchor_locked") from None
        except AnchorStoreRefused:
            if lock_descriptor is not None:
                try:
                    os.close(lock_descriptor)
                except Exception:
                    pass
            raise
        except Exception:
            if lock_descriptor is not None:
                try:
                    os.close(lock_descriptor)
                except Exception:
                    pass
            raise AnchorStoreRefused("anchor_write") from None

        temporary = None
        try:
            current = self._read(missing_ok=True)
            if current is None:
                if anchor["generation"] != 1:
                    raise AnchorStoreRefused("anchor_order")
            else:
                previous = current["anchor"]
                if anchor["generation"] < previous["generation"]:
                    raise AnchorStoreRefused("anchor_stale")
                if anchor["generation"] == previous["generation"]:
                    if anchor != previous:
                        raise AnchorStoreRefused("anchor_conflict")
                    return dict(previous)
                if anchor["generation"] != previous["generation"] + 1:
                    raise AnchorStoreRefused("anchor_order")

            raw = json.dumps(self._document(anchor), sort_keys=True, separators=(",", ":")).encode("ascii")
            if len(raw) > MAX_DOCUMENT_BYTES:
                raise AnchorStoreRefused("anchor_document")
            temporary = self.directory / f".{self.path.name}.{secrets.token_hex(16)}.tmp"
            try:
                with temporary.open("xb") as file:
                    file.write(raw)
                    file.flush()
                    os.fsync(file.fileno())
                self._validate_directory()
                os.replace(temporary, self.path)
                self._sync_directory(self.directory)
                self._validate_directory()
            except Exception:
                raise AnchorStoreRefused("anchor_write") from None
            return dict(anchor)
        finally:
            try:
                if temporary is not None:
                    temporary.unlink(missing_ok=True)
            except Exception:
                pass
            lock_matches = False
            try:
                lock_linked = _path_stat(self.lock_path)
                lock_matches = stat_module.S_ISREG(lock_linked.st_mode) \
                    and os.path.samestat(os.fstat(lock_descriptor), lock_linked)
            except Exception:
                lock_matches = False
            finally:
                try:
                    os.close(lock_descriptor)
                except Exception:
                    lock_matches = False
            if not lock_matches:
                raise AnchorStoreRefused("anchor_lock")
            try:
                self.lock_path.unlink()
                self._sync_directory(self.directory)
                self._validate_directory()
            except Exception:
                # The completed write remains valid, but future publication must
                # fail closed on the retained lock until coordinator review.
                raise AnchorStoreRefused("anchor_lock") from None

    def load(self):
        document = self._read()
        return {
            "version": document["version"],
            "run": document["run"],
            "session": document["session"],
            "configBinding": document["configBinding"],
            "anchor": dict(document["anchor"]),
        }
