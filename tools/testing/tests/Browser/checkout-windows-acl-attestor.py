"""Lazy ctypes ABI boundary for a future checkout Windows ACL attestor.

There is intentionally no usable attestor class in this RED-1 increment. Import
defines data only; native DLL discovery is explicit through ``_load_native``.
"""

from __future__ import annotations

import ctypes
import hashlib
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


class ACL(ctypes.Structure):
    _fields_ = [
        ("AclRevision", BYTE),
        ("Sbz1", BYTE),
        ("AclSize", WORD),
        ("AceCount", WORD),
        ("Sbz2", WORD),
    ]


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
SE_OWNER_DEFAULTED = 0x0001
SE_GROUP_DEFAULTED = 0x0002
SE_DACL_PRESENT = 0x0004
SE_DACL_DEFAULTED = 0x0008
SE_DACL_AUTO_INHERITED = 0x0400
SE_DACL_PROTECTED = 0x1000
SE_SELF_RELATIVE = 0x8000
SECURITY_DESCRIPTOR_REVISION = 1
MIN_SECURITY_DESCRIPTOR_BYTES = 20
MAX_SECURITY_DESCRIPTOR_BYTES = 65535
MIN_SID_BYTES = 8
MAX_SID_BYTES = 68
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
# https://learn.microsoft.com/en-us/windows/win32/api/securitybaseapi/nf-securitybaseapi-isvalidacl
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
    "IsValidAcl": ("advapi32.dll", BOOL, (PACL,)),
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


def _resolve_security_functions(bundle):
    try:
        if type(bundle) is not _NATIVE_BUNDLE_TYPE \
                or type(bundle).resolve is not _NATIVE_RESOLVE:
            raise WindowsAclRefused("acl_attestation")
        names = (
            "GetSecurityInfo", "LocalFree", "IsValidSecurityDescriptor",
            "GetSecurityDescriptorLength", "GetSecurityDescriptorOwner",
            "GetSecurityDescriptorGroup", "GetSecurityDescriptorDacl",
            "GetSecurityDescriptorControl", "IsValidSid", "GetLengthSid",
            "IsValidAcl", "GetAclInformation", "GetAce",
        )
        return {name: bundle.resolve(name) for name in names}
    except WindowsAclRefused:
        raise
    except Exception:
        raise WindowsAclRefused("acl_attestation") from None


def _checked_local_free(bundle, expected, descriptor):
    try:
        if type(bundle) is not _NATIVE_BUNDLE_TYPE \
                or type(bundle).resolve is not _NATIVE_RESOLVE:
            raise WindowsAclRefused("acl_attestation")
        current = bundle.resolve("LocalFree")
        if current is not expected or current(descriptor) is not None:
            raise WindowsAclRefused("acl_attestation")
    except WindowsAclRefused:
        raise
    except Exception:
        raise WindowsAclRefused("acl_attestation") from None


def _successful(value):
    return type(value) is int and value != 0


def _exact_unsigned(value, maximum):
    return type(value) is int and 0 <= value <= maximum


def _pointer(value):
    pointer = getattr(value, "value", None)
    if type(pointer) is not int or pointer <= 0:
        raise WindowsAclRefused("acl_attestation")
    return pointer


def _contained(base, total, start, length):
    maximum = (1 << (ctypes.sizeof(ctypes.c_void_p) * 8)) - 1
    return _exact_unsigned(base, maximum) and 1 <= total <= maximum \
        and base <= maximum - (total - 1) \
        and _exact_unsigned(start, maximum) and 1 <= length <= total \
        and base <= start and start <= base + total - length


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


class _SecuritySnapshot:
    __slots__ = ("__values",)

    _KEYS = (
        "ownerSidBytes", "groupSidBytes", "daclDigest", "control",
        "ownerDefaulted", "groupDefaulted", "daclDefaulted",
        "descriptorRevision", "daclRevision", "aceCount",
        "daclBytesInUse", "daclSize", "ownerSid", "trusteeSid",
        "aceType", "aceFlags", "accessMask", "aceSize",
    )

    def __init__(self, values):
        if type(values) is not tuple or len(values) != len(self._KEYS):
            raise WindowsAclRefused("acl_attestation")
        object.__setattr__(self, "_SecuritySnapshot__values", values)

    def __setattr__(self, _name, _value):
        raise WindowsAclRefused("acl_attestation")

    def __eq__(self, other):
        return type(other) is _SecuritySnapshot \
            and self.__values == other.__values

    def values(self):
        return MappingProxyType(dict(zip(self._KEYS, self.__values, strict=True)))


