"""Lazy ctypes ABI boundary for a future checkout Windows ACL attestor.

There is intentionally no usable attestor class in this RED-1 increment. Import
defines data only; native DLL discovery is explicit through ``_load_native``.
"""

from __future__ import annotations

import ctypes
import os
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
FileAttributeTagInfo = 9
FileIdInfo = 18

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
