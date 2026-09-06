"""Cooperative synthetic checkout supervisor. Import has no runtime side effects."""
from __future__ import annotations

import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import tempfile
import time

_HERE = Path(__file__).resolve().parent
ACL_POLICY_FILE = "tools/testing/tests/Browser/checkout-windows-acl-policy-v1.json"
ACL_POLICY_DIGEST = "a63c221764f73a54e87513fc91cded6b3fa16825138f6b24b6118132829f4eeb"


def _load_acl_policy_module():
    try:
        spec = importlib.util.spec_from_file_location(
            "checkout_acl_policy_supervisor", _HERE / "checkout-acl-policy.py"
        )
        if spec is None or spec.loader is None:
            raise ValueError("module")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        if module.ACL_POLICY_FILE != Path(ACL_POLICY_FILE).name \
                or module.ACL_POLICY_DIGEST != ACL_POLICY_DIGEST:
            raise ValueError("binding")
        return module
    except Exception:
        raise RuntimeError("ACL policy module unavailable") from None


acl_policy_module = _load_acl_policy_module()


def _load_acl_attestation_module():
    try:
        spec = importlib.util.spec_from_file_location(
            "checkout_acl_attestation_supervisor", _HERE / "checkout-acl-attestation.py"
        )
        if spec is None or spec.loader is None:
            raise ValueError("module")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        required = (
            "canonical_request", "request_digest", "decode_evidence",
            "canonical_evidence", "validate_boundary_pair",
        )
        if module.POLICY_DIGEST != ACL_POLICY_DIGEST \
                or module.MAX_REQUEST_BYTES != 16 * 1024 \
                or module.MAX_EVIDENCE_BYTES != 32 * 1024 \
                or any(not callable(getattr(module, name, None)) for name in required):
            raise ValueError("binding")
        return module, {name: getattr(module, name) for name in required}
    except Exception:
        raise RuntimeError("ACL attestation module unavailable") from None


acl_attestation_module, _ACL_CODEC_FUNCTIONS = _load_acl_attestation_module()
_ACL_CODEC_MODULE = acl_attestation_module
_ACL_CODEC_TYPE = type(acl_attestation_module)

PORTS = (8126, 443)
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
HARNESS = "tools/testing/tests/Browser/serve-checkout-session.php"
DRIVER = "tools/testing/tests/Browser/checkout-session.browser.mjs"
JOURNAL = "ownership-journal.head"
JOURNAL_TEMP = "ownership-journal.tmp"
JOURNAL_PREFIX = "ownership-journal-"
JOURNAL_LIMIT = 1024
CLI_SUFFIX = "31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js"
BROWSER_SUFFIX = "ms-playwright/chromium-1234/chrome-win64/chrome.exe"
BROWSER_LAUNCH_ARGS = (
    "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND",
    "--no-proxy-server",
    "--disable-background-networking",
)
CONFIG_TOOL_KEYS = (
    "php", "python", "node", "powershell", "cli", "browser", "ini",
    "browser_config", "cert", "key",
)
CONFIG_KEYS = frozenset({
    "directory", "manifest", *CONFIG_TOOL_KEYS, "tool_hashes", "asset_delivery_review",
    "acl_policy_digest",
})
FULL_MATRIX_KEYS = (
    "checks", "exchangePosts", "hostileForms", "opaqueNetworkBlocks",
    "expectedSandboxInstrumentationErrors", "controlledNetworkEntries",
    "credentialMaterialRecorded", "screenshotsContainingCredentials",
    "fullBusinessPostcondition", "historyObservations",
    "immediateDeliveredDOMRemovalClaimed",
)
FULL_MATRIX_CHECKS = (
    "two controlled cross-site origins and exact body-only exchange",
    "real Laravel Lax login cookie omitted on POST and authority preserved",
    "exact host-only Secure HttpOnly Lax checkout cookies and private headers",
    "exact inert checkout-summary-v2, mandatory DASS-21, escaped DOM and CSP without executable application script in the default-off checkout state",
    "own frozen amount and partial access without parent/peer/invoice disclosure",
    "fixation/replay/history/refresh/shared-tab stale-CSRF/recovery fenced",
    "CSRF projection, invalid channels, progressive and no-JS logout",
    "six foreign/opaque/sibling native forms denied; one LOGOUT audit and real login preserved",
    "expiry and scope revocation clear credentials",
    "wrong origin and host fail closed",
    "desktop 1280, mobile 390/320, keyboard, no unexpected console/network errors; opaque limits counted",
)
POSITIVE_TICK = re.compile(r"[1-9][0-9]{0,19}")
JOURNAL_ROLES = frozenset({"php", "tls", "browser_launcher", "browser", "command", "descendant"})
UNCERTAINTY_CATEGORIES = frozenset({"pid_reuse", "parent_missing", "parent_identity_mismatch",
                                   "child_tick_invalid", "identity_probe_failed",
                                   "postcheck_live_process", "cleanup_exception"})
_FILE_ATTRIBUTE_REPARSE_POINT = 0x400
_BROWSER_CONFIG_LIMIT = 65536


class Refused(Exception):
    """Only fixed stage codes are returned, never subprocess output."""


def canonical_tick(value):
    return type(value) is str and POSITIVE_TICK.fullmatch(value) is not None


def _approved_tool_path(value, suffix):
    return str(value).replace("\\", "/").endswith("/" + suffix)


def _validated_asset_review(value, manifest=None):
    if type(value) is not dict or set(value) != set(ASSET_REVIEW_FILES) \
            or any(type(item) is not str or re.fullmatch(r"[a-f0-9]{64}", item) is None
                   for item in value.values()):
        raise Refused("asset_delivery_review_required")
    if manifest is not None and (type(manifest) is not dict
                                 or any(manifest.get(name) != value[name]
                                        for name in ASSET_REVIEW_FILES)):
        raise Refused("asset_overlay_mismatch")
    return {name: value[name] for name in ASSET_REVIEW_FILES}


def _validated_browser_config(raw, expected_browser):
    """Decode only the exact offline browser schema emitted by the candidate builder."""
    try:
        if type(raw) is not bytes or not 0 < len(raw) <= _BROWSER_CONFIG_LIMIT \
                or type(expected_browser) is not str:
            raise ValueError("shape")

        def object_without_duplicates(pairs):
            result = {}
            for key, value in pairs:
                if key in result:
                    raise ValueError("duplicate")
                result[key] = value
            return result

        def reject_nonfinite(value):
            raise ValueError("nonfinite")

        document = json.loads(
            raw.decode("utf-8", errors="strict"),
            object_pairs_hook=object_without_duplicates,
            parse_constant=reject_nonfinite,
        )
        if type(document) is not dict or set(document) != {"browser"}:
            raise ValueError("shape")
        browser = document["browser"]
        if type(browser) is not dict or set(browser) != {
            "contextOptions", "isolated", "launchOptions", "timeouts"
        }:
            raise ValueError("shape")
        context = browser["contextOptions"]
        launch = browser["launchOptions"]
        timeouts = browser["timeouts"]
        if type(context) is not dict or set(context) != {"offline", "serviceWorkers"} \
                or context["offline"] is not True or context["serviceWorkers"] != "block" \
                or browser["isolated"] is not True \
                or type(launch) is not dict \
                or set(launch) != {"args", "executablePath", "headless"} \
                or launch["executablePath"] != expected_browser \
                or type(launch["executablePath"]) is not str \
                or launch["headless"] is not True \
                or type(timeouts) is not dict or set(timeouts) != {"action", "navigation"} \
                or type(timeouts["action"]) is not int or timeouts["action"] != 30000 \
                or type(timeouts["navigation"]) is not int or timeouts["navigation"] != 30000:
            raise ValueError("semantics")
        WindowsRun._validate_browser_launch_args(launch["args"])
        return browser
    except Exception:
        raise Refused("browser_config") from None


def _browser_config_identity(info):
    return (info.st_dev, info.st_ino, stat.S_IFMT(info.st_mode),
            getattr(info, "st_file_attributes", 0))


def _read_validated_browser_config(path, expected_hash, expected_browser, expected_parent):
    descriptor = None
    try:
        if not isinstance(path, Path) or type(expected_hash) is not str \
                or re.fullmatch(r"[a-f0-9]{64}", expected_hash) is None \
                or not isinstance(expected_parent, Path):
            raise ValueError("shape")
        parent = path.parent
        parent_info = parent.lstat()
        parent_identity = _browser_config_identity(parent_info)
        if (not stat.S_ISDIR(parent_info.st_mode) or parent.is_symlink()
                or getattr(parent_info, "st_file_attributes", 0) & _FILE_ATTRIBUTE_REPARSE_POINT
                or parent.resolve() != parent.absolute()
                or parent.absolute() != expected_parent.absolute()):
            raise ValueError("parent")
        before = path.lstat()
        identity = _browser_config_identity(before)
        if (not stat.S_ISREG(before.st_mode) or path.is_symlink()
                or getattr(before, "st_file_attributes", 0) & _FILE_ATTRIBUTE_REPARSE_POINT
                or path.resolve() != path.absolute()
                or not 0 < before.st_size <= _BROWSER_CONFIG_LIMIT):
            raise ValueError("file")
        descriptor = os.open(
            path,
            os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0),
        )
        if _browser_config_identity(os.fstat(descriptor)) != identity:
            raise ValueError("identity")

        def read_bounded():
            chunks, total = [], 0
            while True:
                chunk = os.read(descriptor, min(8192, _BROWSER_CONFIG_LIMIT + 1 - total))
                if not chunk:
                    return b"".join(chunks)
                chunks.append(chunk)
                total += len(chunk)
                if total > _BROWSER_CONFIG_LIMIT:
                    raise ValueError("size")

        raw = read_bounded()
        if hashlib.sha256(raw).hexdigest() != expected_hash:
            raise ValueError("hash")
        browser = _validated_browser_config(raw, expected_browser)
        os.lseek(descriptor, 0, os.SEEK_SET)
        repeated = read_bounded()
        if repeated != raw or hashlib.sha256(repeated).hexdigest() != expected_hash \
                or _browser_config_identity(os.fstat(descriptor)) != identity \
                or _browser_config_identity(path.lstat()) != identity \
                or _browser_config_identity(parent.lstat()) != parent_identity:
            raise ValueError("drift")
        return browser
    except Exception:
        raise Refused("browser_config") from None
    finally:
        if descriptor is not None:
            try:
                os.close(descriptor)
            except Exception:
                raise Refused("browser_config") from None


