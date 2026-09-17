"""Native Windows point-in-time evidence for an append-only journal.

This test-only provider operates only on an exact caller-supplied journal under
a temporary-directory run root.  Bootstrap requires an already allocated,
empty, identity-pinned file; ``created-new`` describes the logical journal
state, not creation of that file.  Each transition writes and flushes an intent,
then writes and flushes its commit marker.  Incomplete, corrupt, ambiguous,
missing, stale, or identity-drifted state is refused without repair.

The implementation follows the documented CreateFileW non-inheritable-handle,
FILE_FLAG_WRITE_THROUGH, FlushFileBuffers, GetFileInformationByHandle, and
GetFinalPathNameByHandleW contracts:
https://learn.microsoft.com/windows/win32/api/fileapi/nf-fileapi-createfilew
https://learn.microsoft.com/windows/win32/api/fileapi/nf-fileapi-flushfilebuffers
https://learn.microsoft.com/windows/win32/api/fileapi/nf-fileapi-getfileinformationbyhandle
https://learn.microsoft.com/windows/win32/api/fileapi/nf-fileapi-getfinalpathnamebyhandlew

It returns only immutable ``nativeStorageOnly`` observations. Content and
identity are validated while the final reopened handle remains open, and that
handle is closed before return. The path may change immediately after validation
or close. Composition must add a separately accepted descriptor/token snapshot
boundary before it may issue ADR-021 protected-journal evidence. It does not
prove ACL efficacy,
DPAPI/TPM protection, power-loss atomicity, reboot durability, offline rollback
resistance, freshness, trust, admission, or safe use on a candidate or real
journal.
"""

from __future__ import annotations

import ctypes
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import types
from types import MappingProxyType


MAX_JOURNAL_BYTES = 1024 * 1024
FORMAT = "oncam.checkout.protected-journal.v1"
ZERO_DIGEST = "0" * 64


class WindowsProtectedJournalProviderRefused(Exception):
    """Fixed refusal without request, path, state, or Win32 error detail."""


__all__ = ("WindowsProtectedJournalProviderRefused", "apply_storage")


def _load_codec(filename, name):
    path = Path(__file__).with_name(filename)
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise WindowsProtectedJournalProviderRefused(
            "windows_protected_journal_provider"
        )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_REQUEST = _load_codec(
    "checkout-protected-journal-request.py", "_native_journal_request_codec"
)
del _load_codec


