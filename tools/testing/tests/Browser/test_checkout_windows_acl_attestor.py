"""Pure ABI tests for the lazy Windows ACL native boundary."""

import ctypes
import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import patch


SOURCE = Path(__file__).with_name("checkout-windows-acl-attestor.py")


def load_module(name="checkout_windows_acl_attestor_tested"):
    spec = importlib.util.spec_from_file_location(name, SOURCE)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FakeFunction:
    def __init__(self):
        self.restype = "unset"
        self.argtypes = "unset"


class FakeDll:
    def __init__(self, missing=None):
        self.functions = {}
        self.missing = missing
        self.errors = {}

    def __getattr__(self, name):
        if name in self.errors:
            raise self.errors[name]
        if name == self.missing:
            raise AttributeError(name)
        return self.functions.setdefault(name, FakeFunction())


def expected_signatures(module):
    """Independent ABI oracle; never derives entries from module._SIGNATURES."""
    pointer = ctypes.POINTER
    return {
        "CreateFileW": ("kernel32.dll", module.HANDLE, (
            module.LPCWSTR, module.DWORD, module.DWORD,
            pointer(module.SECURITY_ATTRIBUTES), module.DWORD, module.DWORD,
            module.HANDLE,
        )),
        "CloseHandle": ("kernel32.dll", module.BOOL, (module.HANDLE,)),
        "GetHandleInformation": ("kernel32.dll", module.BOOL, (
            module.HANDLE, module.LPDWORD,
        )),
        "SetHandleInformation": ("kernel32.dll", module.BOOL, (
            module.HANDLE, module.DWORD, module.DWORD,
        )),
        "GetFinalPathNameByHandleW": ("kernel32.dll", module.DWORD, (
            module.HANDLE, module.LPWSTR, module.DWORD, module.DWORD,
        )),
        "GetFileInformationByHandle": ("kernel32.dll", module.BOOL, (
            module.HANDLE, pointer(module.BY_HANDLE_FILE_INFORMATION),
        )),
        "GetFileInformationByHandleEx": ("kernel32.dll", module.BOOL, (
            module.HANDLE, ctypes.c_int, module.LPVOID, module.DWORD,
        )),
        "LocalFree": ("kernel32.dll", module.LPVOID, (module.LPVOID,)),
        "GetCurrentProcess": ("kernel32.dll", module.HANDLE, ()),
        "GetSecurityInfo": ("advapi32.dll", module.DWORD, (
            module.HANDLE, ctypes.c_int, module.DWORD,
            pointer(module.PSID), pointer(module.PSID), pointer(module.PACL),
            pointer(module.PACL), pointer(module.PSECURITY_DESCRIPTOR),
        )),
        "GetSecurityDescriptorOwner": ("advapi32.dll", module.BOOL, (
            module.PSECURITY_DESCRIPTOR, pointer(module.PSID), module.LPBOOL,
        )),
        "GetSecurityDescriptorGroup": ("advapi32.dll", module.BOOL, (
            module.PSECURITY_DESCRIPTOR, pointer(module.PSID), module.LPBOOL,
        )),
        "GetSecurityDescriptorDacl": ("advapi32.dll", module.BOOL, (
            module.PSECURITY_DESCRIPTOR, module.LPBOOL, pointer(module.PACL),
            module.LPBOOL,
        )),
        "GetSecurityDescriptorControl": ("advapi32.dll", module.BOOL, (
            module.PSECURITY_DESCRIPTOR, pointer(module.WORD), module.LPDWORD,
        )),
        "GetSecurityDescriptorLength": ("advapi32.dll", module.DWORD, (
            module.PSECURITY_DESCRIPTOR,
        )),
        "IsValidSecurityDescriptor": ("advapi32.dll", module.BOOL, (
            module.PSECURITY_DESCRIPTOR,
        )),
        "GetAclInformation": ("advapi32.dll", module.BOOL, (
            module.PACL, module.LPVOID, module.DWORD, ctypes.c_int,
        )),
        "GetAce": ("advapi32.dll", module.BOOL, (
            module.PACL, module.DWORD, pointer(module.LPVOID),
        )),
        "IsValidSid": ("advapi32.dll", module.BOOL, (module.PSID,)),
        "EqualSid": ("advapi32.dll", module.BOOL,
                     (module.PSID, module.PSID)),
        "GetLengthSid": ("advapi32.dll", module.DWORD, (module.PSID,)),
        "ConvertSidToStringSidW": ("advapi32.dll", module.BOOL, (
            module.PSID, pointer(module.LPWSTR),
        )),
        "OpenProcessToken": ("advapi32.dll", module.BOOL, (
            module.HANDLE, module.DWORD, module.PHANDLE,
        )),
        "GetTokenInformation": ("advapi32.dll", module.BOOL, (
            module.HANDLE, ctypes.c_int, module.LPVOID, module.DWORD,
            module.LPDWORD,
        )),
        "DuplicateTokenEx": ("advapi32.dll", module.BOOL, (
            module.HANDLE, module.DWORD, pointer(module.SECURITY_ATTRIBUTES),
            ctypes.c_int, ctypes.c_int, module.PHANDLE,
        )),
        "AccessCheck": ("advapi32.dll", module.BOOL, (
            module.PSECURITY_DESCRIPTOR, module.HANDLE, module.DWORD,
            pointer(module.GENERIC_MAPPING), pointer(module.PRIVILEGE_SET),
            module.LPDWORD, module.LPDWORD, module.LPBOOL,
        )),
        "LookupPrivilegeValueW": ("advapi32.dll", module.BOOL, (
            module.LPCWSTR, module.LPCWSTR, pointer(module.LUID),
        )),
        "IsTokenRestricted": ("advapi32.dll", module.BOOL, (module.HANDLE,)),
    }


