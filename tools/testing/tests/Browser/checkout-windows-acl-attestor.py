"""Lazy ctypes ABI boundary for a future checkout Windows ACL attestor.

There is intentionally no usable attestor class in this RED-1 increment. Import
defines data only; native DLL discovery is explicit through ``_load_native``.
"""

from __future__ import annotations

import ctypes
import os
import re
from types import MappingProxyType


BYTE = ctypes.c_ubyte
WORD = ctypes.c_ushort
DWORD = ctypes.c_uint32
LONG = ctypes.c_int32
ULONGLONG = ctypes.c_uint64
LONGLONG = ctypes.c_int64
BOOL = ctypes.c_int
SE_OBJECT_TYPE = ctypes.c_int
HANDLE = ctypes.c_void_p
LPVOID = ctypes.c_void_p
LPCWSTR = ctypes.c_wchar_p
LPWSTR = ctypes.c_wchar_p
LPDWORD = ctypes.POINTER(DWORD)
LPBOOL = ctypes.POINTER(BOOL)
PHANDLE = ctypes.POINTER(HANDLE)
PSID = LPVOID
PACL = LPVOID
PSECURITY_DESCRIPTOR = LPVOID


class FILETIME(ctypes.Structure):
    _fields_ = [("dwLowDateTime", DWORD), ("dwHighDateTime", DWORD)]


class BY_HANDLE_FILE_INFORMATION(ctypes.Structure):
    _fields_ = [
        ("dwFileAttributes", DWORD),
        ("ftCreationTime", FILETIME),
        ("ftLastAccessTime", FILETIME),
        ("ftLastWriteTime", FILETIME),
        ("dwVolumeSerialNumber", DWORD),
        ("nFileSizeHigh", DWORD),
        ("nFileSizeLow", DWORD),
        ("nNumberOfLinks", DWORD),
        ("nFileIndexHigh", DWORD),
        ("nFileIndexLow", DWORD),
    ]


class FILE_ID_128(ctypes.Structure):
    _fields_ = [("Identifier", BYTE * 16)]


class FILE_ID_INFO(ctypes.Structure):
    _fields_ = [("VolumeSerialNumber", ULONGLONG), ("FileId", FILE_ID_128)]


class FILE_ATTRIBUTE_TAG_INFO(ctypes.Structure):
    _fields_ = [("FileAttributes", DWORD), ("ReparseTag", DWORD)]


class SECURITY_ATTRIBUTES(ctypes.Structure):
    _fields_ = [
        ("nLength", DWORD),
        ("lpSecurityDescriptor", LPVOID),
        ("bInheritHandle", BOOL),
    ]


class ACL_SIZE_INFORMATION(ctypes.Structure):
    _fields_ = [
        ("AceCount", DWORD),
        ("AclBytesInUse", DWORD),
        ("AclBytesFree", DWORD),
    ]


class ACL_REVISION_INFORMATION(ctypes.Structure):
    _fields_ = [("AclRevision", DWORD)]


class ACE_HEADER(ctypes.Structure):
    _fields_ = [("AceType", BYTE), ("AceFlags", BYTE), ("AceSize", WORD)]


class ACCESS_ALLOWED_ACE(ctypes.Structure):
    _fields_ = [("Header", ACE_HEADER), ("Mask", DWORD), ("SidStart", DWORD)]


class SID_AND_ATTRIBUTES(ctypes.Structure):
    _fields_ = [("Sid", PSID), ("Attributes", DWORD)]


class TOKEN_USER(ctypes.Structure):
    _fields_ = [("User", SID_AND_ATTRIBUTES)]


class TOKEN_GROUPS(ctypes.Structure):
    _fields_ = [("GroupCount", DWORD), ("Groups", SID_AND_ATTRIBUTES * 1)]


class LUID(ctypes.Structure):
    _fields_ = [("LowPart", DWORD), ("HighPart", LONG)]


class LUID_AND_ATTRIBUTES(ctypes.Structure):
    _fields_ = [("Luid", LUID), ("Attributes", DWORD)]