if os.name == "nt":
    from ctypes import wintypes

    class _FILETIME(ctypes.Structure):
        _fields_ = (("low", wintypes.DWORD), ("high", wintypes.DWORD))

    class _BY_HANDLE_FILE_INFORMATION(ctypes.Structure):
        _fields_ = (
            ("attributes", wintypes.DWORD), ("creation", _FILETIME),
            ("access", _FILETIME), ("write", _FILETIME),
            ("volume", wintypes.DWORD), ("size_high", wintypes.DWORD),
            ("size_low", wintypes.DWORD), ("links", wintypes.DWORD),
            ("index_high", wintypes.DWORD), ("index_low", wintypes.DWORD),
        )

    _kernel = ctypes.WinDLL("kernel32.dll", use_last_error=True)
    _CreateFileW = _kernel.CreateFileW
    _CreateFileW.restype = wintypes.HANDLE
    _CreateFileW.argtypes = (
        wintypes.LPCWSTR, wintypes.DWORD, wintypes.DWORD, ctypes.c_void_p,
        wintypes.DWORD, wintypes.DWORD, wintypes.HANDLE,
    )
    _ReadFile = _kernel.ReadFile
    _ReadFile.restype = wintypes.BOOL
    _ReadFile.argtypes = (
        wintypes.HANDLE, ctypes.c_void_p, wintypes.DWORD,
        ctypes.POINTER(wintypes.DWORD), ctypes.c_void_p,
    )
    _WriteFile = _kernel.WriteFile
    _WriteFile.restype = wintypes.BOOL
    _WriteFile.argtypes = _ReadFile.argtypes
    _FlushFileBuffers = _kernel.FlushFileBuffers
    _FlushFileBuffers.restype = wintypes.BOOL
    _FlushFileBuffers.argtypes = (wintypes.HANDLE,)
    _CloseHandle = _kernel.CloseHandle
    _CloseHandle.restype = wintypes.BOOL
    _CloseHandle.argtypes = (wintypes.HANDLE,)
    _GetHandleInformation = _kernel.GetHandleInformation
    _GetHandleInformation.restype = wintypes.BOOL
    _GetHandleInformation.argtypes = (
        wintypes.HANDLE, ctypes.POINTER(wintypes.DWORD),
    )
    _GetFileInformationByHandle = _kernel.GetFileInformationByHandle
    _GetFileInformationByHandle.restype = wintypes.BOOL
    _GetFileInformationByHandle.argtypes = (
        wintypes.HANDLE, ctypes.POINTER(_BY_HANDLE_FILE_INFORMATION),
    )
    _GetFinalPathNameByHandleW = _kernel.GetFinalPathNameByHandleW
    _GetFinalPathNameByHandleW.restype = wintypes.DWORD
    _GetFinalPathNameByHandleW.argtypes = (
        wintypes.HANDLE, wintypes.LPWSTR, wintypes.DWORD, wintypes.DWORD,
    )
    _GetFileSizeEx = _kernel.GetFileSizeEx
    _GetFileSizeEx.restype = wintypes.BOOL
    _GetFileSizeEx.argtypes = (
        wintypes.HANDLE, ctypes.POINTER(ctypes.c_longlong),
    )
    _SetFilePointerEx = _kernel.SetFilePointerEx
    _SetFilePointerEx.restype = wintypes.BOOL
    _SetFilePointerEx.argtypes = (
        wintypes.HANDLE, ctypes.c_longlong,
        ctypes.POINTER(ctypes.c_longlong), wintypes.DWORD,
    )


_GENERIC_READ = 0x80000000
_GENERIC_WRITE = 0x40000000
_FILE_READ_ATTRIBUTES = 0x80
_FILE_SHARE_READ = 1
_FILE_SHARE_WRITE = 2
_OPEN_EXISTING = 3
_FILE_ATTRIBUTE_NORMAL = 0x80
_FILE_ATTRIBUTE_DIRECTORY = 0x10
_FILE_ATTRIBUTE_REPARSE_POINT = 0x400
_FILE_FLAG_WRITE_THROUGH = 0x80000000
_FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000
_FILE_FLAG_BACKUP_SEMANTICS = 0x02000000
_FILE_BEGIN = 0
_FILE_END = 2
_HANDLE_FLAG_INHERIT = 1
_INVALID_HANDLE_VALUE = ctypes.c_void_p(-1).value


def _refuse():
    raise WindowsProtectedJournalProviderRefused(
        "windows_protected_journal_provider"
    )


def _native_ready():
    if os.name != "nt" or "_CreateFileW" not in globals():
        _refuse()


def _canonical_path(value):
    if type(value) is not str:
        _refuse()
    return value.replace("/", "\\")


def _open_handle(path, directory=False):
    _native_ready()
    access = _FILE_READ_ATTRIBUTES if directory else _GENERIC_READ | _GENERIC_WRITE
    share = _FILE_SHARE_READ | _FILE_SHARE_WRITE if directory else 0
    flags = _FILE_FLAG_OPEN_REPARSE_POINT | (
        _FILE_FLAG_BACKUP_SEMANTICS if directory
        else _FILE_ATTRIBUTE_NORMAL | _FILE_FLAG_WRITE_THROUGH
    )
    handle = _CreateFileW(
        _canonical_path(path), access, share, None, _OPEN_EXISTING, flags, None
    )
    numeric = ctypes.cast(handle, ctypes.c_void_p).value
    if numeric is None or numeric == _INVALID_HANDLE_VALUE:
        _refuse()
    inheritance = wintypes.DWORD()
    if not _GetHandleInformation(handle, ctypes.byref(inheritance)) \
            or inheritance.value & _HANDLE_FLAG_INHERIT:
        try:
            _refuse()
        finally:
            _finish_closes((handle,), sys.exc_info()[1])
    return handle