def _read_validated_acl_policy(path, expected_digest, expected_source):
    descriptor = None
    primary = None
    result = None
    try:
        if not isinstance(path, Path) or type(expected_digest) is not str \
                or expected_digest != ACL_POLICY_DIGEST or not isinstance(expected_source, Path) \
                or path != expected_source / Path(*ACL_POLICY_FILE.split("/")):
            raise ValueError("binding")
        for candidate in (expected_source, path.parent, path):
            candidate_info = candidate.lstat()
            if candidate.is_symlink() \
                    or getattr(candidate_info, "st_file_attributes", 0) \
                    & _FILE_ATTRIBUTE_REPARSE_POINT \
                    or candidate.resolve(strict=True) != candidate.absolute():
                raise ValueError("canonical")
        before = path.lstat()
        source_before = expected_source.lstat()
        if not stat.S_ISREG(before.st_mode) or not stat.S_ISDIR(source_before.st_mode) \
                or not 0 < before.st_size <= acl_policy_module.MAX_POLICY_BYTES:
            raise ValueError("shape")
        flags = os.O_RDONLY | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0)
        descriptor = os.open(path, flags)
        opened = os.fstat(descriptor)
        if not os.path.samestat(before, opened):
            raise ValueError("identity")
        chunks = []
        total = 0
        while True:
            chunk = os.read(descriptor, min(4096, acl_policy_module.MAX_POLICY_BYTES + 1 - total))
            if not chunk:
                break
            chunks.append(chunk)
            total += len(chunk)
            if total > acl_policy_module.MAX_POLICY_BYTES:
                raise ValueError("bounds")
        raw = b"".join(chunks)
        if hashlib.sha256(raw).hexdigest() != expected_digest \
                or acl_policy_module.policy_digest(raw) != expected_digest:
            raise ValueError("digest")
        os.lseek(descriptor, 0, os.SEEK_SET)
        repeated = os.read(descriptor, acl_policy_module.MAX_POLICY_BYTES + 1)
        after = os.fstat(descriptor)
        linked = path.lstat()
        source_after = expected_source.lstat()
        if repeated != raw or not os.path.samestat(opened, after) \
                or not os.path.samestat(after, linked) \
                or not os.path.samestat(source_before, source_after) \
                or path.resolve(strict=True) != path.absolute():
            raise ValueError("drift")
        result = acl_policy_module.validated_policy(raw)
    except BaseException as error:
        primary = error
    if descriptor is not None:
        try:
            os.close(descriptor)
        except BaseException as error:
            if primary is None:
                primary = error
    if primary is not None:
        if isinstance(primary, Exception):
            raise Refused("acl_policy") from None
        raise primary
    return result


def supervise(io, *, mode="smoke", requests=3, budget=180):
    """Sequencing core: the OS adapter owns handles even when launch raises."""
    if mode not in ("smoke", "full") or type(requests) is not int or not 1 <= requests <= 3:
        raise Refused("invalid_options")
    if type(budget) is not int or not 1 <= budget <= 1200:
        raise Refused("invalid_budget")
    deadline = io.clock() + budget
    io.io_deadline = deadline
    stage, claimed, completed, final_clean, primary_failed = "preflight", False, False, False, False
    cleanup_remaining, cleanup_failed, cleanup_status = 15.0, False, "not_required"
    io.postcheck_complete = False
    count = 0
    primary_reason = "preflight"
    result = {"state": "invalid", "accepted": False, "requests": 0, "reason": primary_reason}

    def step(name, operation):
        nonlocal stage
        stage = name
        remaining = deadline - io.clock()
        if remaining <= 0:
            raise Refused("budget")
        value = operation(remaining)
        if io.clock() >= deadline:
            raise Refused("budget")
        return value

    def cleanup():
        # One shared allowance, debited even on interruption; no recursion or fresh reserves.
        nonlocal cleanup_remaining, cleanup_failed, cleanup_status
        started, succeeded = io.clock(), False
        allowance = cleanup_remaining
        try:
            if allowance <= 0:
                return False
            succeeded = (io.cleanup(allowance) is True and not getattr(io, "uncertain", False)
                         and io.clock() - started <= allowance)
            return succeeded
        finally:
            status = "uncertain" if getattr(io, "uncertain", False) else ("clean" if succeeded else "failed")
            priority = {"not_required": 0, "clean": 1, "failed": 2, "uncertain": 3}
            if priority[status] > priority[cleanup_status]:
                cleanup_status = status
            cleanup_remaining = max(0, cleanup_remaining - max(0, io.clock() - started))
            cleanup_failed = cleanup_failed or not succeeded

    try:
        step("preflight", io.preflight)
        step("occupied_port", io.assert_ports_free)
        step("claim", io.claim)
        claimed = True
        step("precheck", lambda remaining: io.harness("integrity-pre", remaining))
        for role in ("php", "tls", "browser"):
            step("spawn_" + role, lambda remaining, role=role: io.launch(role, remaining))
        step("ownership", io.assert_owned)
        step("start", lambda remaining: io.harness("integrity-start", remaining))
        # No driver navigation can occur above this point. Browser opens offline about:blank.
        if mode == "smoke":
            for _ in range(requests):
                count += 1  # conservative count of request permits, including a failed attempt
                elapsed = step("smoke", io.smoke_request)
                if not 0 <= elapsed <= 5:
                    raise Refused("request_target")
        else:
            step("matrix", io.full_matrix)
            stage = "cleanup"
            if not cleanup():
                raise Refused("cleanup")
            step("stop", lambda remaining: io.harness("integrity-stop", remaining, assertions=True))
            step("business", lambda remaining: io.harness("verify", remaining))
            stage = "final_cleanup"
            if not cleanup():
                raise Refused("cleanup")
            step("postcheck", lambda remaining: io.harness("integrity-post", remaining))
        completed = True
    except Exception as error:
        primary_failed = True
        code = str(error) if isinstance(error, Refused) else ""
        primary_reason = ("occupied_port" if code == "occupied_port" else "listener_inspection_failed") \
            if stage == "occupied_port" else stage
    finally:
        # Encloses ALL lifecycle operations, including stop/business/post and BaseException.
        # Interrupts are not caught or converted into successful/ordinary result objects.
        claimed = claimed or io.claimed
        if claimed:
            try:
                try:
                    final_clean = cleanup()
                except Exception:
                    final_clean = False
                if not final_clean and not primary_failed:
                    primary_reason = "cleanup"
            finally:
                # Also runs if final cleanup itself raises KeyboardInterrupt/SystemExit.
                if not completed or not final_clean or cleanup_failed or mode == "smoke":
                    io.invalidate()
    result["requests"] = count
    if completed and final_clean and not cleanup_failed:
        if mode == "smoke":
            result["state"], primary_reason = "incomplete", "fresh_run_required"
        else:
            result["state"], primary_reason = "postverified", "root_review_required"
    categories = getattr(io, "uncertainty_categories", set())
    if not isinstance(categories, (set, frozenset)) or not categories <= UNCERTAINTY_CATEGORIES:
        categories = {"cleanup_exception"} if getattr(io, "uncertain", False) else set()
    result.update(reason=primary_reason, primary_reason=primary_reason, cleanup_status=cleanup_status,
                  uncertainty_categories=sorted(categories))
    return result


def recover(io, *, session, anchor, budget=15):
    """Callable crash-recovery flow; never resumes a run or marks it accepted."""
    if type(budget) is not int or not 1 <= budget <= 60:
        raise Refused("invalid_budget")
    deadline = io.clock() + budget
    io.io_deadline = deadline
    ownership_validated = False
    try:
        io.recover_ownership(session, anchor)
        ownership_validated = True
        remaining = deadline - io.clock()
        if remaining <= 0:
            raise Refused("recovery_budget")
        if io.cleanup(remaining) is not True or getattr(io, "uncertain", False):
            raise Refused("recovery_cleanup")
        return {"state": "recovered_cleanup", "accepted": False}
    finally:
        if ownership_validated or getattr(io, "recovery_hydrated", False):
            io.invalidate()