class TOKEN_PRIVILEGES(ctypes.Structure):
    _fields_ = [("PrivilegeCount", DWORD), ("Privileges", LUID_AND_ATTRIBUTES * 1)]


class TOKEN_STATISTICS(ctypes.Structure):
    _fields_ = [
        ("TokenId", LUID),
        ("AuthenticationId", LUID),
        ("ExpirationTime", LONGLONG),
        ("TokenType", ctypes.c_int),
        ("ImpersonationLevel", ctypes.c_int),
        ("DynamicCharged", DWORD),
        ("DynamicAvailable", DWORD),
        ("GroupCount", DWORD),
        ("PrivilegeCount", DWORD),
        ("ModifiedId", LUID),
    ]


class GENERIC_MAPPING(ctypes.Structure):
    _fields_ = [
        ("GenericRead", DWORD),
        ("GenericWrite", DWORD),
        ("GenericExecute", DWORD),
        ("GenericAll", DWORD),
    ]


class PRIVILEGE_SET(ctypes.Structure):
    _fields_ = [
        ("PrivilegeCount", DWORD),
        ("Control", DWORD),
        ("Privilege", LUID_AND_ATTRIBUTES * 1),
    ]


PPRIVILEGE_SET = ctypes.POINTER(PRIVILEGE_SET)


INVALID_HANDLE_VALUE = ctypes.c_void_p(-1).value
READ_CONTROL = 0x00020000
FILE_READ_ATTRIBUTES = 0x00000080
FILE_SHARE_READ = 0x00000001
FILE_SHARE_WRITE = 0x00000002
FILE_SHARE_DELETE = 0x00000004
OPEN_EXISTING = 3
FILE_FLAG_BACKUP_SEMANTICS = 0x02000000
FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000
HANDLE_FLAG_INHERIT = 0x00000001
FILE_ATTRIBUTE_REPARSE_POINT = 0x00000400
FILE_ATTRIBUTE_DIRECTORY = 0x00000010
FileAttributeTagInfo = 9
FileIdInfo = 18
FILE_NAME_NORMALIZED = 0x00000000
VOLUME_NAME_DOS = 0x00000000
MAX_CANONICAL_PATH_CHARS = 4096
MAX_FINAL_PATH_TCHARS = 32768

SE_FILE_OBJECT = 1
OWNER_SECURITY_INFORMATION = 0x00000001
GROUP_SECURITY_INFORMATION = 0x00000002
DACL_SECURITY_INFORMATION = 0x00000004
SE_DACL_PRESENT = 0x0004
SE_DACL_AUTO_INHERITED = 0x0400
SE_DACL_PROTECTED = 0x1000
AclRevisionInformation = 1
AclSizeInformation = 2
ACL_REVISION = 2
ACCESS_ALLOWED_ACE_TYPE = 0
OBJECT_INHERIT_ACE = 0x01
CONTAINER_INHERIT_ACE = 0x02
INHERITED_ACE = 0x10

TOKEN_DUPLICATE = 0x0002
TOKEN_QUERY = 0x0008
MAXIMUM_ALLOWED = 0x02000000
TokenUser = 1
TokenGroups = 2
TokenPrivileges = 3
TokenType = 8
TokenImpersonationLevel = 9
TokenStatistics = 10
TokenRestrictedSids = 11
TokenIsAppContainer = 29
SecurityImpersonation = 2
TokenImpersonation = 2
SE_PRIVILEGE_ENABLED = 0x00000002
ERROR_INSUFFICIENT_BUFFER = 122

FILE_GENERIC_READ = 0x00120089
FILE_GENERIC_WRITE = 0x00120116
FILE_GENERIC_EXECUTE = 0x001200A0
FILE_ALL_ACCESS = 0x001F01FF


class WindowsAclRefused(Exception):
    """Fixed refusal only; never includes native, path, SID, or token details."""


