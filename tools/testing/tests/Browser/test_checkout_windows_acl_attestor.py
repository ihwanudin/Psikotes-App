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


class CallableFakeFunction(FakeFunction):
    def __init__(self, name, implementation, calls):
        super().__init__()
        self.name = name
        self.implementation = implementation
        self.calls = calls

    def __call__(self, *args):
        self.calls.append((self.name, args))
        return self.implementation(*args)


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


class NativeDirectoryHarness:
    def __init__(self, module, *, path="c:/oncam/run/source",
                 file_id=b"\0" * 14 + b"\x01\x02"):
        self.module = module
        self.path = path
        self.final_path = "\\\\?\\" + path.replace("/", "\\").upper()
        self.file_id = file_id
        self.volume_serial = 17
        self.attributes = module.FILE_ATTRIBUTE_DIRECTORY
        self.reparse_tag = 0
        self.handle_flags = 0
        self.handle = 123
        self.calls = []
        self.close_result = 1
        self.close_error = None
        self.state_reads = 0
        self.mutate_identity_after = None
        self.dlls = {"kernel32.dll": FakeDll(), "advapi32.dll": FakeDll()}
        for name, (dll_name, _restype, _argtypes) in expected_signatures(module).items():
            implementation = getattr(self, "call_" + name, self.call_unused)
            self.dlls[dll_name].functions[name] = CallableFakeFunction(
                name, implementation, self.calls,
            )

    def loader(self, name, **_kwargs):
        return self.dlls[name]

    def bundle(self):
        with patch.object(self.module.os, "name", "nt"), \
                patch.object(self.module.ctypes, "WinDLL", side_effect=self.loader,
                             create=True):
            return self.module._load_native()

    @staticmethod
    def call_unused(*_args):
        raise AssertionError("unexpected native function call")

    def call_CreateFileW(self, *_args):
        return self.handle

    @staticmethod
    def call_SetHandleInformation(*_args):
        return 1

    def call_GetHandleInformation(self, _handle, flags):
        flags._obj.value = self.handle_flags
        return 1

    def call_GetFileInformationByHandleEx(self, _handle, info_class, output,
                                          output_size):
        if info_class == self.module.FileAttributeTagInfo:
            self.state_reads += 1
            value = output._obj
            self.assert_size(value, output_size)
            value.FileAttributes = self.attributes
            value.ReparseTag = self.reparse_tag
            return 1
        if info_class == self.module.FileIdInfo:
            value = output._obj
            self.assert_size(value, output_size)
            value.VolumeSerialNumber = self.volume_serial
            raw = self.file_id
            if self.mutate_identity_after is not None \
                    and self.state_reads >= self.mutate_identity_after:
                raw = b"\0" * 15 + b"\x09"
            for index, byte in enumerate(raw):
                value.FileId.Identifier[index] = byte
            return 1
        raise AssertionError("unexpected information class")

    def assert_size(self, value, output_size):
        if output_size != ctypes.sizeof(value):
            raise AssertionError("wrong native structure size")

    def call_GetFinalPathNameByHandleW(self, _handle, buffer, capacity, flags):
        if flags != self.module.FILE_NAME_NORMALIZED | self.module.VOLUME_NAME_DOS:
            raise AssertionError("wrong final path flags")
        if buffer is None:
            if capacity != 0:
                raise AssertionError("probe must have zero capacity")
            return len(self.final_path) + 1
        buffer.value = self.final_path
        return len(self.final_path)

    def call_CloseHandle(self, _handle):
        if self.close_error is not None:
            raise self.close_error
        return self.close_result


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
        self.assertEqual(module.FILE_ATTRIBUTE_DIRECTORY, 0x00000010)
        self.assertEqual(module.FileAttributeTagInfo, 9)
        self.assertEqual(module.FileIdInfo, 18)
        self.assertEqual(module.FILE_NAME_NORMALIZED, 0)
        self.assertEqual(module.VOLUME_NAME_DOS, 0)
        self.assertEqual(module.OWNER_SECURITY_INFORMATION
                         | module.GROUP_SECURITY_INFORMATION
                         | module.DACL_SECURITY_INFORMATION, 7)
        self.assertEqual(module.MAXIMUM_ALLOWED, 0x02000000)
        self.assertEqual(module.ACCESS_ALLOWED_ACE_TYPE, 0)
        self.assertEqual(module.ACL_REVISION, 2)
        self.assertEqual(module.SecurityImpersonation, 2)
        self.assertEqual(module.TokenImpersonation, 2)


