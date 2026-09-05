"""Materialize one reviewed synthetic checkout candidate without running it."""

from __future__ import annotations

import copy
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import stat
import tempfile


EXTERNAL_TOOLS = ("php", "python", "node", "powershell", "cli", "browser")
RUN_LOCAL_FILES = ("ini", "browser_config", "cert", "key")
ALL_TOOL_KEYS = (*EXTERNAL_TOOLS, *RUN_LOCAL_FILES)
DELIVERED_ASSETS = (
    "public/css/checkout-summary-v1.css",
    "public/brand/oncam-logo-full-color.png",
    "public/js/checkout-confirmation-v1.js",
    "public/js/checkout-payment-v1.js",
)
ASSET_REVIEW_FILES = (
    "public/css/checkout-summary-v1.css",
    "public/brand/oncam-logo-full-color.png",
    "public/js/checkout-confirmation-v1.js",
    "public/js/checkout-payment-v1.js",
    "resources/views/checkout/summary.blade.php",
    "tools/testing/tests/Browser/serve-checkout-session.php",
)
SCRIPT_SOURCE_FILES = frozenset(DELIVERED_ASSETS[2:])
BROWSER_TOOLS = frozenset({
    "tools/testing/tests/Browser/checkout-anchor-store.py",
    "tools/testing/tests/Browser/checkout-candidate-builder.py",
    "tools/testing/tests/Browser/checkout-coordinator.py",
    "tools/testing/tests/Browser/checkout-integrity-tests.php",
    "tools/testing/tests/Browser/checkout-session.browser.mjs",
    "tools/testing/tests/Browser/checkout-supervisor.py",
    "tools/testing/tests/Browser/https-loopback-proxy.py",
    "tools/testing/tests/Browser/serve-checkout-session.php",
    "tools/testing/tests/Browser/test_checkout_anchor_store.py",
    "tools/testing/tests/Browser/test_checkout_candidate_builder.py",
    "tools/testing/tests/Browser/test_checkout_coordinator.py",
    "tools/testing/tests/Browser/test_checkout_supervisor.py",
})
REQUIRED_SOURCE = frozenset({
    "artisan",
    "composer.json",
    "composer.lock",
    "tests/TestCase.php",
    "vendor/autoload.php",
    "app/Http/Controllers/CheckoutSessionController.php",
    "app/Actions/Integrations/CheckoutSessionLifecycle.php",
    "app/Services/Integrations/CheckoutSummaryComposer.php",
    "app/Data/Integrations/CheckoutSummary.php",
    "tests/Support/AssessmentAccessFixture.php",
    "tests/Support/AssessmentBillingFixture.php",
    *ASSET_REVIEW_FILES,
    *SCRIPT_SOURCE_FILES,
    *BROWSER_TOOLS,
})
CONFIG_KEYS = frozenset({
    "directory", "manifest", *ALL_TOOL_KEYS, "tool_hashes", "asset_delivery_review"
})
CLI_SUFFIX = "31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js"
BROWSER_SUFFIX = "ms-playwright/chromium-1234/chrome-win64/chrome.exe"
BROWSER_LAUNCH_ARGS = (
    "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND",
    "--no-proxy-server",
    "--disable-background-networking",
)
_DIGEST = re.compile(r"[a-f0-9]{64}")
_REVISION = re.compile(r"[a-f0-9]{40}")
_DESTINATION = re.compile(r"oncam-checkout-[a-f0-9]{32}")
_CODE_ROOTS = {
    "app": ".php",
    "bootstrap": ".php",
    "config": ".php",
    "database/migrations": ".php",
    "database/factories": ".php",
    "routes": ".php",
    "resources/views": ".php",
    "tests/Support": ".php",
}
_ROOT_FILES = frozenset({"artisan", "composer.json", "composer.lock", "tests/TestCase.php"})
_MAX_FILE_BYTES = 256 * 1024 * 1024
_MAX_MANIFEST_FILES = 50_000
_FILE_ATTRIBUTE_REPARSE_POINT = 0x400
INCOMPLETE_MARKER = "integrity-invalid"