# Handle-based file/security contracts:
# https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew
# https://learn.microsoft.com/en-us/windows/win32/api/aclapi/nf-aclapi-getsecurityinfo
# AccessCheck requires an impersonation token and a GENERIC_MAPPING:
# https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-accesscheck
_SIGNATURES = MappingProxyType({
    "CreateFileW": ("kernel32.dll", HANDLE, (
        LPCWSTR, DWORD, DWORD, ctypes.POINTER(SECURITY_ATTRIBUTES), DWORD, DWORD, HANDLE,
    )),
    "CloseHandle": ("kernel32.dll", BOOL, (HANDLE,)),
    "GetHandleInformation": ("kernel32.dll", BOOL, (HANDLE, LPDWORD)),
    "SetHandleInformation": ("kernel32.dll", BOOL, (HANDLE, DWORD, DWORD)),
    "GetFinalPathNameByHandleW": ("kernel32.dll", DWORD, (
        HANDLE, LPWSTR, DWORD, DWORD,
    )),
    "GetFileInformationByHandle": ("kernel32.dll", BOOL, (
        HANDLE, ctypes.POINTER(BY_HANDLE_FILE_INFORMATION),
    )),
    "GetFileInformationByHandleEx": ("kernel32.dll", BOOL, (
        HANDLE, ctypes.c_int, LPVOID, DWORD,
    )),
    "LocalFree": ("kernel32.dll", LPVOID, (LPVOID,)),
    "GetCurrentProcess": ("kernel32.dll", HANDLE, ()),
    "GetSecurityInfo": ("advapi32.dll", DWORD, (
        HANDLE, SE_OBJECT_TYPE, DWORD, ctypes.POINTER(PSID), ctypes.POINTER(PSID),
        ctypes.POINTER(PACL), ctypes.POINTER(PACL), ctypes.POINTER(PSECURITY_DESCRIPTOR),
    )),
    "GetSecurityDescriptorOwner": ("advapi32.dll", BOOL, (
        PSECURITY_DESCRIPTOR, ctypes.POINTER(PSID), LPBOOL,
    )),
    "GetSecurityDescriptorGroup": ("advapi32.dll", BOOL, (
        PSECURITY_DESCRIPTOR, ctypes.POINTER(PSID), LPBOOL,
    )),
    "GetSecurityDescriptorDacl": ("advapi32.dll", BOOL, (
        PSECURITY_DESCRIPTOR, LPBOOL, ctypes.POINTER(PACL), LPBOOL,
    )),
    "GetSecurityDescriptorControl": ("advapi32.dll", BOOL, (
        PSECURITY_DESCRIPTOR, ctypes.POINTER(WORD), LPDWORD,
    )),
    "GetSecurityDescriptorLength": ("advapi32.dll", DWORD, (PSECURITY_DESCRIPTOR,)),
    "IsValidSecurityDescriptor": ("advapi32.dll", BOOL, (PSECURITY_DESCRIPTOR,)),
    "GetAclInformation": ("advapi32.dll", BOOL, (PACL, LPVOID, DWORD, ctypes.c_int)),
    "GetAce": ("advapi32.dll", BOOL, (PACL, DWORD, ctypes.POINTER(LPVOID))),
    "IsValidSid": ("advapi32.dll", BOOL, (PSID,)),
    "EqualSid": ("advapi32.dll", BOOL, (PSID, PSID)),
    "GetLengthSid": ("advapi32.dll", DWORD, (PSID,)),
    "ConvertSidToStringSidW": ("advapi32.dll", BOOL, (
        PSID, ctypes.POINTER(LPWSTR),
    )),
    "OpenProcessToken": ("advapi32.dll", BOOL, (HANDLE, DWORD, PHANDLE)),
    "GetTokenInformation": ("advapi32.dll", BOOL, (
        HANDLE, ctypes.c_int, LPVOID, DWORD, LPDWORD,
    )),
    "DuplicateTokenEx": ("advapi32.dll", BOOL, (
        HANDLE, DWORD, ctypes.POINTER(SECURITY_ATTRIBUTES), ctypes.c_int,
        ctypes.c_int, PHANDLE,
    )),
    "AccessCheck": ("advapi32.dll", BOOL, (
        PSECURITY_DESCRIPTOR, HANDLE, DWORD, ctypes.POINTER(GENERIC_MAPPING),
        PPRIVILEGE_SET, LPDWORD, LPDWORD, LPBOOL,
    )),
    "LookupPrivilegeValueW": ("advapi32.dll", BOOL, (
        LPCWSTR, LPCWSTR, ctypes.POINTER(LUID),
    )),
    "IsTokenRestricted": ("advapi32.dll", BOOL, (HANDLE,)),
})