class CheckoutWindowsAclDirectoryHandleTests(unittest.TestCase):
    def assert_refused(self, module, callback):
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$"):
            callback()

    @staticmethod
    def call_names(harness):
        return [name for name, _args in harness.calls]

    def test_open_validates_twice_exposes_known_vector_and_closes_once(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        bundle = harness.bundle()
        opened = module._open_directory(bundle, harness.path)

        create = next(args for name, args in harness.calls
                      if name == "CreateFileW")
        self.assertEqual(create, (
            "\\\\?\\c:\\oncam\\run\\source",
            module.READ_CONTROL | module.FILE_READ_ATTRIBUTES,
            module.FILE_SHARE_READ | module.FILE_SHARE_WRITE,
            None,
            module.OPEN_EXISTING,
            module.FILE_FLAG_BACKUP_SEMANTICS
            | module.FILE_FLAG_OPEN_REPARSE_POINT,
            None,
        ))
        self.assertEqual(create[1], 0x00020080)
        self.assertEqual(create[2], 0x00000003)
        self.assertEqual(create[4], 3)
        self.assertEqual(create[5], 0x02200000)
        self.assertNotIn("FILE_SHARE_DELETE", repr(create[2]))
        self.assertEqual(harness.state_reads, 2)
        self.assertEqual(opened.snapshot(), {
            "path": harness.path,
            "volumeSerial": "17",
            "fileId": "258",
            "reparse": False,
        })
        self.assertEqual(harness.state_reads, 3)
        information_calls = [
            args for name, args in harness.calls
            if name == "GetFileInformationByHandleEx"
        ]
        self.assertEqual([args[1] for args in information_calls], [9, 18] * 3)
        self.assertEqual([args[3] for args in information_calls], [8, 24] * 3)
        final_calls = [
            args for name, args in harness.calls
            if name == "GetFinalPathNameByHandleW"
        ]
        self.assertEqual([args[3] for args in final_calls], [0] * 6)
        self.assertEqual([args[2] for args in final_calls[::2]], [0] * 3)
        self.assertTrue(all(args[2] == len(harness.final_path) + 1
                            for args in final_calls[1::2]))
        self.assertFalse(hasattr(opened, "handle"))
        opened.close()
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)
        self.assert_refused(module, opened.close)
        self.assert_refused(module, opened.snapshot)

        sensitive = {
            "GetSecurityInfo", "GetSecurityDescriptorOwner",
            "GetSecurityDescriptorDacl", "OpenProcessToken", "AccessCheck",
        }
        self.assertFalse(sensitive.intersection(self.call_names(harness)))

        leading = NativeDirectoryHarness(
            module, file_id=b"\0" * 15 + b"\x01",
        )
        leading_opened = module._open_directory(leading.bundle(), leading.path)
        self.assertEqual(leading_opened.snapshot()["fileId"], "1")
        leading_opened.close()

        high_byte = NativeDirectoryHarness(
            module, file_id=b"\x01" + b"\0" * 15,
        )
        high_byte_opened = module._open_directory(
            high_byte.bundle(), high_byte.path,
        )
        self.assertEqual(
            high_byte_opened.snapshot()["fileId"], str(2 ** 120),
        )
        high_byte_opened.close()

    def test_invalid_paths_and_bundle_refuse_before_create(self):
        module = load_module()
        invalid_paths = (
            "C:/oncam/run", "c:\\oncam\\run", "//server/share/run",
            "c:/oncam/../run", "c:/oncam/run/", "c:/oncam/con.txt",
            "c:/oncam/bad.", "c:/oncam/bad ", "c:/oncam/a*", "c:/tést/run",
            "c:/" + "a" * 256,
        )
        for path in invalid_paths:
            harness = NativeDirectoryHarness(module)
            bundle = harness.bundle()
            with self.subTest(path=path):
                self.assert_refused(
                    module, lambda path=path: module._open_directory(bundle, path),
                )
                self.assertEqual(harness.calls, [])

        harness = NativeDirectoryHarness(module)
        self.assert_refused(
            module, lambda: module._open_directory(object(), harness.path),
        )
        self.assertEqual(harness.calls, [])

    def test_invalid_handle_and_noninheritance_fail_closed(self):
        module = load_module()
        for handle in (0, module.INVALID_HANDLE_VALUE, None):
            harness = NativeDirectoryHarness(module)
            harness.handle = handle
            with self.subTest(handle=handle):
                self.assert_refused(
                    module, lambda: module._open_directory(
                        harness.bundle(), harness.path,
                    ),
                )
                self.assertNotIn("CloseHandle", self.call_names(harness))

        for boundary in ("set", "get", "inherit"):
            harness = NativeDirectoryHarness(module)
            if boundary == "set":
                harness.dlls["kernel32.dll"].functions[
                    "SetHandleInformation"
                ].implementation = lambda *_args: 0
            elif boundary == "get":
                harness.dlls["kernel32.dll"].functions[
                    "GetHandleInformation"
                ].implementation = lambda *_args: 0
            else:
                harness.handle_flags = module.HANDLE_FLAG_INHERIT
            with self.subTest(boundary=boundary):
                self.assert_refused(
                    module, lambda: module._open_directory(
                        harness.bundle(), harness.path,
                    ),
                )
                self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_directory_reparse_and_identity_drift_fail_closed(self):
        module = load_module()
        cases = (
            (0, 0, None),
            (module.FILE_ATTRIBUTE_DIRECTORY
             | module.FILE_ATTRIBUTE_REPARSE_POINT, 0xA000000C, None),
            (module.FILE_ATTRIBUTE_DIRECTORY, 7, None),
            (module.FILE_ATTRIBUTE_DIRECTORY, 0, 2),
        )
        for attributes, tag, mutate_after in cases:
            harness = NativeDirectoryHarness(module)
            harness.attributes = attributes
            harness.reparse_tag = tag
            harness.mutate_identity_after = mutate_after
            with self.subTest(attributes=attributes, tag=tag,
                              mutate_after=mutate_after):
                self.assert_refused(
                    module, lambda: module._open_directory(
                        harness.bundle(), harness.path,
                    ),
                )
                self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_final_path_probe_bounds_consistency_and_exact_match(self):
        module = load_module()

        def run_with(implementation):
            harness = NativeDirectoryHarness(module)
            harness.dlls["kernel32.dll"].functions[
                "GetFinalPathNameByHandleW"
            ].implementation = implementation
            self.assert_refused(
                module, lambda: module._open_directory(harness.bundle(), harness.path),
            )
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

        run_with(lambda *_args: 0)
        run_with(lambda *_args: module.MAX_FINAL_PATH_TCHARS + 1)

        harness = NativeDirectoryHarness(module)

        def inconsistent(_handle, buffer, _capacity, _flags):
            if buffer is None:
                return len(harness.final_path) + 1
            buffer.value = harness.final_path
            return len(harness.final_path) - 1

        run_with(inconsistent)

        for final_path in (
            "\\\\?\\C:\\different\\run\\source",
            "\\\\?\\UNC\\server\\share\\source",
            "C:\\oncam\\run\\source",
        ):
            harness = NativeDirectoryHarness(module)
            harness.final_path = final_path
            with self.subTest(final_path=final_path):
                self.assert_refused(
                    module, lambda: module._open_directory(
                        harness.bundle(), harness.path,
                    ),
                )
                self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_post_open_path_or_symbol_drift_refuses_but_captured_close_works(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        bundle = harness.bundle()
        opened = module._open_directory(bundle, harness.path)
        harness.final_path = "\\\\?\\C:\\different"
        self.assert_refused(module, opened.validate)
        opened.close()
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

        harness = NativeDirectoryHarness(module)
        bundle = harness.bundle()
        opened = module._open_directory(bundle, harness.path)
        harness.dlls["kernel32.dll"].functions["GetHandleInformation"] = (
            CallableFakeFunction("GetHandleInformation", lambda *_args: 1,
                                 harness.calls)
        )
        self.assert_refused(module, opened.validate)
        opened.close()
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_cleanup_preserves_primary_and_explicit_close_is_exhaustive(self):
        module = load_module()
        primary = KeyboardInterrupt()
        harness = NativeDirectoryHarness(module)

        def interrupt(*_args):
            raise primary

        harness.dlls["kernel32.dll"].functions[
            "GetHandleInformation"
        ].implementation = interrupt
        harness.close_error = SystemExit(8)
        with self.assertRaises(KeyboardInterrupt) as raised:
            module._open_directory(harness.bundle(), harness.path)
        self.assertIs(raised.exception, primary)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

        harness = NativeDirectoryHarness(module)
        opened = module._open_directory(harness.bundle(), harness.path)
        close_interrupt = KeyboardInterrupt()
        harness.close_error = close_interrupt
        with self.assertRaises(KeyboardInterrupt) as raised:
            opened.close()
        self.assertIs(raised.exception, close_interrupt)
        self.assert_refused(module, opened.close)

        harness = NativeDirectoryHarness(module)
        opened = module._open_directory(harness.bundle(), harness.path)
        harness.close_result = 0
        self.assert_refused(module, opened.close)
        self.assert_refused(module, opened.close)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

        harness = NativeDirectoryHarness(module)
        opened = module._open_directory(harness.bundle(), harness.path)
        body_error = RuntimeError("body marker")
        harness.close_error = SystemExit(9)
        with self.assertRaises(RuntimeError) as raised:
            with opened:
                raise body_error
        self.assertIs(raised.exception, body_error)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_enter_validation_failure_closes_and_preserves_primary(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        opened = module._open_directory(harness.bundle(), harness.path)
        harness.final_path = "\\\\?\\C:\\changed"
        harness.close_result = 0
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$"):
            with opened:
                self.fail("body must not run")
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)
        self.assert_refused(module, opened.close)

        harness = NativeDirectoryHarness(module)
        opened = module._open_directory(harness.bundle(), harness.path)
        interrupt = KeyboardInterrupt()

        def interrupt_validation(*_args):
            raise interrupt

        harness.dlls["kernel32.dll"].functions[
            "GetHandleInformation"
        ].implementation = interrupt_validation
        harness.close_error = SystemExit(11)
        with self.assertRaises(KeyboardInterrupt) as raised:
            with opened:
                self.fail("body must not run")
        self.assertIs(raised.exception, interrupt)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_close_rechecks_exact_native_symbol_before_calling_it(self):
        module = load_module()
        for drift in ("symbol", "signature"):
            harness = NativeDirectoryHarness(module)
            bundle = harness.bundle()
            opened = module._open_directory(bundle, harness.path)
            if drift == "symbol":
                replacement = CallableFakeFunction(
                    "CloseHandle", lambda *_args: 1, harness.calls,
                )
                replacement.restype = module.BOOL
                replacement.argtypes = [module.HANDLE]
                harness.dlls["kernel32.dll"].functions["CloseHandle"] = replacement
            else:
                harness.dlls["kernel32.dll"].functions[
                    "CloseHandle"
                ].argtypes = []
            with self.subTest(drift=drift):
                self.assert_refused(module, opened.close)
                self.assertEqual(self.call_names(harness).count("CloseHandle"), 0)
                self.assert_refused(module, opened.close)


if __name__ == "__main__":
    unittest.main()