class CheckoutWindowsAclAttestorAbiTests(unittest.TestCase):
    def test_import_is_side_effect_free_and_exposes_no_usable_attestor(self):
        with patch.object(ctypes, "WinDLL", create=True) as loader:
            module = load_module("checkout_windows_acl_attestor_import_test")
        loader.assert_not_called()
        self.assertFalse(hasattr(module, "WindowsAclAttestor"))
        self.assertEqual(str(module.WindowsAclRefused("acl_attestation")),
                         "acl_attestation")

    def test_non_windows_refuses_before_loading_any_dll(self):
        module = load_module()
        with patch.object(module.os, "name", "posix"), \
                patch.object(module.ctypes, "WinDLL", create=True) as loader, \
                self.assertRaisesRegex(module.WindowsAclRefused, "^acl_attestation$"):
            module._load_native()
        loader.assert_not_called()

    def test_loader_uses_exact_dll_names_last_error_and_signature_table(self):
        module = load_module()
        expected = expected_signatures(module)
        dlls = {"kernel32.dll": FakeDll(), "advapi32.dll": FakeDll()}
        calls = []

        def load(name, **kwargs):
            calls.append((name, kwargs))
            return dlls[name]

        with patch.object(module.os, "name", "nt"), \
                patch.object(module.ctypes, "WinDLL", side_effect=load, create=True):
            bundle = module._load_native()

        self.assertEqual(calls, [
            ("kernel32.dll", {"use_last_error": True}),
            ("advapi32.dll", {"use_last_error": True}),
        ])
        self.assertEqual(set(module._SIGNATURES), set(expected))
        self.assertEqual(dict(module._SIGNATURES), expected)
        for name, (dll_name, restype, argtypes) in expected.items():
            function = dlls[dll_name].functions[name]
            self.assertIs(function, bundle.resolve(name))
            self.assertIs(function.restype, restype)
            self.assertEqual(function.argtypes, list(argtypes))
        self.assertIs(module._SIGNATURES["GetSecurityInfo"][2][1],
                      module.SE_OBJECT_TYPE)
        self.assertIs(module._SIGNATURES["AccessCheck"][2][4],
                      module.PPRIVILEGE_SET)

    def test_bundle_refuses_unknown_names_mutation_and_native_drift(self):
        module = load_module()
        dlls = {"kernel32.dll": FakeDll(), "advapi32.dll": FakeDll()}

        def load(name, **_kwargs):
            return dlls[name]

        with patch.object(module.os, "name", "nt"), \
                patch.object(module.ctypes, "WinDLL", side_effect=load,
                             create=True):
            bundle = module._load_native()

        class StringName(str):
            pass

        for name in ("NotAnApi", StringName("CreateFileW"), 1, None):
            with self.subTest(name=name), self.assertRaisesRegex(
                    module.WindowsAclRefused, "^acl_attestation$"):
                bundle.resolve(name)

        with self.assertRaises(AttributeError):
            _ = bundle.kernel32
        with self.assertRaises(AttributeError):
            _ = bundle.functions
        for attribute in ("new_attribute", "kernel32", "functions", "resolve",
                          "_NativeBundle__kernel32"):
            with self.subTest(attribute=attribute), self.assertRaisesRegex(
                    module.WindowsAclRefused, "^acl_attestation$"):
                setattr(bundle, attribute, object())

        original = dlls["kernel32.dll"].functions["CreateFileW"]
        original.restype = module.DWORD
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$"):
            bundle.resolve("CreateFileW")
        original.restype = module.HANDLE
        original.argtypes = []
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$"):
            bundle.resolve("CreateFileW")
        original.argtypes = list(expected_signatures(module)["CreateFileW"][2])
        dlls["kernel32.dll"].functions["CreateFileW"] = FakeFunction()
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$"):
            bundle.resolve("CreateFileW")
        dlls["kernel32.dll"].functions["CreateFileW"] = original
        dlls["kernel32.dll"].errors["CreateFileW"] = RuntimeError(
            "PRIVATE NATIVE DETAIL")
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$") as raised:
            bundle.resolve("CreateFileW")
        self.assertNotIn("PRIVATE", str(raised.exception))
        interrupt = KeyboardInterrupt()
        dlls["kernel32.dll"].errors["CreateFileW"] = interrupt
        with self.assertRaises(KeyboardInterrupt) as raised:
            bundle.resolve("CreateFileW")
        self.assertIs(raised.exception, interrupt)

    def test_missing_symbol_and_ordinary_loader_errors_are_redacted(self):
        module = load_module()
        kernel = FakeDll(missing="CreateFileW")

        def missing(name, **_kwargs):
            return kernel if name == "kernel32.dll" else FakeDll()

        for loader in (missing, RuntimeError("PRIVATE DLL DETAIL")):
            with self.subTest(loader=loader), patch.object(module.os, "name", "nt"), \
                    patch.object(module.ctypes, "WinDLL", side_effect=loader, create=True), \
                    self.assertRaisesRegex(module.WindowsAclRefused,
                                                "^acl_attestation$") as raised:
                module._load_native()
            self.assertNotIn("PRIVATE", str(raised.exception))
            self.assertNotIn("CreateFileW", str(raised.exception))

    def test_loader_preserves_keyboard_interrupt_and_system_exit_identity(self):
        module = load_module()
        for error in (KeyboardInterrupt(), SystemExit(7)):
            with self.subTest(error=type(error).__name__), \
                    patch.object(module.os, "name", "nt"), \
                    patch.object(module.ctypes, "WinDLL", side_effect=error, create=True):
                with self.assertRaises(type(error)) as raised:
                    module._load_native()
            self.assertIs(raised.exception, error)

    def test_pointer_width_struct_layouts_and_constants_are_exact(self):
        module = load_module()
        self.assertEqual(ctypes.sizeof(module.BYTE), 1)
        self.assertEqual(ctypes.sizeof(module.WORD), 2)
        self.assertEqual(ctypes.sizeof(module.DWORD), 4)
        self.assertEqual(ctypes.sizeof(module.LONG), 4)
        self.assertEqual(ctypes.sizeof(module.LONGLONG), 8)
        self.assertEqual(ctypes.sizeof(module.BOOL), 4)
        self.assertEqual(ctypes.sizeof(module.HANDLE), ctypes.sizeof(ctypes.c_void_p))
        self.assertEqual(ctypes.sizeof(module.LPVOID), ctypes.sizeof(ctypes.c_void_p))
        self.assertEqual(ctypes.sizeof(module.FILE_ID_128), 16)
        self.assertEqual(ctypes.sizeof(module.FILE_ID_INFO), 24)
        self.assertEqual(module.FILE_ID_INFO.VolumeSerialNumber.offset, 0)
        self.assertEqual(module.FILE_ID_INFO.FileId.offset, 8)
        self.assertEqual(ctypes.sizeof(module.FILE_ATTRIBUTE_TAG_INFO), 8)
        self.assertEqual(ctypes.sizeof(module.ACL_SIZE_INFORMATION), 12)
        self.assertEqual(ctypes.sizeof(module.ACE_HEADER), 4)
        self.assertEqual(module.ACE_HEADER.AceType.offset, 0)
        self.assertEqual(module.ACE_HEADER.AceFlags.offset, 1)
        self.assertEqual(module.ACE_HEADER.AceSize.offset, 2)
        self.assertEqual(ctypes.sizeof(module.ACCESS_ALLOWED_ACE), 12)
        self.assertEqual(module.ACCESS_ALLOWED_ACE.Header.offset, 0)
        self.assertEqual(module.ACCESS_ALLOWED_ACE.Mask.offset, 4)
        self.assertEqual(module.ACCESS_ALLOWED_ACE.SidStart.offset, 8)
        self.assertEqual(ctypes.sizeof(module.LUID), 8)
        self.assertEqual(ctypes.sizeof(module.GENERIC_MAPPING), 16)
        self.assertEqual(module.GENERIC_MAPPING.GenericRead.offset, 0)
        self.assertEqual(module.GENERIC_MAPPING.GenericWrite.offset, 4)
        self.assertEqual(module.GENERIC_MAPPING.GenericExecute.offset, 8)
        self.assertEqual(module.GENERIC_MAPPING.GenericAll.offset, 12)
        self.assertEqual([name for name, _type in module.FILE_ID_INFO._fields_],
                         ["VolumeSerialNumber", "FileId"])
        self.assertEqual([name for name, _type in module.ACCESS_ALLOWED_ACE._fields_],
                         ["Header", "Mask", "SidStart"])
        self.assertEqual(module.INVALID_HANDLE_VALUE, ctypes.c_void_p(-1).value)
        self.assertEqual(module.READ_CONTROL | module.FILE_READ_ATTRIBUTES, 0x00020080)
        self.assertEqual(module.FILE_SHARE_READ | module.FILE_SHARE_WRITE
                         | module.FILE_SHARE_DELETE, 7)
        self.assertEqual(module.FILE_FLAG_BACKUP_SEMANTICS
                         | module.FILE_FLAG_OPEN_REPARSE_POINT, 0x02200000)
        self.assertEqual(module.OWNER_SECURITY_INFORMATION
                         | module.GROUP_SECURITY_INFORMATION
                         | module.DACL_SECURITY_INFORMATION, 7)
        self.assertEqual(module.MAXIMUM_ALLOWED, 0x02000000)
        self.assertEqual(module.ACCESS_ALLOWED_ACE_TYPE, 0)
        self.assertEqual(module.ACL_REVISION, 2)
        self.assertEqual(module.SecurityImpersonation, 2)
        self.assertEqual(module.TokenImpersonation, 2)


if __name__ == "__main__":
    unittest.main()