class _NativeBundle:
    __slots__ = ("__kernel32", "__advapi32", "__functions", "__sealed")

    def __init__(self, kernel32, advapi32, functions):
        object.__setattr__(self, "_NativeBundle__kernel32", kernel32)
        object.__setattr__(self, "_NativeBundle__advapi32", advapi32)
        object.__setattr__(self, "_NativeBundle__functions",
                           MappingProxyType(dict(functions)))
        object.__setattr__(self, "_NativeBundle__sealed", True)

    def __setattr__(self, _name, _value):
        raise WindowsAclRefused("acl_attestation")

    def resolve(self, name):
        try:
            if type(name) is not str or name not in _SIGNATURES:
                raise WindowsAclRefused("acl_attestation")
            dll_name, expected_restype, expected_argtypes = _SIGNATURES[name]
            dll = (self.__kernel32 if dll_name == "kernel32.dll"
                   else self.__advapi32)
            stored = self.__functions[name]
            current = getattr(dll, name)
            if current is not stored or current.restype is not expected_restype:
                raise WindowsAclRefused("acl_attestation")
            if type(current.argtypes) is not list:
                raise WindowsAclRefused("acl_attestation")
            if tuple(current.argtypes) != expected_argtypes:
                raise WindowsAclRefused("acl_attestation")
            return current
        except WindowsAclRefused:
            raise
        except Exception:
            raise WindowsAclRefused("acl_attestation") from None


_NATIVE_BUNDLE_TYPE = _NativeBundle
_NATIVE_RESOLVE = _NativeBundle.resolve
_DOS_DEVICE = re.compile(r"(?:con|prn|aux|nul|com[1-9]|lpt[1-9])")


def _canonical_directory_path(value):
    if type(value) is not str or not 4 <= len(value) <= MAX_CANONICAL_PATH_CHARS:
        raise WindowsAclRefused("acl_attestation")
    try:
        value.encode("ascii")
    except Exception:
        raise WindowsAclRefused("acl_attestation") from None
    if not re.fullmatch(r"[a-z]:/(?:[^/]+)(?:/[^/]+)*", value) \
            or value != value.lower() or "\\" in value:
        raise WindowsAclRefused("acl_attestation")
    for part in value[3:].split("/"):
        if not 1 <= len(part.encode("ascii")) <= 255 \
                or part in {".", ".."} or part.endswith((".", " ")) \
                or any(ord(character) < 32 or ord(character) == 127
                       or character in '<>"|?*:' for character in part):
            raise WindowsAclRefused("acl_attestation")
        device = part.split(".", 1)[0].rstrip(" .")
        if _DOS_DEVICE.fullmatch(device) is not None:
            raise WindowsAclRefused("acl_attestation")
    return value


def _resolve_directory_functions(bundle):
    try:
        if type(bundle) is not _NATIVE_BUNDLE_TYPE \
                or type(bundle).resolve is not _NATIVE_RESOLVE:
            raise WindowsAclRefused("acl_attestation")
        names = (
            "CreateFileW", "CloseHandle", "SetHandleInformation",
            "GetHandleInformation", "GetFileInformationByHandleEx",
            "GetFinalPathNameByHandleW",
        )
        return {name: bundle.resolve(name) for name in names}
    except WindowsAclRefused:
        raise
    except Exception:
        raise WindowsAclRefused("acl_attestation") from None