def _close(handle):
    if not _CloseHandle(handle):
        _refuse()


def _finish_closes(handles, primary):
    cleanup_failure = None
    for handle in handles:
        if handle is None:
            continue
        try:
            _close(handle)
        except BaseException as error:
            if cleanup_failure is None:
                cleanup_failure = error
    if primary is None and cleanup_failure is not None:
        raise cleanup_failure


def _info(handle, require_directory):
    info = _BY_HANDLE_FILE_INFORMATION()
    if not _GetFileInformationByHandle(handle, ctypes.byref(info)):
        _refuse()
    if bool(info.attributes & _FILE_ATTRIBUTE_DIRECTORY) is not require_directory \
            or info.attributes & _FILE_ATTRIBUTE_REPARSE_POINT:
        _refuse()
    return str(info.volume), str((info.index_high << 32) | info.index_low)


def _final_path(handle):
    size = _GetFinalPathNameByHandleW(handle, None, 0, 0)
    if not 1 <= size <= 32768:
        _refuse()
    buffer = ctypes.create_unicode_buffer(size + 1)
    written = _GetFinalPathNameByHandleW(handle, buffer, len(buffer), 0)
    if not 1 <= written < len(buffer):
        _refuse()
    value = buffer.value
    if value.startswith("\\\\?\\UNC\\"):
        value = "//" + value[8:]
    elif value.startswith("\\\\?\\"):
        value = value[4:]
    value = value.replace("\\", "/")
    if len(value) >= 2 and value[1] == ":":
        value = value[0].upper() + value[1:]
    return value


def _validate_handle(handle, expected, directory=False):
    volume, file_id = _info(handle, directory)
    if _final_path(handle) != expected["path"] \
            or volume != expected["volumeSerial"] \
            or file_id != expected["fileId"]:
        _refuse()
    return {
        "path": expected["path"], "volumeSerial": volume, "fileId": file_id,
    }


def _seek(handle, offset, method):
    actual = ctypes.c_longlong()
    if not _SetFilePointerEx(handle, offset, ctypes.byref(actual), method):
        _refuse()
    return actual.value


def _read(handle):
    size = ctypes.c_longlong()
    if not _GetFileSizeEx(handle, ctypes.byref(size)) \
            or not 0 <= size.value <= MAX_JOURNAL_BYTES:
        _refuse()
    if _seek(handle, 0, _FILE_BEGIN) != 0:
        _refuse()
    if size.value == 0:
        return b""
    buffer = ctypes.create_string_buffer(size.value)
    done = wintypes.DWORD()
    if not _ReadFile(handle, buffer, size.value, ctypes.byref(done), None) \
            or done.value != size.value:
        _refuse()
    return buffer.raw


def _write_all(handle, raw):
    if type(raw) is not bytes or not raw or len(raw) > MAX_JOURNAL_BYTES:
        _refuse()
    buffer = ctypes.create_string_buffer(raw)
    done = wintypes.DWORD()
    if not _WriteFile(handle, buffer, len(raw), ctypes.byref(done), None) \
            or done.value != len(raw) or not _FlushFileBuffers(handle):
        _refuse()


def _canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def _digest(value):
    return type(value) is str and len(value) == 64 \
        and all(character in "0123456789abcdef" for character in value)


def _object(raw):
    value = json.loads(raw.decode("ascii"))
    if type(value) is not dict or _canonical(value) != raw:
        _refuse()
    return value