def _canonical_sid(value):
    if type(value) is not bytes or not MIN_SID_BYTES <= len(value) <= MAX_SID_BYTES:
        raise WindowsAclRefused("acl_attestation")
    revision = value[0]
    subauthority_count = value[1]
    expected_length = 8 + 4 * subauthority_count
    if revision != 1 or not 1 <= subauthority_count <= 15 \
            or len(value) != expected_length:
        raise WindowsAclRefused("acl_attestation")
    authority = int.from_bytes(value[2:8], "big", signed=False)
    subauthorities = [
        int.from_bytes(value[index:index + 4], "little", signed=False)
        for index in range(8, expected_length, 4)
    ]
    return "S-1-" + str(authority) + "-" + "-".join(
        str(part) for part in subauthorities
    )


def _security_descriptor_parts(functions, descriptor, owner, group, dacl):
    if not _successful(functions["IsValidSecurityDescriptor"](descriptor)):
        raise WindowsAclRefused("acl_attestation")
    descriptor_length = functions["GetSecurityDescriptorLength"](descriptor)
    if not _exact_unsigned(descriptor_length, MAX_SECURITY_DESCRIPTOR_BYTES) \
            or descriptor_length < MIN_SECURITY_DESCRIPTOR_BYTES:
        raise WindowsAclRefused("acl_attestation")
    base = _pointer(descriptor)
    if not _contained(base, descriptor_length, base, descriptor_length):
        raise WindowsAclRefused("acl_attestation")

    owner_read = PSID()
    owner_defaulted = BOOL()
    if not _successful(functions["GetSecurityDescriptorOwner"](
            descriptor, ctypes.byref(owner_read), ctypes.byref(owner_defaulted))):
        raise WindowsAclRefused("acl_attestation")
    group_read = PSID()
    group_defaulted = BOOL()
    if not _successful(functions["GetSecurityDescriptorGroup"](
            descriptor, ctypes.byref(group_read), ctypes.byref(group_defaulted))):
        raise WindowsAclRefused("acl_attestation")
    dacl_present = BOOL()
    dacl_read = PACL()
    dacl_defaulted = BOOL()
    if not _successful(functions["GetSecurityDescriptorDacl"](
            descriptor, ctypes.byref(dacl_present), ctypes.byref(dacl_read),
            ctypes.byref(dacl_defaulted))):
        raise WindowsAclRefused("acl_attestation")
    boolean_values = (
        owner_defaulted.value, group_defaulted.value, dacl_present.value,
        dacl_defaulted.value,
    )
    if any(type(value) is not int or value not in (0, 1)
           for value in boolean_values):
        raise WindowsAclRefused("acl_attestation")

    owner_address = _pointer(owner)
    group_address = _pointer(group)
    dacl_address = _pointer(dacl)
    if _pointer(owner_read) != owner_address or _pointer(group_read) != group_address \
            or _pointer(dacl_read) != dacl_address \
            or dacl_present.value != 1:
        raise WindowsAclRefused("acl_attestation")

    control = WORD()
    descriptor_revision = DWORD()
    if not _successful(functions["GetSecurityDescriptorControl"](
            descriptor, ctypes.byref(control), ctypes.byref(descriptor_revision))):
        raise WindowsAclRefused("acl_attestation")
    required_control = SE_SELF_RELATIVE | SE_DACL_PRESENT | SE_DACL_PROTECTED
    if descriptor_revision.value != SECURITY_DESCRIPTOR_REVISION \
            or control.value & required_control != required_control \
            or control.value & SE_DACL_AUTO_INHERITED \
            or bool(control.value & SE_OWNER_DEFAULTED) != bool(owner_defaulted.value) \
            or bool(control.value & SE_GROUP_DEFAULTED) != bool(group_defaulted.value) \
            or bool(control.value & SE_DACL_DEFAULTED) != bool(dacl_defaulted.value):
        raise WindowsAclRefused("acl_attestation")

    sid_values = []
    for sid, address in ((owner, owner_address), (group, group_address)):
        if not _contained(base, descriptor_length, address, MIN_SID_BYTES):
            raise WindowsAclRefused("acl_attestation")
        sid_prefix = ctypes.string_at(address, MIN_SID_BYTES)
        claimed_sid_length = 8 + 4 * sid_prefix[1]
        if sid_prefix[0] != 1 or not 1 <= sid_prefix[1] <= 15 \
                or not MIN_SID_BYTES <= claimed_sid_length <= MAX_SID_BYTES \
                or not _contained(base, descriptor_length, address,
                                  claimed_sid_length):
            raise WindowsAclRefused("acl_attestation")
        if not _successful(functions["IsValidSid"](sid)):
            raise WindowsAclRefused("acl_attestation")
        sid_length = functions["GetLengthSid"](sid)
        if not _exact_unsigned(sid_length, MAX_SID_BYTES) \
                or sid_length != claimed_sid_length:
            raise WindowsAclRefused("acl_attestation")
        sid_bytes = ctypes.string_at(address, sid_length)
        if sid_bytes[:MIN_SID_BYTES] != sid_prefix:
            raise WindowsAclRefused("acl_attestation")
        sid_values.append(sid_bytes)

    if not _contained(base, descriptor_length, dacl_address, ctypes.sizeof(ACL)):
        raise WindowsAclRefused("acl_attestation")
    header_bytes = ctypes.string_at(dacl_address, ctypes.sizeof(ACL))
    header = ACL.from_buffer_copy(header_bytes)
    dacl_size = header.AclSize
    if header.AclRevision != ACL_REVISION \
            or header.Sbz1 != 0 or header.Sbz2 != 0 \
            or not 8 <= dacl_size <= MAX_SECURITY_DESCRIPTOR_BYTES \
            or not _contained(base, descriptor_length, dacl_address, dacl_size):
        raise WindowsAclRefused("acl_attestation")
    if not _successful(functions["IsValidAcl"](dacl)):
        raise WindowsAclRefused("acl_attestation")
    size_information = ACL_SIZE_INFORMATION()
    if not _successful(functions["GetAclInformation"](
            dacl, ctypes.byref(size_information), ctypes.sizeof(size_information),
            AclSizeInformation)):
        raise WindowsAclRefused("acl_attestation")
    revision_information = ACL_REVISION_INFORMATION()
    if not _successful(functions["GetAclInformation"](
            dacl, ctypes.byref(revision_information),
            ctypes.sizeof(revision_information), AclRevisionInformation)):
        raise WindowsAclRefused("acl_attestation")

    bytes_in_use = size_information.AclBytesInUse
    bytes_free = size_information.AclBytesFree
    if revision_information.AclRevision != ACL_REVISION \
            or header.AceCount != size_information.AceCount \
            or size_information.AceCount != 1 \
            or not 8 <= bytes_in_use <= dacl_size <= MAX_SECURITY_DESCRIPTOR_BYTES \
            or size_information.AceCount > (bytes_in_use - 8) // 4 \
            or bytes_in_use + bytes_free != dacl_size:
        raise WindowsAclRefused("acl_attestation")

    ace = LPVOID()
    if not _successful(functions["GetAce"](dacl, 0, ctypes.byref(ace))):
        raise WindowsAclRefused("acl_attestation")
    ace_address = _pointer(ace)
    expected_ace_address = dacl_address + ctypes.sizeof(ACL)
    if ace_address != expected_ace_address \
            or ace_address % ctypes.alignment(DWORD) != 0 \
            or not _contained(base, descriptor_length, ace_address,
                              ctypes.sizeof(ACE_HEADER)) \
            or not _contained(dacl_address, bytes_in_use, ace_address,
                              ctypes.sizeof(ACE_HEADER)):
        raise WindowsAclRefused("acl_attestation")
    dacl_bytes = ctypes.string_at(dacl_address, bytes_in_use)
    if dacl_bytes[:ctypes.sizeof(ACL)] != header_bytes:
        raise WindowsAclRefused("acl_attestation")
    ace_header = ACE_HEADER.from_buffer_copy(
        dacl_bytes[ctypes.sizeof(ACL):
                   ctypes.sizeof(ACL) + ctypes.sizeof(ACE_HEADER)],
    )
    ace_size = ace_header.AceSize
    if ace_header.AceType != ACCESS_ALLOWED_ACE_TYPE \
            or ace_header.AceFlags != OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE \
            or ace_size % ctypes.alignment(DWORD) != 0 \
            or ace_size < 8 + MIN_SID_BYTES \
            or 8 + ace_size != bytes_in_use \
            or not _contained(base, descriptor_length, ace_address, ace_size) \
            or not _contained(dacl_address, bytes_in_use, ace_address, ace_size):
        raise WindowsAclRefused("acl_attestation")

    trustee_address = ace_address + ACCESS_ALLOWED_ACE.SidStart.offset
    if not _contained(base, descriptor_length, trustee_address, MIN_SID_BYTES) \
            or not _contained(dacl_address, bytes_in_use, trustee_address,
                              MIN_SID_BYTES):
        raise WindowsAclRefused("acl_attestation")
    trustee_offset = ctypes.sizeof(ACL) + ACCESS_ALLOWED_ACE.SidStart.offset
    trustee_prefix = dacl_bytes[trustee_offset:
                                trustee_offset + MIN_SID_BYTES]
    claimed_trustee_length = 8 + 4 * trustee_prefix[1]
    if trustee_prefix[0] != 1 or not 1 <= trustee_prefix[1] <= 15 \
            or not MIN_SID_BYTES <= claimed_trustee_length <= MAX_SID_BYTES \
            or not _contained(base, descriptor_length, trustee_address,
                              claimed_trustee_length) \
            or not _contained(dacl_address, bytes_in_use, trustee_address,
                              claimed_trustee_length):
        raise WindowsAclRefused("acl_attestation")
    trustee = PSID(trustee_address)
    if not _successful(functions["IsValidSid"](trustee)):
        raise WindowsAclRefused("acl_attestation")
    trustee_length = functions["GetLengthSid"](trustee)
    if not _exact_unsigned(trustee_length, MAX_SID_BYTES) \
            or trustee_length != claimed_trustee_length \
            or ace_size != ACCESS_ALLOWED_ACE.SidStart.offset + trustee_length:
        raise WindowsAclRefused("acl_attestation")
    trustee_bytes = dacl_bytes[trustee_offset:trustee_offset + trustee_length]
    if ctypes.string_at(trustee_address, trustee_length) != trustee_bytes:
        raise WindowsAclRefused("acl_attestation")
    owner_sid = _canonical_sid(sid_values[0])
    trustee_sid = _canonical_sid(trustee_bytes)
    if trustee_bytes != sid_values[0] or trustee_sid != owner_sid:
        raise WindowsAclRefused("acl_attestation")

    access_mask = int.from_bytes(
        dacl_bytes[ctypes.sizeof(ACL) + ACCESS_ALLOWED_ACE.Mask.offset:
                   ctypes.sizeof(ACL) + ACCESS_ALLOWED_ACE.Mask.offset + 4],
        "little", signed=False,
    )
    return _SecuritySnapshot((
        sid_values[0], sid_values[1], hashlib.sha256(dacl_bytes).hexdigest(),
        control.value, bool(owner_defaulted.value), bool(group_defaulted.value),
        bool(dacl_defaulted.value), descriptor_revision.value,
        revision_information.AclRevision, size_information.AceCount,
        bytes_in_use, dacl_size, owner_sid, trustee_sid,
        ace_header.AceType, ace_header.AceFlags, access_mask, ace_size,
    ))