def _checked_close(bundle, expected, handle):
    try:
        if type(bundle) is not _NATIVE_BUNDLE_TYPE \
                or type(bundle).resolve is not _NATIVE_RESOLVE:
            raise WindowsAclRefused("acl_attestation")
        current = bundle.resolve("CloseHandle")
        if current is not expected or not _successful(current(handle)):
            raise WindowsAclRefused("acl_attestation")
    except WindowsAclRefused:
        raise
    except Exception:
        raise WindowsAclRefused("acl_attestation") from None


def _successful(value):
    return type(value) is int and value != 0


def _final_path(function, handle):
    required = function(
        handle, None, 0, FILE_NAME_NORMALIZED | VOLUME_NAME_DOS,
    )
    if type(required) is not int or not 2 <= required <= MAX_FINAL_PATH_TCHARS:
        raise WindowsAclRefused("acl_attestation")
    buffer = ctypes.create_unicode_buffer(required)
    written = function(
        handle, buffer, required, FILE_NAME_NORMALIZED | VOLUME_NAME_DOS,
    )
    if type(written) is not int or written != required - 1 \
            or written >= required or len(buffer.value) != written:
        raise WindowsAclRefused("acl_attestation")
    opened = buffer.value
    if not opened.startswith("\\\\?\\") or opened.startswith("\\\\?\\UNC\\"):
        raise WindowsAclRefused("acl_attestation")
    canonical = opened[4:].replace("\\", "/").lower()
    return _canonical_directory_path(canonical)


def _directory_state(functions, handle, expected_path):
    flags = DWORD()
    if not _successful(functions["GetHandleInformation"](
            handle, ctypes.byref(flags))) or flags.value & HANDLE_FLAG_INHERIT:
        raise WindowsAclRefused("acl_attestation")

    attributes = FILE_ATTRIBUTE_TAG_INFO()
    if not _successful(functions["GetFileInformationByHandleEx"](
            handle, FileAttributeTagInfo, ctypes.byref(attributes),
            ctypes.sizeof(attributes))):
        raise WindowsAclRefused("acl_attestation")
    if not attributes.FileAttributes & FILE_ATTRIBUTE_DIRECTORY \
            or attributes.FileAttributes & FILE_ATTRIBUTE_REPARSE_POINT \
            or attributes.ReparseTag != 0:
        raise WindowsAclRefused("acl_attestation")

    identity = FILE_ID_INFO()
    if not _successful(functions["GetFileInformationByHandleEx"](
            handle, FileIdInfo, ctypes.byref(identity), ctypes.sizeof(identity))):
        raise WindowsAclRefused("acl_attestation")
    file_id_bytes = bytes(identity.FileId.Identifier)
    if len(file_id_bytes) != 16:
        raise WindowsAclRefused("acl_attestation")
    final_path = _final_path(functions["GetFinalPathNameByHandleW"], handle)
    if final_path != expected_path:
        raise WindowsAclRefused("acl_attestation")
    return (
        flags.value,
        attributes.FileAttributes,
        attributes.ReparseTag,
        identity.VolumeSerialNumber,
        file_id_bytes,
        final_path,
    )