def _parse(raw, kind, namespace):
    if raw == b"":
        return 0, ZERO_DIGEST, set()
    if not raw.endswith(b"\n"):
        _refuse()
    lines = raw.splitlines(keepends=True)
    if not lines:
        _refuse()
    header = _object(lines[0])
    if header != {"format": FORMAT, "journalKind": kind, "namespace": namespace} \
            or len(lines) == 1 or len(lines[1:]) % 2:
        _refuse()
    generation = 0
    raw_digest = ZERO_DIGEST
    requests = set()
    for index in range(1, len(lines), 2):
        intent_raw = lines[index]
        intent = _object(intent_raw)
        commit = _object(lines[index + 1])
        if set(intent) != {
            "generation", "previousDigest", "rawDigest", "requestDigest", "type",
        } or intent.get("type") != "intent" \
                or type(intent.get("generation")) is not int \
                or not 1 <= intent["generation"] < (1 << 63) \
                or not _digest(intent.get("previousDigest")) \
                or not _digest(intent.get("rawDigest")) \
                or not _digest(intent.get("requestDigest")) \
                or intent["previousDigest"] != raw_digest \
                or intent["rawDigest"] == raw_digest \
                or intent["requestDigest"] in requests:
            _refuse()
        if kind == "one-shot-ledger":
            if intent["generation"] != generation + 1:
                _refuse()
        elif intent["generation"] <= generation:
            _refuse()
        intent_digest = hashlib.sha256(intent_raw).hexdigest()
        if commit != {"generation": intent["generation"],
                      "intentDigest": intent_digest, "type": "commit"}:
            _refuse()
        generation = intent["generation"]
        raw_digest = intent["rawDigest"]
        requests.add(intent["requestDigest"])
    return generation, raw_digest, requests


def _apply_impl(request_raw):
    if type(request_raw) is not bytes:
        _refuse()
    _REQUEST.decode(request_raw)
    request = json.loads(request_raw.decode("ascii"))
    temp_root = str(Path(tempfile.gettempdir()).resolve()).replace("\\", "/")
    if len(temp_root) >= 2 and temp_root[1] == ":":
        temp_root = temp_root[0].upper() + temp_root[1:]
    run_path = request["runIdentity"]["path"]
    if not run_path.startswith(temp_root + "/"):
        _refuse()
    run_handle = None
    journal_handle = None
    try:
        run_handle = _open_handle(request["runIdentity"]["path"], directory=True)
        _validate_handle(run_handle, request["runIdentity"], directory=True)
        try:
            journal_handle = _open_handle(request["expectedFileIdentity"]["path"])
        except WindowsProtectedJournalProviderRefused:
            _refuse()
        journal_identity = _validate_handle(
            journal_handle, request["expectedFileIdentity"]
        )
        before = _read(journal_handle)
        generation, state_digest, request_digests = _parse(
            before, request["journalKind"], request["namespace"]
        )
        request_digest = hashlib.sha256(request_raw).hexdigest()
        if generation != request["currentState"]["generation"] \
                or state_digest != request["currentState"]["rawDigest"] \
                or request_digest in request_digests:
            _refuse()
        header = b"" if before else _canonical({
            "format": FORMAT, "journalKind": request["journalKind"],
            "namespace": request["namespace"],
        })
        intent = _canonical({
            "generation": request["proposedState"]["generation"],
            "previousDigest": request["proposedState"]["previousDigest"],
            "rawDigest": request["proposedState"]["rawDigest"],
            "requestDigest": request_digest, "type": "intent",
        })
        commit = _canonical({
            "generation": request["proposedState"]["generation"],
            "intentDigest": hashlib.sha256(intent).hexdigest(), "type": "commit",
        })
        if len(before) + len(header) + len(intent) + len(commit) > MAX_JOURNAL_BYTES:
            _refuse()
        if _seek(journal_handle, 0, _FILE_END) != len(before):
            _refuse()
        if header:
            _write_all(journal_handle, header)
        _write_all(journal_handle, intent)
        _write_all(journal_handle, commit)
        _validate_handle(journal_handle, journal_identity)
    finally:
        _finish_closes((journal_handle, run_handle), sys.exc_info()[1])
    reopened = _open_handle(request["expectedFileIdentity"]["path"])
    try:
        _validate_handle(reopened, request["expectedFileIdentity"])
        final = _read(reopened)
        final_generation, final_digest, _requests = _parse(
            final, request["journalKind"], request["namespace"]
        )
        if final != before + header + intent + commit \
                or final_generation != request["proposedState"]["generation"] \
                or final_digest != request["proposedState"]["rawDigest"]:
            _refuse()
    finally:
        _finish_closes((reopened,), sys.exc_info()[1])
    identity_digest = hashlib.sha256(
        _canonical(request["expectedFileIdentity"])
    ).hexdigest()
    observation = {
        "afterGeneration": request["proposedState"]["generation"],
        "afterStateDigest": request["proposedState"]["rawDigest"],
        "beforeGeneration": request["currentState"]["generation"],
        "beforeStateDigest": request["currentState"]["rawDigest"],
        "journalIdentityDigest": identity_digest,
        "journalKind": request["journalKind"],
        "namespace": request["namespace"],
        "requestDigest": hashlib.sha256(request_raw).hexdigest(),
        "finalHandleClosedBeforeReturn": True,
    }
    return MappingProxyType({
        "nativeStorageOnly": True,
        **observation,
        "storageEvidenceDigest": hashlib.sha256(
            b"oncam.checkout.native-storage-observation.v1\0"
            + _canonical(observation)
        ).hexdigest(),
    })