def _one_security_snapshot(bundle, functions, handle):
    owner = PSID()
    group = PSID()
    dacl = PACL()
    descriptor = PSECURITY_DESCRIPTOR()
    result = None
    primary = None
    try:
        status = functions["GetSecurityInfo"](
            handle,
            SE_FILE_OBJECT,
            OWNER_SECURITY_INFORMATION | GROUP_SECURITY_INFORMATION
            | DACL_SECURITY_INFORMATION,
            ctypes.byref(owner),
            ctypes.byref(group),
            ctypes.byref(dacl),
            None,
            ctypes.byref(descriptor),
        )
        if type(status) is not int or status != 0:
            raise WindowsAclRefused("acl_attestation")
        _pointer(descriptor)
        result = _security_descriptor_parts(
            functions, descriptor, owner, group, dacl,
        )
    except BaseException as error:
        primary = error

    if getattr(descriptor, "value", None) is not None:
        try:
            _checked_local_free(bundle, functions["LocalFree"], descriptor)
        except BaseException as error:
            if primary is None:
                primary = error

    if primary is not None:
        if isinstance(primary, WindowsAclRefused):
            raise primary
        if isinstance(primary, Exception):
            raise WindowsAclRefused("acl_attestation") from None
        raise primary
    if type(result) is not _SecuritySnapshot:
        raise WindowsAclRefused("acl_attestation")
    return result


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

    def _security_snapshot(self):
        result = None
        primary = None
        try:
            self.validate()
            first_functions = _resolve_security_functions(self.__bundle)
            first = _one_security_snapshot(
                self.__bundle, first_functions, self.__handle,
            )
            second_functions = _resolve_security_functions(self.__bundle)
            if tuple(second_functions.items()) != tuple(first_functions.items()):
                raise WindowsAclRefused("acl_attestation")
            second = _one_security_snapshot(
                self.__bundle, second_functions, self.__handle,
            )
            if first != second:
                raise WindowsAclRefused("acl_attestation")
            result = first
        except BaseException as error:
            primary = error
        try:
            self.validate()
        except BaseException as error:
            if primary is None:
                primary = error
        if primary is not None:
            if isinstance(primary, WindowsAclRefused):
                raise primary
            if isinstance(primary, Exception):
                raise WindowsAclRefused("acl_attestation") from None
            raise primary
        if type(result) is not _SecuritySnapshot:
            raise WindowsAclRefused("acl_attestation")
        return result

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