class _OpenedDirectory:
    __slots__ = (
        "__bundle", "__handle", "__functions", "__path", "__state",
        "__operational", "__closed",
    )

    def __init__(self, bundle, handle, functions, path, state):
        object.__setattr__(self, "_OpenedDirectory__bundle", bundle)
        object.__setattr__(self, "_OpenedDirectory__handle", handle)
        object.__setattr__(self, "_OpenedDirectory__functions",
                           MappingProxyType(dict(functions)))
        object.__setattr__(self, "_OpenedDirectory__path", path)
        object.__setattr__(self, "_OpenedDirectory__state", state)
        object.__setattr__(self, "_OpenedDirectory__closed", False)
        object.__setattr__(self, "_OpenedDirectory__operational", (
            bundle, handle, tuple(functions.items()), path, state,
        ))

    def __setattr__(self, _name, _value):
        raise WindowsAclRefused("acl_attestation")

    def _current_functions(self):
        functions = _resolve_directory_functions(self.__bundle)
        if tuple(functions.items()) != tuple(self.__functions.items()):
            raise WindowsAclRefused("acl_attestation")
        return functions

    def validate(self):
        try:
            if self.__closed or (
                self.__bundle, self.__handle, tuple(self.__functions.items()),
                self.__path, self.__state,
            ) != self.__operational:
                raise WindowsAclRefused("acl_attestation")
            functions = self._current_functions()
            if _directory_state(functions, self.__handle, self.__path) != self.__state:
                raise WindowsAclRefused("acl_attestation")
            return None
        except WindowsAclRefused:
            raise
        except Exception:
            raise WindowsAclRefused("acl_attestation") from None

    def snapshot(self):
        self.validate()
        return {
            "path": self.__path,
            "volumeSerial": str(self.__state[3]),
            # FILE_ID_128 is opaque bytes. Decimal is a contract serialization,
            # not a claim that Windows defines a native integer byte order.
            "fileId": str(int.from_bytes(self.__state[4], "big")),
            "reparse": False,
        }

    def _close_preserving(self, primary):
        if self.__closed:
            if primary is None:
                raise WindowsAclRefused("acl_attestation")
            return
        object.__setattr__(self, "_OpenedDirectory__closed", True)
        try:
            _checked_close(
                self.__bundle, self.__functions["CloseHandle"], self.__handle,
            )
        except BaseException as error:
            if primary is not None:
                return
            if isinstance(error, Exception):
                raise WindowsAclRefused("acl_attestation") from None
            raise

    def close(self):
        self._close_preserving(None)

    def __enter__(self):
        try:
            self.validate()
            return self
        except BaseException as primary:
            self._close_preserving(primary)
            raise

    def __exit__(self, _error_type, error, _traceback):
        self._close_preserving(error)
        return False


def _open_directory(bundle, canonical_path):
    path = _canonical_directory_path(canonical_path)
    functions = _resolve_directory_functions(bundle)
    handle = None
    try:
        handle = functions["CreateFileW"](
            "\\\\?\\" + path.replace("/", "\\"),
            READ_CONTROL | FILE_READ_ATTRIBUTES,
            FILE_SHARE_READ | FILE_SHARE_WRITE,
            None,
            OPEN_EXISTING,
            FILE_FLAG_BACKUP_SEMANTICS | FILE_FLAG_OPEN_REPARSE_POINT,
            None,
        )
        if type(handle) is not int or handle in (0, INVALID_HANDLE_VALUE):
            handle = None
            raise WindowsAclRefused("acl_attestation")
        if not _successful(functions["SetHandleInformation"](
                handle, HANDLE_FLAG_INHERIT, 0)):
            raise WindowsAclRefused("acl_attestation")
        before = _directory_state(functions, handle, path)
        after = _directory_state(functions, handle, path)
        if before != after:
            raise WindowsAclRefused("acl_attestation")
        opened = _OpenedDirectory(bundle, handle, functions, path, before)
        handle = None
        return opened
    except BaseException as primary:
        if handle is not None:
            try:
                _checked_close(bundle, functions["CloseHandle"], handle)
            except BaseException:
                pass
        if isinstance(primary, WindowsAclRefused):
            raise
        if isinstance(primary, Exception):
            raise WindowsAclRefused("acl_attestation") from None
        raise


def _load_native():
    if os.name != "nt":
        raise WindowsAclRefused("acl_attestation")
    try:
        loader = ctypes.WinDLL
        dlls = {
            "kernel32.dll": loader("kernel32.dll", use_last_error=True),
            "advapi32.dll": loader("advapi32.dll", use_last_error=True),
        }
        functions = {}
        for name, (dll_name, restype, argtypes) in _SIGNATURES.items():
            function = getattr(dlls[dll_name], name)
            function.restype = restype
            function.argtypes = list(argtypes)
            functions[name] = function
        bundle = _NativeBundle(dlls["kernel32.dll"], dlls["advapi32.dll"],
                               functions)
        for name in _SIGNATURES:
            bundle.resolve(name)
        return bundle
    except WindowsAclRefused:
        raise
    except Exception:
        raise WindowsAclRefused("acl_attestation") from None