def _apply_storage_unbound(request_raw):
    try:
        if type(request_raw) is not bytes:
            _refuse()
        _REQUEST.decode(request_raw)
    except (KeyboardInterrupt, SystemExit):
        raise
    except Exception:
        raise WindowsProtectedJournalProviderRefused(
            "windows_protected_journal_provider"
        ) from None
    try:
        return _apply_impl(request_raw)
    except (KeyboardInterrupt, SystemExit):
        raise
    except Exception:
        raise WindowsProtectedJournalProviderRefused(
            "windows_protected_journal_provider"
        ) from None


# Narrow helpers exist only so native tests can prove handle policy without
# exporting handles from the public module surface.
def _open_exclusive_for_test(path):
    try:
        return _open_handle(path)
    except WindowsProtectedJournalProviderRefused:
        raise
    except Exception:
        _refuse()


def _handle_inheritable_for_test(handle):
    flags = wintypes.DWORD()
    if not _GetHandleInformation(handle, ctypes.byref(flags)):
        _refuse()
    return bool(flags.value & _HANDLE_FLAG_INHERIT)


def _close_for_test(handle):
    return _close(handle)


def _identity_for_test(path, directory=False):
    handle = _open_handle(path, directory=directory)
    try:
        volume, file_id = _info(handle, directory)
        return {
            "fileId": file_id,
            "path": _final_path(handle),
            "volumeSerial": volume,
        }
    finally:
        _finish_closes((handle,), sys.exc_info()[1])


def _invoke_cleanup_for_test(operation, handles):
    try:
        return operation()
    finally:
        _finish_closes(handles, sys.exc_info()[1])


_AUTHORITY_NAMES = (
    "ctypes", "hashlib", "importlib", "json", "os", "Path", "sys",
    "tempfile", "types", "MappingProxyType", "MAX_JOURNAL_BYTES", "FORMAT",
    "ZERO_DIGEST", "WindowsProtectedJournalProviderRefused", "__all__",
    "_REQUEST", "_GENERIC_READ", "_GENERIC_WRITE", "_FILE_READ_ATTRIBUTES",
    "_FILE_SHARE_READ", "_FILE_SHARE_WRITE", "_OPEN_EXISTING",
    "_FILE_ATTRIBUTE_NORMAL", "_FILE_ATTRIBUTE_DIRECTORY",
    "_FILE_ATTRIBUTE_REPARSE_POINT", "_FILE_FLAG_WRITE_THROUGH",
    "_FILE_FLAG_OPEN_REPARSE_POINT", "_FILE_FLAG_BACKUP_SEMANTICS",
    "_FILE_BEGIN", "_FILE_END", "_HANDLE_FLAG_INHERIT",
    "_INVALID_HANDLE_VALUE", "_AUTHORITY_NAMES",
)
if os.name == "nt":
    _AUTHORITY_NAMES += (
        "wintypes", "_FILETIME", "_BY_HANDLE_FILE_INFORMATION", "_kernel",
        "_CreateFileW", "_ReadFile", "_WriteFile", "_FlushFileBuffers",
        "_CloseHandle", "_GetHandleInformation", "_GetFileInformationByHandle",
        "_GetFinalPathNameByHandleW", "_GetFileSizeEx", "_SetFilePointerEx",
    )