class CandidateRefused(Exception):
    """Fixed refusal codes; never include paths, file bytes, or private material."""


def _approved_tool_path(value, suffix):
    return str(value).replace("\\", "/").endswith("/" + suffix)


def _digest(value):
    return hashlib.sha256(value).hexdigest()


def _json_bytes(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


def _is_reparse(info):
    return bool(getattr(info, "st_file_attributes", 0) & _FILE_ATTRIBUTE_REPARSE_POINT)


def _identity(info):
    return (info.st_dev, info.st_ino, stat.S_IFMT(info.st_mode),
            getattr(info, "st_file_attributes", 0))


def _directory_identity(path, code):
    try:
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or path.is_symlink() or _is_reparse(info) \
                or path.resolve() != path.absolute():
            raise CandidateRefused(code)
        return _identity(info)
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused(code) from None


def _revalidate_directory(path, identity, code):
    if _directory_identity(path, code) != identity:
        raise CandidateRefused(code)


def _file_identity(path, code):
    try:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or path.is_symlink() or _is_reparse(info) \
                or path.resolve() != path.absolute():
            raise CandidateRefused(code)
        return _identity(info)
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused(code) from None


def _revalidate_file(path, identity, code):
    if _file_identity(path, code) != identity:
        raise CandidateRefused(code)


def _canonical_directory(value, code):
    try:
        raw = os.fspath(value)
        if not isinstance(raw, str) or "\0" in raw:
            raise CandidateRefused(code)
        path = Path(raw)
        if not path.is_absolute():
            raise CandidateRefused(code)
        _directory_identity(path, code)
        return path
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused(code) from None


def _relative_path(value):
    if type(value) is not str or not value or "\0" in value or "\\" in value \
            or value.startswith("/") or value.endswith("/") or "//" in value:
        raise CandidateRefused("manifest_shape")
    parts = value.split("/")
    if any(part in ("", ".", "..") for part in parts):
        raise CandidateRefused("manifest_shape")
    return parts


def _allowed_source(relative):
    if relative in _ROOT_FILES or relative in BROWSER_TOOLS or relative in ASSET_REVIEW_FILES \
            or relative in SCRIPT_SOURCE_FILES:
        return True
    if relative.startswith("vendor/"):
        return relative != "vendor/" and "/.env" not in relative and not relative.endswith("/.env")
    for root, suffix in _CODE_ROOTS.items():
        if relative.startswith(root + "/") and relative.endswith(suffix):
            return root != "bootstrap" or not relative.startswith("bootstrap/cache/")
    return False


def _assert_tree_component(path):
    try:
        info = path.lstat()
        if path.is_symlink() or _is_reparse(info):
            raise CandidateRefused("source_identity")
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused("source_identity") from None


def _inventory(source_root):
    found = set()

    def add_file(path, relative):
        _assert_tree_component(path)
        try:
            if not stat.S_ISREG(path.lstat().st_mode):
                raise CandidateRefused("source_identity")
        except CandidateRefused:
            raise
        except Exception:
            raise CandidateRefused("source_identity") from None
        found.add(relative)

    for relative in sorted(_ROOT_FILES | BROWSER_TOOLS | frozenset(ASSET_REVIEW_FILES) | SCRIPT_SOURCE_FILES):
        path = source_root / Path(*relative.split("/"))
        if path.exists() or path.is_symlink():
            for parent in path.parents:
                if parent == source_root.parent:
                    break
                _assert_tree_component(parent)
            add_file(path, relative)

    for root, suffix in {**_CODE_ROOTS, "vendor": None}.items():
        directory = source_root / Path(*root.split("/"))
        if not directory.exists():
            continue
        _assert_tree_component(directory)
        for current, directories, files in os.walk(directory, followlinks=False):
            current_path = Path(current)
            _assert_tree_component(current_path)
            for name in list(directories):
                _assert_tree_component(current_path / name)
            for name in files:
                path = current_path / name
                relative = path.relative_to(source_root).as_posix()
                if suffix is None or relative.endswith(suffix):
                    if not _allowed_source(relative):
                        raise CandidateRefused("manifest_policy")
                    add_file(path, relative)
    return found


def _open_verified(path, expected, code="source_identity"):
    flags = os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        before = path.lstat()
        if path.is_symlink() or _is_reparse(before) or not stat.S_ISREG(before.st_mode) \
                or before.st_size > _MAX_FILE_BYTES:
            raise CandidateRefused(code)
        descriptor = os.open(path, flags)
        after = os.fstat(descriptor)
        if (before.st_dev, before.st_ino) != (after.st_dev, after.st_ino) \
                or not stat.S_ISREG(after.st_mode) or after.st_size > _MAX_FILE_BYTES:
            os.close(descriptor)
            raise CandidateRefused(code)
        return descriptor, _identity(after)
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused(code) from None


def _hash_verified(path, expected, guard=None, code="source_identity"):
    if guard is not None:
        guard.validate_parent(path.parent)
    descriptor, identity = _open_verified(path, expected, code)
    checksum = hashlib.sha256()
    total = 0
    try:
        while True:
            chunk = os.read(descriptor, 1024 * 1024)
            if not chunk:
                break
            total += len(chunk)
            if total > _MAX_FILE_BYTES:
                raise CandidateRefused("source_identity")
            checksum.update(chunk)
    finally:
        _revalidate_file(path, identity, code)
        os.close(descriptor)
    if guard is not None:
        guard.validate_parent(path.parent)
    if checksum.hexdigest() != expected:
        raise CandidateRefused("source_digest")


class _DestinationGuard:
    def __init__(self, parent, parent_identity, target, target_identity):
        self.parent = parent
        self.parent_identity = parent_identity
        self.target = target
        self.directories = {target: target_identity}

    def validate(self):
        _revalidate_directory(self.parent, self.parent_identity, "destination_identity")
        _revalidate_directory(self.target, self.directories[self.target], "destination_identity")

    def ensure_directory(self, path):
        try:
            relative = path.relative_to(self.target)
        except ValueError:
            raise CandidateRefused("destination_identity") from None
        current = self.target
        for part in relative.parts:
            current = current / part
            self.validate()
            if current not in self.directories:
                try:
                    current.mkdir()
                except FileExistsError:
                    raise CandidateRefused("destination_identity") from None
                except Exception:
                    raise CandidateRefused("candidate_write") from None
                self.directories[current] = _directory_identity(current, "destination_identity")
            _revalidate_directory(current, self.directories[current], "destination_identity")
        self.validate()

    def validate_parent(self, path):
        self.validate()
        current = path
        while True:
            identity = self.directories.get(current)
            if identity is None:
                raise CandidateRefused("destination_identity")
            _revalidate_directory(current, identity, "destination_identity")
            if current == self.target:
                break
            current = current.parent


def _write_new(path, value, guard):
    guard.validate_parent(path.parent)
    descriptor = None
    try:
        descriptor = os.open(
            path,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_BINARY", 0),
            0o600,
        )
        opened = _identity(os.fstat(descriptor))
        _revalidate_file(path, opened, "destination_identity")
        guard.validate_parent(path.parent)
        view = memoryview(value)
        while view:
            written = os.write(descriptor, view)
            if written <= 0:
                raise OSError("short write")
            view = view[written:]
        os.fsync(descriptor)
        if _identity(os.fstat(descriptor)) != opened:
            raise CandidateRefused("destination_identity")
        _revalidate_file(path, opened, "destination_identity")
        guard.validate_parent(path.parent)
        return opened
    finally:
        if descriptor is not None:
            os.close(descriptor)


def _copy_verified(source, target, expected, guard):
    guard.validate_parent(target.parent)
    source_descriptor, source_identity = _open_verified(source, expected)
    target_descriptor = None
    checksum = hashlib.sha256()
    try:
        target_descriptor = os.open(
            target,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_BINARY", 0),
            0o600,
        )
        target_identity = _identity(os.fstat(target_descriptor))
        _revalidate_file(target, target_identity, "destination_identity")
        guard.validate_parent(target.parent)
        while True:
            chunk = os.read(source_descriptor, 1024 * 1024)
            if not chunk:
                break
            checksum.update(chunk)
            view = memoryview(chunk)
            while view:
                written = os.write(target_descriptor, view)
                if written <= 0:
                    raise OSError("short write")
                view = view[written:]
        os.fsync(target_descriptor)
        if _identity(os.fstat(target_descriptor)) != target_identity:
            raise CandidateRefused("destination_identity")
        _revalidate_file(source, source_identity, "source_identity")
        _revalidate_file(target, target_identity, "destination_identity")
        guard.validate_parent(target.parent)
    finally:
        os.close(source_descriptor)
        if target_descriptor is not None:
            os.close(target_descriptor)
    if checksum.hexdigest() != expected:
        raise CandidateRefused("source_digest")
    _hash_verified(target, expected, guard, "destination_identity")
    _revalidate_file(target, target_identity, "destination_identity")
    guard.validate_parent(target.parent)


def _validated_manifest(value, source_root):
    if type(value) is not dict or not 10 <= len(value) <= _MAX_MANIFEST_FILES:
        raise CandidateRefused("manifest_shape")
    lowered = set()
    manifest = {}
    for relative, expected in value.items():
        _relative_path(relative)
        folded = relative.casefold()
        if folded in lowered or not _allowed_source(relative) or type(expected) is not str \
                or _DIGEST.fullmatch(expected) is None:
            raise CandidateRefused("manifest_shape")
        lowered.add(folded)
        manifest[relative] = expected
    if not REQUIRED_SOURCE.issubset(manifest) or set(manifest) != _inventory(source_root):
        raise CandidateRefused("manifest_inventory")
    for relative, expected in manifest.items():
        _hash_verified(source_root / Path(*relative.split("/")), expected)
    return dict(sorted(manifest.items()))


def _validated_tools(value):
    if type(value) is not dict or set(value) != set(EXTERNAL_TOOLS):
        raise CandidateRefused("tool_shape")
    result = {}
    for name in EXTERNAL_TOOLS:
        item = value[name]
        if type(item) is not dict or set(item) != {"path", "sha256"} \
                or type(item["sha256"]) is not str or _DIGEST.fullmatch(item["sha256"]) is None:
            raise CandidateRefused("tool_shape")
        path = _canonical_file(item["path"], "tool_identity")
        if name == "cli" and not _approved_tool_path(path.absolute(), CLI_SUFFIX):
            raise CandidateRefused("tool_path")
        if name == "browser" and not _approved_tool_path(path.absolute(), BROWSER_SUFFIX):
            raise CandidateRefused("tool_path")
        _hash_verified(path, item["sha256"])
        result[name] = {"path": str(path.absolute()), "sha256": item["sha256"]}
    return result


def _canonical_file(value, code):
    try:
        raw = os.fspath(value)
        if not isinstance(raw, str) or "\0" in raw:
            raise CandidateRefused(code)
        path = Path(raw)
        if not path.is_absolute() or path.resolve() != path.absolute():
            raise CandidateRefused(code)
        _assert_tree_component(path)
        if not stat.S_ISREG(path.lstat().st_mode):
            raise CandidateRefused(code)
        return path
    except CandidateRefused as error:
        if str(error) == "source_identity":
            raise CandidateRefused(code) from None
        raise
    except Exception:
        raise CandidateRefused(code) from None


def _validated_runtime(value):
    if type(value) is not dict or set(value) != {"ini", "cert", "key"}:
        raise CandidateRefused("runtime_shape")
    result = {}
    for name in ("ini", "cert", "key"):
        item = value[name]
        if type(item) is not dict or set(item) != {"bytes", "sha256"} \
                or type(item["bytes"]) is not bytes or not 0 < len(item["bytes"]) <= 65536 \
                or b"\0" in item["bytes"] or type(item["sha256"]) is not str \
                or _DIGEST.fullmatch(item["sha256"]) is None or _digest(item["bytes"]) != item["sha256"]:
            raise CandidateRefused("runtime_shape")
        result[name] = bytes(item["bytes"])
    try:
        result["ini"].decode("utf-8")
    except UnicodeDecodeError:
        raise CandidateRefused("runtime_shape") from None
    if b"-----BEGIN CERTIFICATE-----" not in result["cert"] \
            or b"-----END CERTIFICATE-----" not in result["cert"]:
        raise CandidateRefused("certificate_shape")
    prefix = b"-----BEGIN "
    suffix = b"-----END "
    private_key = b"PRIVATE " + b"KEY"
    labels = (private_key, b"RSA " + private_key, b"ENCRYPTED " + private_key)
    key_pairs = tuple(
        (prefix + label + b"-----", suffix + label + b"-----")
        for label in labels
    )
    if not any(begin in result["key"] and end in result["key"] for begin, end in key_pairs):
        raise CandidateRefused("certificate_shape")
    return result


def _revalidate_certificate_file(path, identity, expected, guard):
    guard.validate_parent(path.parent)
    _revalidate_file(path, identity, "destination_identity")
    try:
        _hash_verified(path, expected, guard, "destination_identity")
    except CandidateRefused as error:
        if str(error) == "source_digest":
            raise CandidateRefused("destination_identity") from None
        raise
    _revalidate_file(path, identity, "destination_identity")


def _reject_encrypted_key_password():
    raise CandidateRefused("certificate_semantics")


def _validated_certificate_pair(cert_path, cert_identity, cert_hash,
                                key_path, key_identity, key_hash, guard):
    """Validate parseability and key match only; PKI policy remains a runtime gate."""
    _revalidate_certificate_file(cert_path, cert_identity, cert_hash, guard)
    _revalidate_certificate_file(key_path, key_identity, key_hash, guard)
    failed = False
    try:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(
            certfile=str(cert_path), keyfile=str(key_path),
            password=_reject_encrypted_key_password,
        )
    except (ssl.SSLError, OSError, ValueError):
        failed = True
    finally:
        _revalidate_certificate_file(cert_path, cert_identity, cert_hash, guard)
        _revalidate_certificate_file(key_path, key_identity, key_hash, guard)
    if failed:
        raise CandidateRefused("certificate_semantics")


def _validated_review(value, manifest):
    if type(value) is not dict or set(value) != set(ASSET_REVIEW_FILES):
        raise CandidateRefused("asset_review")
    if any(type(item) is not str or _DIGEST.fullmatch(item) is None
           or manifest.get(name) != item for name, item in value.items()):
        raise CandidateRefused("asset_review")
    return {name: value[name] for name in ASSET_REVIEW_FILES}


def build_candidate(*, destination, candidate_parent, source_root, expected_manifest,
                    source_revision, tools, runtime_files, asset_delivery_review):
    """Build preparation artifacts only; fixtures and runtime activation stay absent."""
    parent = _canonical_directory(candidate_parent, "destination_scope")
    system_temp = _canonical_directory(Path(tempfile.gettempdir()).absolute(), "destination_scope")
    parent_identity = _directory_identity(parent, "destination_scope")
    if parent.absolute() != system_temp.absolute() \
            or parent_identity != _directory_identity(system_temp, "destination_scope"):
        raise CandidateRefused("destination_scope")
    source = _canonical_directory(source_root, "source_identity")
    try:
        raw_destination = os.fspath(destination)
        if not isinstance(raw_destination, str) or "\0" in raw_destination:
            raise CandidateRefused("destination_scope")
        target = Path(raw_destination)
        if not target.is_absolute() or target.parent.absolute() != parent.absolute() \
                or _DESTINATION.fullmatch(target.name) is None or target == source \
                or source in target.parents or target in source.parents:
            raise CandidateRefused("destination_scope")
        if target.exists() or target.is_symlink():
            raise CandidateRefused("destination_freshness")
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused("destination_scope") from None
    if type(source_revision) is not str or _REVISION.fullmatch(source_revision) is None:
        raise CandidateRefused("source_revision")

    manifest = _validated_manifest(expected_manifest, source)
    reviewed_tools = _validated_tools(tools)
    runtime = _validated_runtime(runtime_files)
    review = _validated_review(asset_delivery_review, manifest)
    _revalidate_directory(parent, parent_identity, "destination_identity")

    try:
        target.mkdir(mode=0o700)
    except FileExistsError:
        raise CandidateRefused("destination_freshness") from None
    except Exception:
        raise CandidateRefused("candidate_write") from None

    try:
        target_identity = _directory_identity(target, "destination_identity")
        _revalidate_directory(parent, parent_identity, "destination_identity")
        guard = _DestinationGuard(parent, parent_identity, target, target_identity)
        incomplete = target / INCOMPLETE_MARKER
        incomplete_identity = _write_new(incomplete, b"candidate-incomplete\n", guard)
        output_source = target / "source"
        guard.ensure_directory(output_source)
        for relative, expected in manifest.items():
            output = output_source / Path(*relative.split("/"))
            guard.ensure_directory(output.parent)
            _copy_verified(source / Path(*relative.split("/")), output, expected, guard)

        manifest_bytes = _json_bytes(manifest)
        _write_new(target / "source-manifest.json", manifest_bytes, guard)
        _write_new(target / "source-revision.txt", (source_revision + "\n").encode("ascii"), guard)
        _write_new(target / "runtime.ini", runtime["ini"], guard)
        cert_path = target / "cert.pem"
        key_path = target / "key.pem"
        cert_identity = _write_new(cert_path, runtime["cert"], guard)
        key_identity = _write_new(key_path, runtime["key"], guard)
        _validated_certificate_pair(
            cert_path, cert_identity, _digest(runtime["cert"]),
            key_path, key_identity, _digest(runtime["key"]), guard
        )
        browser_config = {
            "browser": {
                "contextOptions": {"offline": True, "serviceWorkers": "block"},
                "isolated": True,
                "launchOptions": {
                    "args": list(BROWSER_LAUNCH_ARGS),
                    "executablePath": reviewed_tools["browser"]["path"],
                    "headless": True,
                },
                "timeouts": {"action": 30000, "navigation": 30000},
            }
        }
        browser_bytes = _json_bytes(browser_config)
        _write_new(target / "browser-config.json", browser_bytes, guard)

        local_paths = {
            "ini": target / "runtime.ini",
            "browser_config": target / "browser-config.json",
            "cert": target / "cert.pem",
            "key": target / "key.pem",
        }
        local_hashes = {
            "ini": _digest(runtime["ini"]),
            "browser_config": _digest(browser_bytes),
            "cert": _digest(runtime["cert"]),
            "key": _digest(runtime["key"]),
        }
        config = {
            "directory": str(target.absolute()),
            "manifest": _digest(manifest_bytes),
            **{name: reviewed_tools[name]["path"] for name in EXTERNAL_TOOLS},
            **{name: str(local_paths[name].absolute()) for name in RUN_LOCAL_FILES},
            "tool_hashes": {
                **{name: reviewed_tools[name]["sha256"] for name in EXTERNAL_TOOLS},
                **local_hashes,
            },
            "asset_delivery_review": review,
        }
        for name, expected in config["tool_hashes"].items():
            local_guard = guard if name in RUN_LOCAL_FILES else None
            code = "destination_identity" if local_guard is not None else "source_identity"
            _hash_verified(Path(config[name]), expected, local_guard, code)
        _hash_verified(target / "source-manifest.json", config["manifest"], guard,
                       "destination_identity")
        for relative, expected in manifest.items():
            _hash_verified(output_source / Path(*relative.split("/")), expected, guard,
                           "destination_identity")

        pending = target / "supervisor-config.pending"
        pending_identity = _write_new(pending, _json_bytes(config), guard)
        guard.validate_parent(target)
        _revalidate_file(pending, pending_identity, "destination_identity")
        final = target / "supervisor-config.json"
        if final.exists() or final.is_symlink():
            raise CandidateRefused("destination_identity")
        os.rename(pending, target / "supervisor-config.json")
        guard.validate_parent(target)
        _revalidate_file(final, pending_identity, "destination_identity")
        _revalidate_file(incomplete, incomplete_identity, "destination_identity")
        guard.validate_parent(target)
        os.unlink(incomplete)
        return copy.deepcopy(config)
    except CandidateRefused:
        raise
    except Exception:
        raise CandidateRefused("candidate_write") from None