class WindowsRun:
    """Explicit reviewed paths only. No downloads, shared sessions or default config discovery."""

    def __init__(self, config, *, anchor_publisher=None):
        if anchor_publisher is not None and (
            not callable(anchor_publisher) or not callable(getattr(anchor_publisher, "load", None))
        ):
            raise Refused("anchor_publisher")
        self.c = config
        self.anchor_publisher = anchor_publisher
        self.__expected_anchor_publisher = None
        self.__expected_anchor_publisher_type = None
        self.__expected_anchor_publish_implementation = None
        self.__anchor_publish = None
        self.__anchor_publish_fingerprint = None
        self.__anchor_load = None
        self.__anchor_load_fingerprint = None
        self.lifecycle_lease = None
        self.__expected_lifecycle_lease = None
        self.__expected_lifecycle_lease_type = None
        self.__lease_validate = None
        self.__lease_validate_fingerprint = None
        self.__lease_binding = None
        self.__lease_descriptor_identity = None
        self.__lease_acl_context = None
        self.acl_attestor = None
        self.__expected_acl_attestor = None
        self.__expected_acl_attestor_type = None
        self.__expected_acl_attestor_mro = None
        self.__acl_attestor_methods = {}
        self.__acl_attestor_descriptors = {}
        self.__acl_state = "unbound"
        self.__acl_phase = None
        self.__acl_anchor = None
        self.__acl_execution = None
        self.__acl_execution_token = None
        if anchor_publisher is not None:
            self._pin_anchor_publisher(anchor_publisher)
        self.run = Path(config["directory"])
        self.source = self.run / "source"
        self.env = {}
        self.owned = {}  # PID -> exact creation ticks, including short-lived CLI children.
        self.owned_records = {}
        self.roles = {}
        self.handles = []
        self.claimed = False
        self.uncertainty_categories = set()
        self.postcheck_complete = False
        self.__journal_postchecked = False
        self.session = "checkout-" + secrets.token_hex(16)
        self.owner = None
        self.launch_intents = []
        self.journal_generation = 0
        self.journal_digest = None
        self.recovery_hydrated = False
        self.recovery_validated = False
        self.lifecycle_phase = "new"
        self.io_deadline = float("inf")

    @property
    def uncertain(self):
        return bool(self.uncertainty_categories)

    def bind_anchor_publisher(self, publisher):
        acl_bound = self.__expected_acl_attestor is not None
        if self.lifecycle_phase != "new" or self.claimed is not False \
                or self.anchor_publisher is not None or self.__expected_anchor_publisher is not None \
                or not callable(publisher) \
                or acl_bound and self.__acl_state != "anchor_ready":
            raise Refused("anchor_publisher_bind")
        if not acl_bound:
            if not callable(getattr(publisher, "load", None)):
                raise Refused("anchor_publisher_bind")
            self.anchor_publisher = publisher
            self._pin_anchor_publisher(publisher)
            return
        try:
            if not callable(getattr(publisher, "load", None)):
                raise Refused("anchor_publisher_bind")
            self.anchor_publisher = publisher
            self._pin_anchor_publisher(publisher)
        except BaseException as error:
            self._clear_anchor_publisher_binding()
            if isinstance(error, Refused):
                raise
            if isinstance(error, Exception):
                raise Refused("anchor_publisher_bind") from None
            raise
        try:
            self._validated_acl_anchor()
            self._bound_anchor_publisher()
            self.__acl_state = "anchor_consumed"
        except BaseException as error:
            self._clear_anchor_publisher_binding()
            self._acl_fail(error)

    def _clear_anchor_publisher_binding(self):
        self.anchor_publisher = None
        self.__expected_anchor_publisher = None
        self.__expected_anchor_publisher_type = None
        self.__expected_anchor_publish_implementation = None
        self.__anchor_publish = None
        self.__anchor_publish_fingerprint = None
        self.__anchor_load = None
        self.__anchor_load_fingerprint = None

    def bind_lifecycle_lease(self, lease):
        if self.lifecycle_phase != "new" or self.claimed is not False \
                or self.lifecycle_lease is not None or self.__expected_lifecycle_lease is not None:
            raise Refused("lifecycle_lease_bind")
        try:
            validate = lease.validate
            normalized_run = self._normalized_path(str(self.run.absolute()))
            expected_binding = hashlib.sha256(normalized_run.encode("utf-8")).hexdigest()
            descriptor_identity = lease.descriptor_identity
            if not callable(validate) or getattr(validate, "__self__", None) is not lease \
                    or not callable(getattr(validate, "__func__", None)) \
                    or lease.run != normalized_run or lease.binding != expected_binding \
                    or type(descriptor_identity) is not tuple or len(descriptor_identity) != 2 \
                    or any(type(value) is not int or value < 0 for value in descriptor_identity):
                raise Refused("lifecycle_lease_bind")
            document = validate()
            if document != {"version": 1, "run": normalized_run,
                            "leaseBinding": expected_binding}:
                raise Refused("lifecycle_lease_bind")
            self.lifecycle_lease = lease
            self.__expected_lifecycle_lease = lease
            self.__expected_lifecycle_lease_type = type(lease)
            self.__lease_validate = validate
            self.__lease_validate_fingerprint = self._callable_fingerprint(validate)
            self.__lease_binding = expected_binding
            self.__lease_descriptor_identity = descriptor_identity
        except Refused:
            raise
        except Exception:
            raise Refused("lifecycle_lease_bind") from None

    @staticmethod
    def _class_descriptor(cls, name):
        for owner in cls.__mro__:
            if name in owner.__dict__:
                return owner, owner.__dict__[name]
        raise Refused("acl_attestor_identity")

    @staticmethod
    def _narrow_attestor_method(method):
        function = getattr(method, "__func__", None)
        code = getattr(function, "__code__", None)
        if not callable(function) or code is None \
                or function.__defaults__ is not None or function.__kwdefaults__ is not None \
                or function.__closure__ is not None or code.co_argcount != 2 \
                or code.co_posonlyargcount != 0 or code.co_kwonlyargcount != 0 \
                or code.co_flags & (0x04 | 0x08 | 0x20 | 0x80 | 0x200):
            raise Refused("acl_attestor_identity")
        return function, code

    def _required_acl_codec(self):
        if acl_attestation_module is not _ACL_CODEC_MODULE \
                or type(acl_attestation_module) is not _ACL_CODEC_TYPE \
                or acl_attestation_module.POLICY_DIGEST != ACL_POLICY_DIGEST \
                or acl_attestation_module.MAX_REQUEST_BYTES != 16 * 1024 \
                or acl_attestation_module.MAX_EVIDENCE_BYTES != 32 * 1024:
            raise Refused("acl_attestation")
        for name, function in _ACL_CODEC_FUNCTIONS.items():
            if getattr(acl_attestation_module, name, None) is not function:
                raise Refused("acl_attestation")
        return _ACL_CODEC_FUNCTIONS

    @staticmethod
    def _lease_identity(value):
        if type(value) is not tuple or len(value) != 2 \
                or any(type(part) is not int or part < 0 for part in value):
            raise Refused("acl_admission")
        return {"volumeSerial": str(value[0]), "fileId": str(value[1])}

    def _acl_lease_context(self, lease, *, capture=False):
        try:
            coordinator = self._normalized_path(str(Path(lease.directory).absolute()))
            run = self._normalized_path(str(Path(lease.run_directory).absolute()))
            source = self._normalized_path(str(self.source.absolute()))
            lease_path = self._normalized_path(str(Path(lease.path).absolute()))
            normalized_run = self._normalized_path(str(self.run.absolute()))
            context = {
                "coordinatorPath": coordinator,
                "runPath": run,
                "sourcePath": source,
                "leasePath": lease_path,
                "coordinator": self._lease_identity(lease.directory_identity),
                "run": self._lease_identity(lease.run_identity),
                "descriptor": self._lease_identity(lease.descriptor_identity),
            }
            if run != normalized_run or source != run + "/source" \
                    or lease_path != run + "/.checkout-coordinator.lease":
                raise Refused("acl_admission")
            if capture:
                self.__lease_acl_context = context
            elif context != self.__lease_acl_context:
                raise Refused("acl_admission")
            return context
        except Exception:
            raise Refused("acl_admission") from None

    def bind_acl_attestor(self, attestor):
        if self.__acl_state != "unbound" or self.lifecycle_phase != "new" \
                or self.claimed is not False or self.recovery_hydrated \
                or self.owner is not None or self.handles or self.owned or self.owned_records \
                or self.roles or self.launch_intents or self.anchor_publisher is not None \
                or self.__expected_anchor_publisher is not None:
            raise Refused("acl_admission")
        lease = self._required_lifecycle_lease()
        try:
            cls = type(attestor)
            methods = {}
            descriptors = {}
            for name in ("attest", "load", "discard"):
                method = getattr(attestor, name)
                owner, descriptor = self._class_descriptor(cls, name)
                if not callable(method) or getattr(method, "__self__", None) is not attestor \
                        or not callable(getattr(method, "__func__", None)):
                    raise Refused("acl_attestor_identity")
                function, code = self._narrow_attestor_method(method)
                if descriptor is not function:
                    raise Refused("acl_attestor_identity")
                methods[name] = (method, self._callable_fingerprint(method), code)
                descriptors[name] = (owner, descriptor)
            self._required_acl_codec()
            self._acl_lease_context(lease, capture=True)
            self.acl_attestor = attestor
            self.__expected_acl_attestor = attestor
            self.__expected_acl_attestor_type = cls
            self.__expected_acl_attestor_mro = cls.__mro__
            self.__acl_attestor_methods = methods
            self.__acl_attestor_descriptors = descriptors
            self.__acl_state = "bound"
        except Refused:
            raise
        except Exception:
            raise Refused("acl_admission") from None

    def _bound_acl_attestor(self):
        attestor = self.__expected_acl_attestor
        if attestor is None:
            raise Refused("acl_admission")
        cls = type(attestor)
        try:
            if self.acl_attestor is not attestor or cls is not self.__expected_acl_attestor_type \
                    or cls.__mro__ != self.__expected_acl_attestor_mro:
                raise Refused("acl_attestor_identity")
            for name, (captured, fingerprint, expected_code) \
                    in self.__acl_attestor_methods.items():
                owner, descriptor = self._class_descriptor(cls, name)
                expected_owner, expected_descriptor = self.__acl_attestor_descriptors[name]
                current = getattr(attestor, name)
                function, code = self._narrow_attestor_method(current)
                if owner is not expected_owner or descriptor is not expected_descriptor \
                        or descriptor is not function or code is not expected_code \
                        or not self._callable_matches(current, fingerprint):
                    raise Refused("acl_attestor_identity")
            return attestor
        except Refused:
            raise
        except Exception:
            raise Refused("acl_attestor_identity") from None

    def _acl_callback(self, name, *args):
        self._required_acl_codec()
        self._bound_acl_attestor()
        self._acl_lease_context(self._required_lifecycle_lease())
        callback = self.__acl_attestor_methods[name][0]
        primary = None
        result = None
        try:
            result = callback(*args)
        except BaseException as error:
            primary = error
        try:
            self._required_acl_codec()
            self._bound_acl_attestor()
            self._acl_lease_context(self._required_lifecycle_lease())
        except BaseException as error:
            if primary is None:
                primary = error
        if primary is not None:
            raise primary
        return result

    def _discard_acl(self, digest):
        primary = None
        try:
            self._required_acl_codec()
            self._bound_acl_attestor()
            self._acl_lease_context(self._required_lifecycle_lease())
        except BaseException as error:
            primary = error
        try:
            self.__acl_attestor_methods["discard"][0](digest)
        except BaseException as error:
            if primary is None:
                primary = error
        try:
            self._required_acl_codec()
            self._bound_acl_attestor()
            self._acl_lease_context(self._required_lifecycle_lease())
        except BaseException as error:
            if primary is None:
                primary = error
        return primary

    def _acl_fail(self, error, digest=None):
        if digest is not None:
            self._discard_acl(digest)
        self.__acl_state = "exhausted"
        self.__acl_anchor = None
        self.__acl_execution = None
        self.__acl_execution_token = None
        if isinstance(error, Exception):
            raise Refused("acl_attestation") from None
        raise error

    def _validated_acl_anchor(self):
        codec = self._required_acl_codec()
        self._bound_acl_attestor()
        self._acl_lease_context(self._required_lifecycle_lease())
        if type(self.__acl_anchor) is not tuple or len(self.__acl_anchor) != 3:
            raise Refused("acl_attestation")
        request, evidence, raw = self.__acl_anchor
        self._validate_current_acl_request(request, "anchor")
        if codec["canonical_evidence"](evidence, request) != raw:
            raise Refused("acl_attestation")
        return codec, request, evidence, raw

    def _validate_current_acl_request(self, request, boundary):
        if type(request) is not dict or type(boundary) is not str \
                or boundary not in ("anchor", "execution"):
            raise Refused("acl_attestation")
        lease = self._required_lifecycle_lease()
        context = self._acl_lease_context(lease)
        expected = {
            "version": 1,
            "boundary": boundary,
            "phase": self.__acl_phase,
            "session": self.session,
            "configBinding": self._config_binding(self.session),
            "leaseBinding": self.__lease_binding,
            "leaseIdentity": {
                "path": context["leasePath"],
                "coordinator": dict(context["coordinator"]),
                "descriptor": dict(context["descriptor"]),
                "run": dict(context["run"]),
            },
            "policyDigest": ACL_POLICY_DIGEST,
            "challenge": request.get("challenge"),
            "targets": [
                {"role": "coordinator", "path": context["coordinatorPath"]},
                {"role": "run", "path": context["runPath"]},
                {"role": "source", "path": context["sourcePath"]},
            ],
        }
        if request != expected:
            raise Refused("acl_attestation")
        self._required_acl_codec()["canonical_request"](request)

    def _build_acl_request(self, boundary, phase):
        codec = self._required_acl_codec()
        lease = self._required_lifecycle_lease()
        context = self._acl_lease_context(lease)
        challenge = secrets.token_hex(32)
        if type(challenge) is not str or re.fullmatch(r"[a-f0-9]{64}", challenge) is None \
                or self.__acl_anchor is not None \
                and challenge == self.__acl_anchor[0]["challenge"]:
            raise Refused("acl_attestation")
        request = {
            "version": 1,
            "boundary": boundary,
            "phase": phase,
            "session": self.session,
            "configBinding": self._config_binding(self.session),
            "leaseBinding": self.__lease_binding,
            "leaseIdentity": {
                "path": context["leasePath"],
                "coordinator": dict(context["coordinator"]),
                "descriptor": dict(context["descriptor"]),
                "run": dict(context["run"]),
            },
            "policyDigest": ACL_POLICY_DIGEST,
            "challenge": challenge,
            "targets": [
                {"role": "coordinator", "path": context["coordinatorPath"]},
                {"role": "run", "path": context["runPath"]},
                {"role": "source", "path": context["sourcePath"]},
            ],
        }
        codec["canonical_request"](request)
        return request

    def prepare_acl_admission(self, boundary, phase):
        expected = "bound" if boundary == "anchor" else "anchor_consumed"
        if type(boundary) is not str or boundary not in ("anchor", "execution") \
                or type(phase) is not str or phase not in ("fresh", "recovery") \
                or self.__acl_state != expected \
                or boundary == "execution" and phase != self.__acl_phase:
            raise Refused("acl_admission")
        digest = None
        try:
            codec = self._required_acl_codec()
            if boundary == "execution":
                self._validated_acl_anchor()
                self._bound_anchor_publisher()
            request = self._build_acl_request(boundary, phase)
            digest = codec["request_digest"](request)
            raw = self._acl_callback("attest", json.loads(json.dumps(request)))
            evidence = codec["decode_evidence"](raw, request)
            if codec["canonical_evidence"](evidence, request) != raw:
                raise Refused("acl_attestation")
            loaded = self._acl_callback("load", digest)
            if type(loaded) is not bytes or loaded != raw:
                raise Refused("acl_attestation")
            # load is a destructive, one-shot read. A second read must prove absence.
            if self._acl_callback("load", digest) is not None:
                raise Refused("acl_attestation")
            loaded_evidence = codec["decode_evidence"](loaded, request)
            if boundary == "anchor":
                self.__acl_anchor = (json.loads(json.dumps(request)),
                                     json.loads(json.dumps(loaded_evidence)), bytes(loaded))
                self.__acl_phase = phase
                self.__acl_state = "anchor_ready"
            else:
                anchor_request, anchor_evidence, _ = self.__acl_anchor
                codec["validate_boundary_pair"](
                    anchor_request, anchor_evidence, request, loaded_evidence,
                )
                self.__acl_execution = (json.loads(json.dumps(request)),
                                         json.loads(json.dumps(loaded_evidence)), bytes(loaded))
                self.__acl_state = "execution_ready"
            return None
        except BaseException as error:
            self._acl_fail(error, digest)

    def begin_acl_execution(self, phase):
        if type(phase) is not str or phase != self.__acl_phase \
                or self.__acl_state != "execution_ready":
            raise Refused("acl_admission")
        try:
            codec, anchor_request, anchor_evidence, anchor_raw = \
                self._validated_acl_anchor()
            self._bound_anchor_publisher()
            execution_request, execution_evidence, execution_raw = self.__acl_execution
            self._validate_current_acl_request(execution_request, "execution")
            if codec["canonical_evidence"](execution_evidence, execution_request) \
                    != execution_raw:
                raise Refused("acl_attestation")
            codec["validate_boundary_pair"](
                anchor_request, anchor_evidence, execution_request, execution_evidence,
            )
            token = object()
            self.__acl_execution_token = token
            self.__acl_state = "active"
            return token
        except BaseException as error:
            self._acl_fail(error)

    def finish_acl_execution(self, token):
        if self.__acl_state != "active" or token is not self.__acl_execution_token:
            raise Refused("acl_admission")
        primary = None
        try:
            codec, anchor_request, anchor_evidence, anchor_raw = \
                self._validated_acl_anchor()
            self._bound_anchor_publisher()
            execution_request, execution_evidence, execution_raw = self.__acl_execution
            self._validate_current_acl_request(execution_request, "execution")
            if codec["canonical_evidence"](execution_evidence, execution_request) \
                    != execution_raw:
                raise Refused("acl_attestation")
            codec["validate_boundary_pair"](
                anchor_request, anchor_evidence, execution_request, execution_evidence,
            )
        except BaseException as error:
            primary = error
        self.__acl_execution_token = None
        self.__acl_anchor = None
        self.__acl_execution = None
        self.__acl_state = "exhausted"
        if primary is not None:
            if isinstance(primary, Exception):
                raise Refused("acl_attestation") from None
            raise primary

    def _required_lifecycle_lease(self):
        lease = self.__expected_lifecycle_lease
        if lease is None:
            raise Refused("lifecycle_lease")
        try:
            current_validate = lease.validate
            normalized_run = self._normalized_path(str(self.run.absolute()))
            if self.lifecycle_lease is not lease \
                    or type(lease) is not self.__expected_lifecycle_lease_type \
                    or not self._callable_matches(current_validate,
                                                  self.__lease_validate_fingerprint) \
                    or lease.run != normalized_run \
                    or lease.binding != self.__lease_binding \
                    or lease.descriptor_identity != self.__lease_descriptor_identity:
                raise Refused("lifecycle_lease_identity")
            document = self.__lease_validate()
            if document != {"version": 1, "run": normalized_run,
                            "leaseBinding": self.__lease_binding}:
                raise Refused("lifecycle_lease_identity")
            return lease
        except Refused:
            raise
        except Exception:
            raise Refused("lifecycle_lease_identity") from None

    @staticmethod
    def _callable_fingerprint(value):
        bound_self = getattr(value, "__self__", None)
        bound_func = getattr(value, "__func__", None)
        if bound_self is not None and bound_func is not None:
            return ("bound", bound_self, bound_func, type(value))
        if bound_self is not None:
            return ("slot", bound_self, getattr(value, "__name__", None),
                    getattr(value, "__objclass__", None), type(value))
        return ("exact", value, type(value))

    @staticmethod
    def _callable_matches(value, fingerprint):
        if fingerprint[0] == "bound":
            return type(value) is fingerprint[3] \
                and getattr(value, "__self__", None) is fingerprint[1] \
                and getattr(value, "__func__", None) is fingerprint[2]
        if fingerprint[0] == "slot":
            return type(value) is fingerprint[4] \
                and getattr(value, "__self__", None) is fingerprint[1] \
                and getattr(value, "__name__", None) == fingerprint[2] \
                and getattr(value, "__objclass__", None) is fingerprint[3]
        return type(value) is fingerprint[2] and value is fingerprint[1]

    def _pin_anchor_publisher(self, publisher):
        publish = publisher.__call__
        load = publisher.load
        self.__expected_anchor_publisher = publisher
        self.__expected_anchor_publisher_type = type(publisher)
        self.__expected_anchor_publish_implementation = getattr(type(publisher), "__call__", None)
        self.__anchor_publish = publish
        self.__anchor_publish_fingerprint = self._callable_fingerprint(publish)
        self.__anchor_load = load
        self.__anchor_load_fingerprint = self._callable_fingerprint(load)

    def _bound_anchor_publisher(self):
        self._required_lifecycle_lease()
        expected = self.__expected_anchor_publisher
        if expected is None:
            raise Refused("anchor_publisher")
        try:
            current_publish = expected.__call__
            current_load = expected.load
        except Exception:
            raise Refused("anchor_publisher_identity") from None
        if self.anchor_publisher is not expected or type(expected) is not self.__expected_anchor_publisher_type \
                or getattr(type(expected), "__call__", None) is not self.__expected_anchor_publish_implementation \
                or not callable(current_publish) or not callable(current_load) \
                or not self._callable_matches(current_publish, self.__anchor_publish_fingerprint) \
                or not self._callable_matches(current_load, self.__anchor_load_fingerprint):
            raise Refused("anchor_publisher_identity")
        return expected, self.__anchor_publish, self.__anchor_load

    def _mark_uncertain(self, category):
        if category not in UNCERTAINTY_CATEGORIES:
            raise Refused("internal")
        self.uncertainty_categories.add(category)

    clock = staticmethod(time.monotonic)

    @staticmethod
    def _canonical(path):
        if path.is_symlink() or path.resolve() != path.absolute():
            raise Refused("noncanonical")

    def _validate_candidate_config(self):
        try:
            if type(self.c) is not dict or set(self.c) != CONFIG_KEYS:
                raise ValueError("keys")
            if type(self.c["manifest"]) is not str \
                    or re.fullmatch(r"[a-f0-9]{64}", self.c["manifest"]) is None:
                raise ValueError("manifest")
            if type(self.c["acl_policy_digest"]) is not str \
                    or self.c["acl_policy_digest"] != ACL_POLICY_DIGEST:
                raise ValueError("acl_policy")
            hashes = self.c["tool_hashes"]
            if type(hashes) is not dict or set(hashes) != set(CONFIG_TOOL_KEYS):
                raise ValueError("hashes")
            normalized_paths = []
            for key in CONFIG_TOOL_KEYS:
                value = self.c[key]
                if type(value) is not str or value == "" or "\0" in value \
                        or not Path(value).is_absolute() \
                        or value != str(Path(value).absolute()) \
                        or ".." in Path(value).parts \
                        or type(hashes[key]) is not str \
                        or re.fullmatch(r"[a-f0-9]{64}", hashes[key]) is None:
                    raise ValueError("tool")
                normalized_paths.append(self._normalized_path(value))
            if len(set(normalized_paths)) != len(CONFIG_TOOL_KEYS):
                raise ValueError("tool_uniqueness")
            directory = self.c["directory"]
            if type(directory) is not str or directory == "" or "\0" in directory \
                    or not Path(directory).is_absolute() \
                    or ".." in Path(directory).parts \
                    or directory != str(self.run.absolute()):
                raise ValueError("directory")
            local_names = {
                "ini": "runtime.ini",
                "browser_config": "browser-config.json",
                "cert": "cert.pem",
                "key": "key.pem",
            }
            for key, name in local_names.items():
                path = Path(self.c[key])
                if path.name != name or self._normalized_path(str(path.parent.absolute())) \
                        != self._normalized_path(directory):
                    raise ValueError("runtime_scope")
            return _validated_asset_review(self.c["asset_delivery_review"])
        except Exception:
            raise Refused("candidate_config") from None

    def preflight(self, remaining):
        review = self._validate_candidate_config()
        required = ASSET_REVIEW_FILES
        if os.name != "nt" or self.run.parent.resolve() != Path(tempfile.gettempdir()).resolve():
            raise Refused("scope")
        if not re.fullmatch(r"oncam-checkout-[0-9a-f]{32}", self.run.name):
            raise Refused("scope")
        for path in (self.run, self.source):
            self._canonical(path)
            if list(path.glob(".env*")):
                raise Refused("environment")
        if any((self.run / p).exists() for p in
               ("supervisor.json", JOURNAL, JOURNAL_TEMP, "integrity-evidence.json", "integrity-invalid")):
            raise Refused("not_fresh")
        if list(self.run.glob(JOURNAL_PREFIX + "*.json")):
            raise Refused("not_fresh")
        for name in ("browser.sqlite", "baseline.json", "fixtures.json"):
            path = self.run / name
            self._canonical(path)
            if not path.is_file():
                raise Refused("fresh_fixture_required")
        raw = (self.run / "source-manifest.json").read_bytes()
        if hashlib.sha256(raw).hexdigest() != self.c["manifest"]:
            raise Refused("manifest")
        manifest = json.loads(raw)
        if type(manifest) is not dict or manifest.get(ACL_POLICY_FILE) != ACL_POLICY_DIGEST:
            raise Refused("acl_policy")
        _read_validated_acl_policy(
            self.source / Path(*ACL_POLICY_FILE.split("/")),
            self.c["acl_policy_digest"],
            self.source,
        )
        review = _validated_asset_review(review, manifest)
        # This reviewed asset-delivery contract is deliberately absent from the old copy.
        # Presence alone does not authorize a generic static-file router.
        for name in required:
            path = self.source / name
            self._canonical(path)
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            if digest != manifest.get(name) or digest != review[name]:
                raise Refused("asset_overlay_mismatch")
        # Reviewed executable/config hashes are supplied by root, never auto-refreshed here.
        browser_config = None
        for key in ("php", "python", "node", "powershell", "cli", "browser", "ini", "browser_config", "cert", "key"):
            path = Path(self.c[key])
            self._canonical(path)
            if key == "cli" and not _approved_tool_path(path.absolute(), CLI_SUFFIX):
                raise Refused("cli")
            if key == "browser" and not _approved_tool_path(path.absolute(), BROWSER_SUFFIX):
                raise Refused("browser")
            if key in ("ini", "browser_config", "cert", "key") and path.parent.resolve() != self.run.resolve():
                raise Refused("runtime_file_scope")
            if key == "browser_config":
                browser_config = _read_validated_browser_config(
                    path, self.c["tool_hashes"][key], self.c["browser"], self.run
                )
            elif hashlib.sha256(path.read_bytes()).hexdigest() != self.c["tool_hashes"][key]:
                raise Refused("tool_hash")
        if browser_config is None:
            raise Refused("browser_config")
        # Process-local allowlist; do not copy the calling shell's application environment.
        self.env = {key: os.environ[key] for key in ("SystemRoot", "TEMP", "TMP")}
        runtime_home = self.run / "storage/framework/supervisor-home"
        daemon_dir = self.run / "storage/framework/playwright-daemon"
        self.env.update(CI="1", NO_UPDATE_NOTIFIER="1", APP_ENV="testing",
                        LOCALAPPDATA=str(runtime_home), USERPROFILE=str(runtime_home),
                        PWTEST_DAEMON_SESSION_DIR=str(daemon_dir), PWTEST_CLI_GLOBAL_CONFIG=str(runtime_home),
                        ONCAM_CHECKOUT_BROWSER_DIRECTORY=str(self.run), ONCAM_CHECKOUT_BROWSER_PORT="8126",
                        ONCAM_CHECKOUT_BROWSER_MANIFEST_SHA256=self.c["manifest"],
                        ONCAM_CHECKOUT_BROWSER_INTEGRITY_MODE="cooperative-v1")
        self.owner = self._identity(os.getpid())
        self.owner["nonce"] = secrets.token_hex(32)
        self.env["ONCAM_CHECKOUT_BROWSER_OWNER"] = json.dumps(self.owner, separators=(",", ":"))

    @staticmethod
    def _normalized_path(value):
        if not isinstance(value, str) or not Path(value).is_absolute() or "\0" in value:
            raise Refused("journal_shape")
        return os.path.normcase(str(Path(value).absolute())).replace("\\", "/")

    @staticmethod
    def _validate_browser_launch_args(args):
        if type(args) is not list or args != list(BROWSER_LAUNCH_ARGS) \
                or any(type(arg) is not str for arg in args) or len(set(args)) != len(args):
            raise Refused("browser_network_guard")

    @staticmethod
    def _validate_snapshot(rows, relevant):
        if type(rows) is not list or type(relevant) is not set \
                or any(type(pid) is not int or pid <= 0 for pid in relevant):
            raise Refused("snapshot_schema")
        seen = set()
        for row in rows:
            if type(row) is not dict or set(row) != {"pid", "parent", "started", "executable"} \
                    or type(row["pid"]) is not int or row["pid"] <= 0 \
                    or type(row["parent"]) is not int or row["parent"] < 0 \
                    or row["pid"] in seen:
                raise Refused("snapshot_schema")
            started, executable = row["started"], row["executable"]
            inaccessible = started is None and executable is None
            if not inaccessible:
                if not canonical_tick(started) \
                        or not isinstance(executable, str):
                    raise Refused("snapshot_schema")
                try:
                    WindowsRun._normalized_path(executable)
                except Refused:
                    raise Refused("snapshot_schema") from None
            elif started is not None or executable is not None:
                raise Refused("snapshot_schema")
            seen.add(row["pid"])
        current = {row["pid"]: row for row in rows}
        required = set(relevant)
        changed = True
        while changed:
            changed = False
            for row in rows:
                if row["parent"] in required and row["pid"] not in required:
                    required.add(row["pid"])
                    changed = True
        if any(pid in current and (current[pid]["started"] is None or current[pid]["executable"] is None)
               for pid in required):
            raise Refused("snapshot_schema")
        return current

    def _config_binding(self, session):
        review = self._validate_candidate_config()
        keys = CONFIG_TOOL_KEYS
        hashes = self.c.get("tool_hashes")
        if not isinstance(session, str) or not re.fullmatch(r"checkout-[a-f0-9]{32}", session):
            raise Refused("journal_shape")
        if not isinstance(self.c.get("manifest"), str) or not re.fullmatch(r"[a-f0-9]{64}", self.c["manifest"]):
            raise Refused("journal_shape")
        if not isinstance(hashes, dict) or any(
            key not in self.c or key not in hashes or not isinstance(hashes[key], str)
            or not re.fullmatch(r"[a-f0-9]{64}", hashes[key]) for key in keys
        ):
            raise Refused("journal_shape")
        payload = {
            "directory": self._normalized_path(self.c["directory"]),
            "manifest": self.c["manifest"],
            "aclPolicyDigest": self.c["acl_policy_digest"],
            "session": session,
            "tools": {key: {"path": self._normalized_path(self.c[key]), "sha256": hashes[key]}
                      for key in keys},
            "assetDeliveryReview": review,
        }
        return hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()

    @staticmethod
    def _journal_digest(payload):
        body = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()
        return hashlib.sha256(body).hexdigest()

    def _read_claim(self):
        try:
            claim = json.loads((self.run / "supervisor.json").read_text(encoding="utf-8"))
        except Exception:
            raise Refused("journal_claim") from None
        if not isinstance(claim, dict) or set(claim) != {
            "version", "mode", "owner", "session", "configBinding", "journalBinding", "accepted"
        } or claim["version"] != 2 or claim["mode"] != "cooperative" or claim["accepted"] is not False:
            raise Refused("journal_claim")
        owner = claim["owner"]
        if not isinstance(owner, dict) or set(owner) != {"pid", "started", "nonce"} \
                or type(owner["pid"]) is not int or owner["pid"] <= 0 \
                or not canonical_tick(owner["started"]) \
                or not isinstance(owner["nonce"], str) or not re.fullmatch(r"[a-f0-9]{64}", owner["nonce"]):
            raise Refused("journal_claim")
        if not isinstance(claim["journalBinding"], str) or not re.fullmatch(r"[a-f0-9]{64}", claim["journalBinding"]):
            raise Refused("journal_claim")
        return claim

    def _journal_payload(self, claim):
        records = sorted(self.owned_records.values(), key=lambda item: item["pid"])
        return {"version": 1, "run": self._normalized_path(str(self.run.absolute())), "session": self.session,
                "configBinding": self._config_binding(self.session), "launchIntents": list(self.launch_intents),
                "owned": records, "claimDigest": self._journal_digest(claim)}

    def _persist_journal(self):
        if not self.claimed:
            return
        publisher, publish, load = self._bound_anchor_publisher()
        claim = self._read_claim()
        payload = self._journal_payload(claim)
        self._validate_journal_entries(payload)
        if claim["session"] != payload["session"] or claim["configBinding"] != payload["configBinding"]:
            raise Refused("journal_claim")
        generation = self.journal_generation + 1
        if generation > JOURNAL_LIMIT:
            raise Refused("journal_limit")
        previous = self.journal_digest or claim["journalBinding"]
        chained = {**payload, "generation": generation, "prevDigest": previous}
        digest = self._journal_digest(chained)
        document = {**chained, "integrity": digest}
        generation_path = self.run / f"{JOURNAL_PREFIX}{generation:08d}.json"
        try:
            with generation_path.open("x", encoding="utf-8", newline="\n") as file:
                json.dump(document, file, sort_keys=True, separators=(",", ":"))
                file.flush()
                os.fsync(file.fileno())
            with (self.run / JOURNAL).open("x" if generation == 1 else "a", encoding="ascii", newline="\n") as head:
                head.write(f"{generation:08d} {digest}\n")
                head.flush()
                os.fsync(head.fileno())
        except Exception:
            raise Refused("journal_write") from None
        self.journal_generation = generation
        self.journal_digest = digest
        anchor = self.journal_anchor()
        self._validate_anchor(anchor)
        if self._bound_anchor_publisher()[0] is not publisher:
            raise Refused("anchor_publisher_identity")
        try:
            publish(dict(anchor))
        except Exception:
            # The durable generation remains authoritative; managed spawn must stop.
            raise Refused("anchor_publish") from None
        if self._bound_anchor_publisher()[0] is not publisher:
            raise Refused("anchor_publisher_identity")
        try:
            reopened = load()
            self._validate_anchor(reopened)
            if reopened != anchor:
                raise Refused("anchor_publish")
        except Refused:
            raise Refused("anchor_publish") from None
        except Exception:
            raise Refused("anchor_publish") from None
        if self._bound_anchor_publisher()[0] is not publisher:
            raise Refused("anchor_publisher_identity")

    def _publisher_anchor(self):
        publisher, _, load = self._bound_anchor_publisher()
        try:
            anchor = load()
            self._validate_anchor(anchor)
        except Refused:
            raise
        except Exception:
            raise Refused("anchor_publisher") from None
        if self._bound_anchor_publisher()[0] is not publisher:
            raise Refused("anchor_publisher_identity")
        return anchor

    def journal_anchor(self):
        if type(self.journal_generation) is not int or self.journal_generation <= 0 \
                or not isinstance(self.journal_digest, str):
            raise Refused("journal_anchor")
        return {"generation": self.journal_generation, "digest": self.journal_digest}

    @staticmethod
    def _validate_anchor(anchor):
        if not isinstance(anchor, dict) or set(anchor) != {"generation", "digest"} \
                or type(anchor["generation"]) is not int or not 1 <= anchor["generation"] <= JOURNAL_LIMIT \
                or not isinstance(anchor["digest"], str) or not re.fullmatch(r"[a-f0-9]{64}", anchor["digest"]):
            raise Refused("journal_anchor")

    def _read_journal(self, anchor=None):
        claim = self._read_claim()
        if (self.run / JOURNAL_TEMP).exists():
            raise Refused("journal_read")
        try:
            lines = (self.run / JOURNAL).read_text(encoding="ascii").splitlines()
            paths = sorted(self.run.glob(JOURNAL_PREFIX + "*.json"))
        except Exception:
            raise Refused("journal_read") from None
        if not 1 <= len(lines) == len(paths) <= JOURNAL_LIMIT:
            raise Refused("journal_chain")
        previous = claim["journalBinding"]
        latest = None
        for generation, (line, path) in enumerate(zip(lines, paths), 1):
            expected_name = f"{JOURNAL_PREFIX}{generation:08d}.json"
            if path.name != expected_name or not re.fullmatch(r"[0-9]{8} [a-f0-9]{64}", line):
                raise Refused("journal_chain")
            number, head_digest = line.split(" ")
            if int(number) != generation:
                raise Refused("journal_chain")
            try:
                document = json.loads(path.read_text(encoding="utf-8"))
            except Exception:
                raise Refused("journal_read") from None
            if not isinstance(document, dict) or set(document) != {
                "version", "run", "session", "configBinding", "launchIntents", "owned", "claimDigest",
                "generation", "prevDigest", "integrity"
            }:
                raise Refused("journal_shape")
            integrity = document.pop("integrity")
            if integrity != head_digest or integrity != self._journal_digest(document) \
                    or document["generation"] != generation or document["prevDigest"] != previous:
                raise Refused("journal_integrity")
            if document["version"] != 1 or document["run"] != self._normalized_path(str(self.run.absolute())):
                raise Refused("journal_shape")
            if document["session"] != claim["session"] or document["configBinding"] != claim["configBinding"] \
                    or document["claimDigest"] != self._journal_digest(claim):
                raise Refused("journal_claim")
            self._validate_journal_entries(document)
            previous, latest = integrity, document
        self.journal_generation = len(lines)
        self.journal_digest = previous
        if anchor is not None:
            self._validate_anchor(anchor)
            if anchor != self.journal_anchor():
                raise Refused("journal_anchor")
        return latest

    def _validate_journal_entries(self, journal):
        intents = journal["launchIntents"]
        if not isinstance(intents, list):
            raise Refused("journal_shape")
        for intent in intents:
            if not isinstance(intent, dict) or set(intent) != {"role", "executable"} \
                    or intent["role"] not in {"php", "tls", "browser", "browser_launcher", "command", "helper"}:
                raise Refused("journal_shape")
            if self._normalized_path(intent["executable"]) not in self._expected_executables(
                intent["role"]
            ):
                raise Refused("journal_shape")
        records = journal["owned"]
        if not isinstance(records, list) or any(not isinstance(record, dict) for record in records):
            raise Refused("journal_shape")
        seen = {}
        unique_roles = set()
        for record in records:
            parent = record.get("parent")
            if set(record) != {"pid", "started", "role", "parent", "executable"} \
                    or type(record["pid"]) is not int or record["pid"] <= 0 \
                    or not canonical_tick(record["started"]) \
                    or record["role"] not in JOURNAL_ROLES or not isinstance(parent, dict) \
                    or set(parent) != {"pid", "started"} or type(parent["pid"]) is not int or parent["pid"] <= 0 \
                    or not canonical_tick(parent["started"]):
                raise Refused("journal_shape")
            self._normalized_path(record["executable"])
            if record["pid"] in seen:
                raise Refused("journal_shape")
            if record["role"] in {"php", "tls", "browser_launcher", "browser"}:
                if record["role"] in unique_roles:
                    raise Refused("journal_shape")
                unique_roles.add(record["role"])
            seen[record["pid"]] = record["started"]
        if [record["pid"] for record in records] != sorted(seen):
            raise Refused("journal_shape")

    def _push_launch_intent(self, role, executable):
        if role not in {"php", "tls", "browser", "browser_launcher", "command", "helper"}:
            raise Refused("journal_shape")
        intent = {"role": role, "executable": executable}
        self.launch_intents.append(intent)
        self._persist_journal()
        return intent

    def _clear_launch_intent(self, intent):
        if not self.launch_intents or self.launch_intents[-1] != intent:
            raise Refused("journal_shape")
        popped = self.launch_intents.pop()
        try:
            self._persist_journal()
        except BaseException:
            # Conservatively retain uncertainty even if the durable clear reached disk
            # but publishing it failed. A later generation must re-state this intent.
            self.launch_intents.append(popped)
            raise

    def _register_owned(self, identity, role, parent, executable):
        if role not in JOURNAL_ROLES or not isinstance(identity, dict) or set(identity) != {"pid", "started"} \
                or not isinstance(parent, dict) or not {"pid", "started"} <= set(parent):
            raise Refused("journal_shape")
        record = {"pid": identity["pid"], "started": identity["started"], "role": role,
                  "parent": {"pid": parent["pid"], "started": parent["started"]}, "executable": executable}
        candidate = {**self.owned_records, identity["pid"]: record}
        if identity["pid"] in self.owned_records and self.owned_records[identity["pid"]] != record:
            raise Refused("journal_shape")
        self._validate_journal_entries({"launchIntents": [],
                                        "owned": sorted(candidate.values(), key=lambda item: item["pid"])})
        self.owned[identity["pid"]] = identity["started"]
        self.owned_records[identity["pid"]] = record
        self._persist_journal()

    def _expected_executables(self, role):
        keys = {"php": ("php",), "tls": ("python",), "browser_launcher": ("node",),
                "browser": ("browser",), "command": ("php", "node"),
                "helper": ("powershell",),
                "descendant": ("php", "python", "node", "browser")}[role]
        return {self._normalized_path(self.c[key]) for key in keys}

    def recover_ownership(self, session, anchor):
        self._required_lifecycle_lease()
        try:
            return self._recover_ownership(session, anchor)
        except BaseException:
            if self.recovery_hydrated:
                self.invalidate()
            raise

    def _recover_ownership(self, session, anchor):
        try:
            self._validate_candidate_config()
        except Refused:
            raise Refused("recovery_config") from None
        self._validate_anchor(anchor)
        if self._publisher_anchor() != anchor:
            raise Refused("journal_anchor")
        claim = self._read_claim()
        if session != claim["session"]:
            raise Refused("recovery_session")
        try:
            binding = self._config_binding(session)
        except Refused:
            raise Refused("recovery_config") from None
        if binding != claim["configBinding"]:
            raise Refused("recovery_config")
        try:
            for key, expected in self.c["tool_hashes"].items():
                path = Path(self.c[key])
                self._canonical(path)
                if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
                    raise Refused("recovery_config")
        except Exception:
            raise Refused("recovery_config") from None
        journal = self._read_journal(anchor)
        if journal["launchIntents"]:
            # A crash between spawn and exact PID registration cannot be recovered by guessing.
            raise Refused("recovery_incomplete")
        records = {record["pid"]: record for record in journal["owned"]}
        owner = claim["owner"]
        relevant = {owner["pid"], *records, *(record["parent"]["pid"] for record in records.values())}
        # Hydrate the exact persisted graph before the first recovery census. This
        # makes its PowerShell helper intent-only and preserves all records in the
        # journal generations it appends; it does not authorize cleanup yet.
        self.session = session
        self.owner = owner
        self.owned_records = records
        self.owned = {pid: record["started"] for pid, record in records.items()}
        self.roles = {}
        self.recovery_hydrated = True
        self.claimed = True
        self.lifecycle_phase = "recovery"
        current_rows = self._snapshot()
        try:
            current = self._validate_snapshot(current_rows, relevant)
        except Refused:
            raise Refused("recovery_identity") from None
        if current.get(owner["pid"], {}).get("started") == owner["started"]:
            raise Refused("recovery_owner_active")
        live = {}
        for pid, record in records.items():
            row = current.get(pid)
            if row is None:
                continue
            if row.get("started") != record["started"]:
                raise Refused("recovery_identity")
            try:
                actual_executable = self._normalized_path(row.get("executable"))
            except Refused:
                raise Refused("recovery_executable") from None
            if actual_executable != self._normalized_path(record["executable"]) \
                    or self._normalized_path(record["executable"]) not in self._expected_executables(record["role"]):
                raise Refused("recovery_executable")
            if row["parent"] != record["parent"]["pid"]:
                raise Refused("recovery_parent")
            live[pid] = record
        for record in live.values():
            parent = record["parent"]
            row = current.get(parent["pid"])
            if parent["pid"] == owner["pid"]:
                continue
            if row is None or row.get("started") != parent["started"]:
                raise Refused("recovery_parent")
            if parent["pid"] not in records:
                raise Refused("recovery_parent")
        self.owned = {pid: record["started"] for pid, record in live.items()}
        self.owned_records = records
        self.roles = {record["role"]: {"pid": pid, "started": record["started"]}
                      for pid, record in live.items() if record["role"] in {"php", "tls", "browser"}}
        self.recovery_validated = True

    def _clear_journal(self):
        expected_anchor = self.journal_anchor()
        self._read_journal(expected_anchor)
        paths = sorted(self.run.glob(JOURNAL_PREFIX + "*.json"))
        for path in reversed(paths):
            self._canonical(path)
            path.unlink()
        head = self.run / JOURNAL
        self._canonical(head)
        head.unlink()

    def _command(self, args, timeout, *, policy="owned", role="command"):
        valid_policy = type(policy) is str and type(role) is str and (
            (policy == "untracked" and self.claimed is False and self.lifecycle_phase == "new" and role == "helper")
            or (policy == "intent_only" and self.claimed is True
                and self.lifecycle_phase in {"normal", "recovery"} and role == "helper")
            or (policy == "owned" and self.claimed is True and self.lifecycle_phase == "normal"
                and role in {"command", "browser_launcher"})
        )
        if not valid_policy:
            raise Refused("tracking_policy")
        end = min(self.clock() + timeout, self.io_deadline)
        if self.clock() >= end:
            raise Refused("budget")
        tracked = policy != "untracked"
        intent = self._push_launch_intent(role, str(Path(args[0]).absolute())) if tracked else None
        # Real files avoid Windows pipe-read hangs. Output is bounded on capture/poll.
        with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
            process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=output, stderr=errors,
                                       cwd=self.run, env=self.env, creationflags=subprocess.CREATE_NO_WINDOW)
            self.handles.append(process)  # retain even if registration/timeout fails
            try:
                if policy == "owned":
                    try:
                        identity = self._identity(process.pid)
                        self._register_owned(identity, role, self.owner, str(Path(args[0]).absolute()))
                        self._clear_launch_intent(intent)
                    except Exception:
                        self._mark_uncertain("identity_probe_failed")
                        raise
                while process.poll() is None:
                    if self.clock() >= end or os.fstat(output.fileno()).st_size > 262144 or os.fstat(errors.fileno()).st_size > 4096:
                        raise Refused("command")
                    if policy == "owned":
                        self._discover()
                    time.sleep(.02)
                if policy == "owned":
                    self._discover()
                output.seek(0)
                errors.seek(0)
                data = output.read(262145)
                if process.returncode != 0 or len(data) > 262144 or errors.read(4097):
                    raise Refused("command")
                text = data.decode("utf-8-sig")
                if policy == "intent_only":
                    self._clear_launch_intent(intent)
                return text
            finally:
                if process.poll() is None:
                    process.kill()  # Popen handle only, not a searched PID.
                    process.wait(timeout=2)

    def _ps(self, script, timeout=3):
        policy = "intent_only" if self.claimed else "untracked"
        return self._command([self.c["powershell"], "-NoProfile", "-NonInteractive", "-Command", script],
                             timeout, policy=policy, role="helper")

    def _identity(self, pid):
        if type(pid) is not int or pid <= 0:
            raise Refused("identity")
        ticks = self._ps(f"$ErrorActionPreference='Stop'; $p=[Diagnostics.Process]::GetProcessById({pid}); try {{$p.StartTime.ToUniversalTime().Ticks.ToString()}} finally {{$p.Dispose()}}").strip()
        if not canonical_tick(ticks):
            raise Refused("identity")
        return {"pid": pid, "started": ticks}

    def _snapshot(self):
        script = "$ErrorActionPreference='Stop'; $rows=@(Get-CimInstance Win32_Process | ForEach-Object { $p=$null; $t=$null; $x=$null; try {$p=[Diagnostics.Process]::GetProcessById([int]$_.ProcessId); $candidateTick=$p.StartTime.ToUniversalTime().Ticks.ToString(); $candidateExecutable=$p.MainModule.FileName; $t=$candidateTick; $x=$candidateExecutable} catch {$t=$null; $x=$null} finally {if($null -ne $p){$p.Dispose()}}; @{pid=[int]$_.ProcessId; parent=[int]$_.ParentProcessId; started=$t; executable=$x} }); ConvertTo-Json -InputObject $rows -Compress"
        return json.loads(self._ps(script))

    def _discover(self):
        rows = self._snapshot()
        current = self._validate_snapshot(rows, set(self.owned))
        for pid, tick in list(self.owned.items()):
            if pid in current and current[pid]["started"] != tick:
                self._mark_uncertain("pid_reuse")  # Never adopt/kill the new owner's children.
        changed = True
        while changed:
            changed = False
            for row in rows:
                parent = row["parent"]
                if row["pid"] in self.owned or parent not in self.owned:
                    continue
                if parent not in current:
                    # An orphan's numeric PPID alone cannot prove ancestry after PID reuse.
                    self._mark_uncertain("parent_missing")
                    continue
                if current[parent]["started"] != self.owned[parent]:
                    self._mark_uncertain("parent_identity_mismatch")
                    continue
                if not row["started"] or int(row["started"]) < int(self.owned[parent]):
                    self._mark_uncertain("child_tick_invalid")
                    continue
                if self.claimed:
                    executable = row.get("executable")
                    if not isinstance(executable, str) or not Path(executable).is_absolute():
                        self._mark_uncertain("identity_probe_failed")
                        continue
                    parent_record = {"pid": parent, "started": self.owned[parent]}
                    self._register_owned({"pid": row["pid"], "started": row["started"]},
                                         "descendant", parent_record, executable)
                else:
                    self.owned[row["pid"]] = row["started"]
                changed = True
        return current

    def _listeners(self):
        try:
            # Six seconds is scoped to this cold module-backed inspection only. The
            # global lifecycle deadline remains authoritative and debits the time.
            rows = json.loads(self._ps("$ErrorActionPreference='Stop'; $r=@(Get-NetTCPConnection -ErrorAction Stop | Where-Object {$_.State -eq 'Listen' -and $_.LocalPort -in 8126,443} | ForEach-Object {@{pid=[int]$_.OwningProcess; port=[int]$_.LocalPort; address=$_.LocalAddress}}); ConvertTo-Json -InputObject $r -Compress", timeout=6))
            if not isinstance(rows, list) or any(
                not isinstance(row, dict) or set(row) != {"pid", "port", "address"}
                or type(row["pid"]) is not int or row["pid"] <= 0
                or type(row["port"]) is not int or row["port"] not in PORTS
                or not isinstance(row["address"], str) or not 1 <= len(row["address"]) <= 64
                for row in rows
            ):
                raise Refused("listener_inspection_failed")
            return rows
        except Exception:
            raise Refused("listener_inspection_failed") from None

    def assert_ports_free(self, remaining):
        if self._listeners():
            raise Refused("occupied_port")

    def claim(self, remaining):
        self._required_lifecycle_lease()
        if self.lifecycle_phase != "new":
            raise Refused("lifecycle_phase")
        self._bound_anchor_publisher()
        binding = self._config_binding(self.session)
        with (self.run / "supervisor.json").open("x") as file:
            self.claimed = True
            json.dump({"version": 2, "mode": "cooperative", "owner": self.owner, "session": self.session,
                       "configBinding": binding, "journalBinding": secrets.token_hex(32),
                       "accepted": False}, file, sort_keys=True, separators=(",", ":"))
        self.claimed = True
        self.lifecycle_phase = "claiming"
        self._persist_journal()
        self.lifecycle_phase = "normal"

    def _require_normal_run(self):
        if self.claimed is not True or self.lifecycle_phase != "normal" or self.recovery_hydrated:
            raise Refused("lifecycle_phase")

    def harness(self, mode, remaining, assertions=False):
        self._require_normal_run()
        self.io_deadline = self.clock() + remaining
        if assertions:
            self.env["ONCAM_CHECKOUT_BROWSER_ASSERTIONS_PASSED"] = "1"
        if self.roles:
            self.env["ONCAM_CHECKOUT_BROWSER_OWNED_PROCESSES"] = json.dumps(self.roles, separators=(",", ":"))
        text = self._command([self.c["php"], "-n", "-c", self.c["ini"], str(self.source / HARNESS), mode], remaining)
        data = json.loads(text)
        self.__accept_harness_result(mode, data)

    def __accept_harness_result(self, mode, data):
        expected = {"integrity-pre": "preverified", "integrity-start": "serving", "integrity-stop": "stopped", "integrity-post": "postverified"}
        if mode == "verify":
            if data != {"businessMatchesFixedPlan": True, "lifecycleAuditsChecked": True}:
                raise Refused("business")
        elif mode not in expected or data != {"state": expected[mode], "accepted": False}:
            raise Refused("harness")
        if mode == "integrity-post":
            self.postcheck_complete = True
            self.__journal_postchecked = True

    def _cli(self, args, remaining):
        return self._command([self.c["node"], self.c["cli"], "-s=" + self.session, *args], remaining,
                             policy="owned", role="browser_launcher" if args[0] == "open" else "command")

    def launch(self, role, remaining):
        self._require_normal_run()
        expected = self.c[{"php": "php", "tls": "python", "browser": "browser"}[role]]
        if role == "browser":
            _read_validated_browser_config(
                Path(self.c["browser_config"]), self.c["tool_hashes"]["browser_config"],
                self.c["browser"], self.run,
            )
        intent = self._push_launch_intent(role, expected)
        if role == "browser":
            text = self._cli(["open", "about:blank", "--config=" + self.c["browser_config"]], remaining)
            match = re.search(r"opened with pid ([0-9]+)\.", text)
            if not match:
                raise Refused("browser_pid")
            identity = self._identity(int(match[1]))
            self._discover()
            if self.owned.get(identity["pid"]) != identity["started"]:
                raise Refused("unowned_browser")
            record = self.owned_records[identity["pid"]]
            if self._normalized_path(record["executable"]) != self._normalized_path(self.c["browser"]):
                raise Refused("unowned_browser")
            record["role"] = "browser"
            self._persist_journal()
        else:
            args = ([self.c["php"], "-n", "-c", self.c["ini"], "-S", "127.0.0.1:8126", "-t", str(self.run / "storage/public"), str(self.source / HARNESS)] if role == "php" else
                    [self.c["python"], str(self.source / "tools/testing/tests/Browser/https-loopback-proxy.py"), self.c["cert"], self.c["key"], "443", "8126"])
            process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                       cwd=self.run, env=self.env, creationflags=subprocess.CREATE_NO_WINDOW)
            self.handles.append(process)
            try:
                identity = self._identity(process.pid)
            except Exception:
                self._mark_uncertain("identity_probe_failed")
                raise
            self._register_owned(identity, role, self.owner, str(Path(args[0]).absolute()))
        self.roles[role] = identity
        self._clear_launch_intent(intent)

    def assert_owned(self, remaining):
        self._require_normal_run()
        current = self._discover()
        if self.uncertain or set(self.roles) != {"php", "tls", "browser"}:
            raise Refused("ownership")
        for identity in self.roles.values():
            if current.get(identity["pid"], {}).get("started") != identity["started"]:
                raise Refused("ownership")
        listeners = self._listeners()
        expected = {(8126, self.roles["php"]["pid"], "127.0.0.1"), (443, self.roles["tls"]["pid"], "127.0.0.1")}
        if {(r["port"], r["pid"], r["address"]) for r in listeners} != expected:
            raise Refused("listeners")

    def _run_code(self, code, remaining):
        self._require_normal_run()
        # CLI --filename is documented; the temporary code has no credential values.
        path = self.run / ("supervisor-code-" + secrets.token_hex(8) + ".mjs")
        with path.open("x") as file:
            file.write(code)
        text = self._cli(["run-code", "--filename=" + str(path)], remaining)
        if type(text) is not str:
            raise Refused("driver")
        try:
            text.encode("utf-8", errors="strict")
        except UnicodeError:
            raise Refused("driver") from None
        normalized = text.replace("\r\n", "\n")
        marker = "### Result\n"
        if (len(normalized) > 262144 or "### Error" in normalized
                or normalized.count(marker) != 1
                or re.search(r"(?m)^### Result\n", normalized) is None):
            raise Refused("driver")
        payload = normalized.split(marker, 1)[1]
        if len(payload) > 65536:
            raise Refused("driver")

        def object_without_duplicates(pairs):
            result = {}
            for key, value in pairs:
                if key in result:
                    raise ValueError("duplicate")
                result[key] = value
            return result

        def reject_nonfinite(value):
            raise ValueError("nonfinite")

        try:
            data = json.loads(payload, object_pairs_hook=object_without_duplicates,
                              parse_constant=reject_nonfinite)
        except (TypeError, ValueError, RecursionError):
            raise Refused("driver") from None
        if type(data) is not dict:
            raise Refused("driver")
        return data

    def smoke_request(self, remaining):
        code = '''async (page) => { const c=page.context(); await c.unrouteAll(); let n=0;
        await c.route('**/*', route => { const r=route.request(); if(r.method()==='GET' && r.url()==='https://psikotes.oncam.id/__browser/login' && ++n===1) return route.continue(); return route.abort(); }); await c.setOffline(false);
        const start=Date.now(); const r=await page.goto('https://psikotes.oncam.id/__browser/login', {timeout:30000,waitUntil:'domcontentloaded'});
        if(!r || r.status()!==200 || n!==1) throw new Error('Smoke refused'); await c.setOffline(true); return {seconds:(Date.now()-start)/1000, requests:n}; }'''
        result = self._run_code(code, remaining)
        if list(result) != ["seconds", "requests"]:
            raise Refused("smoke_result")
        if type(result["requests"]) is not int or result["requests"] != 1:
            raise Refused("smoke_count")
        seconds = result["seconds"]
        if (type(seconds) not in (int, float) or not math.isfinite(seconds)
                or seconds < 0 or seconds > 5):
            raise Refused("smoke_result")
        return seconds

    def full_matrix(self, remaining):
        code = (self.source / DRIVER).read_text()
        code = "async(page)=>{await page.context().route('**/*',r=>r.abort()); await page.context().setOffline(false); return await (" + code + ")(page);}"
        result = self._run_code(code, remaining)
        if list(result) != list(FULL_MATRIX_KEYS) or result["checks"] != list(FULL_MATRIX_CHECKS):
            raise Refused("matrix")
        exact_integers = {
            "exchangePosts": 11,
            "hostileForms": 6,
            "screenshotsContainingCredentials": 0,
        }
        if any(type(result[key]) is not int or result[key] != value
               for key, value in exact_integers.items()):
            raise Refused("matrix")
        if (result["credentialMaterialRecorded"] is not False
                or result["fullBusinessPostcondition"] is not True
                or result["immediateDeliveredDOMRemovalClaimed"] is not False):
            raise Refused("matrix")
        for key in ("opaqueNetworkBlocks", "expectedSandboxInstrumentationErrors"):
            if type(result[key]) is not int or not 0 <= result[key] <= 2:
                raise Refused("matrix")
        if (type(result["controlledNetworkEntries"]) is not int
                or not 1 <= result["controlledNetworkEntries"] <= 64):
            raise Refused("matrix")
        observations = result["historyObservations"]
        if type(observations) is not list or len(observations) != 2:
            raise Refused("matrix")
        for observation, phase in zip(
            observations, ("after-logout-back", "after-recovery-back"), strict=True
        ):
            if (type(observation) is not dict
                    or list(observation) != ["phase", "documentResponseObserved", "summaryDOMVisible"]
                    or observation["phase"] != phase
                    or type(observation["documentResponseObserved"]) is not bool
                    or type(observation["summaryDOMVisible"]) is not bool):
                raise Refused("matrix")
        if len(result) != 11:
            raise Refused("matrix")

    def cleanup(self, budget):
        end = self.clock() + budget
        self.io_deadline = end
        clean = False
        try:
            current = self._discover()
            if self.postcheck_complete and any(current.get(pid, {}).get("started") == tick for pid, tick in self.owned.items()):
                # A writer alive after the full scan invalidates its premise, even if cleanup succeeds.
                self._mark_uncertain("postcheck_live_process")
            browser = self.roles.get("browser")
            if browser and current.get(browser["pid"], {}).get("started") == browser["started"]:
                try:
                    self._cli(["close"], min(3, end - self.clock()))
                except Exception:
                    pass
            while self.clock() < end:
                current = self._discover()
                live = [(pid, tick) for pid, tick in self.owned.items() if current.get(pid, {}).get("started") == tick]
                if not live:
                    break
                if self.postcheck_complete:
                    self._mark_uncertain("postcheck_live_process")
                for pid, tick in reversed(live):
                    # Re-check creation time inside the same PS command before stopping it.
                    script = f"$ErrorActionPreference='Stop'; try {{$p=[Diagnostics.Process]::GetProcessById({pid})}} catch {{exit 0}}; try {{if($p.StartTime.ToUniversalTime().Ticks.ToString() -eq '{tick}') {{$p.Kill()}}}} finally {{$p.Dispose()}}"
                    self._ps(script)
                time.sleep(.02)
            current = self._discover()
            clean = not self._listeners() and not any(current.get(pid, {}).get("started") == tick for pid, tick in self.owned.items())
        except Exception:
            self._mark_uncertain("cleanup_exception")
            clean = False
        finally:
            # Also covers a launch failure before identity registration. Never search by name.
            for handle in self.handles:
                try:
                    if handle.poll() is None:
                        handle.kill()
                        handle.wait(timeout=max(.01, min(2, end - self.clock())))
                except Exception:
                    self._mark_uncertain("cleanup_exception")
        if clean and not self.uncertain and self.__journal_postchecked:
            try:
                self._clear_journal()
                self.lifecycle_phase = "closed"
            except Exception:
                self._mark_uncertain("cleanup_exception")
                clean = False
        return clean and not self.uncertain

    def invalidate(self):
        if self.claimed:
            try:
                with (self.run / "integrity-invalid").open("x") as file:
                    file.write("INVALID\n")
            except FileExistsError:
                pass
        self.lifecycle_phase = "invalidated"


if __name__ == "__main__":
    # No accidental launch or implicit config discovery. Runtime authorization is a separate review.
    raise SystemExit("Runtime entrypoint disabled pending supervisor review; import for pure tests only.")