def _frozen_value(value):
    if type(value) is tuple:
        return ("tuple", tuple(_frozen_value(item) for item in value))
    if type(value) is list:
        return ("list", tuple(_frozen_value(item) for item in value))
    if type(value) is dict:
        return ("dict", tuple(sorted(
            ((key, _frozen_value(item)) for key, item in value.items()),
            key=lambda item: repr(item[0]),
        )))
    if type(value) in {str, bytes, int, type(None)}:
        return ("value", type(value), value)
    return ("identity", value)


def _callable_state(value):
    bound_self = None
    implementation = value
    if type(value) is types.MethodType:
        bound_self = value.__self__
        implementation = value.__func__
    code = defaults = kwdefaults = closure = function_globals = None
    if type(implementation) is types.FunctionType:
        code = implementation.__code__
        defaults = implementation.__defaults__
        kwdefaults = implementation.__kwdefaults__
        closure = implementation.__closure__
        function_globals = implementation.__globals__
    restype = getattr(value, "restype", None)
    argtypes = getattr(value, "argtypes", None)
    return (
        type(value), bound_self, implementation, code, defaults, kwdefaults,
        closure, function_globals, restype, argtypes,
        None if argtypes is None else tuple(argtypes),
    )


def _same_callable(value, expected):
    current = _callable_state(value)
    return all(current[index] is expected[index] for index in range(10)) \
        and current[10] == expected[10]


def _seal_authority():
    module_globals = globals()
    freeze = _frozen_value
    same_callable = _same_callable
    refusal = WindowsProtectedJournalProviderRefused
    names = _AUTHORITY_NAMES
    globals_state = MappingProxyType({
        name: (module_globals[name], freeze(module_globals[name])) for name in names
    })
    excluded = frozenset({
        "apply_storage", "_seal_authority", "_require_authority",
    })
    functions_state = MappingProxyType({
        name: _callable_state(value)
        for name, value in module_globals.items()
        if type(value) is types.FunctionType and value.__module__ == __name__
        and name not in excluded
    })
    dependency_state = tuple(_callable_state(value) for value in (
        _REQUEST.decode, json.dumps, json.loads, hashlib.sha256,
        tempfile.gettempdir,
    ))
    native_names = tuple(
        name for name in names if name in module_globals
        and (hasattr(module_globals[name], "restype")
             or hasattr(module_globals[name], "argtypes"))
    )
    native_state = MappingProxyType({
        name: _callable_state(module_globals[name]) for name in native_names
    })

    def guard():
        try:
            for name, expected in globals_state.items():
                current = module_globals.get(name)
                if current is not expected[0] or freeze(current) != expected[1]:
                    raise ValueError("authority")
            current_functions = {
                name for name, value in module_globals.items()
                if type(value) is types.FunctionType and value.__module__ == __name__
                and name not in excluded
            }
            if current_functions != set(functions_state):
                raise ValueError("authority")
            for name, expected in functions_state.items():
                if not same_callable(module_globals.get(name), expected):
                    raise ValueError("authority")
            dependencies = (
                module_globals["_REQUEST"].decode,
                module_globals["json"].dumps,
                module_globals["json"].loads,
                module_globals["hashlib"].sha256,
                module_globals["tempfile"].gettempdir,
            )
            if any(
                not same_callable(current, expected)
                for current, expected in zip(dependencies, dependency_state)
            ):
                raise ValueError("authority")
            for name, expected in native_state.items():
                if not same_callable(module_globals.get(name), expected):
                    raise ValueError("authority")
        except refusal:
            raise
        except Exception:
            raise refusal("windows_protected_journal_provider") from None

    return guard


_require_authority = _seal_authority()
del _seal_authority


def _bind_public(guard, operation, refusal):
    def apply_storage(request_raw):
        try:
            guard()
            result = operation(request_raw)
            guard()
            return result
        except BaseException as primary:
            try:
                guard()
            except BaseException:
                pass
            if isinstance(primary, (KeyboardInterrupt, SystemExit)):
                raise
            raise refusal("windows_protected_journal_provider") from None

    return apply_storage


apply_storage = _bind_public(
    _require_authority, _apply_storage_unbound,
    WindowsProtectedJournalProviderRefused,
)
del _bind_public
