"""Pure ABI tests for the lazy Windows ACL native boundary."""

import ctypes
import hashlib
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
        "IsValidAcl": ("advapi32.dll", module.BOOL, (module.PACL,)),
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
        self.security_statuses = [0, 0]
        self.allocate_descriptor = True
        self.security_call = 0
        self.security_buffers = []
        self.security_records = {}
        self.security_mutator = None
        self.local_free_result = None
        self.local_free_error = None
        self.valid_descriptor = 1
        self.valid_acl = 1
        self.valid_sid = 1
        self.descriptor_length = 128
        self.control = (module.SE_SELF_RELATIVE | module.SE_DACL_PRESENT
                        | module.SE_DACL_PROTECTED)
        self.descriptor_revision = 1
        self.owner_defaulted = 0
        self.group_defaulted = 0
        self.dacl_defaulted = 0
        self.dacl_present = 1
        self.acl_revision = 2
        self.header_acl_revision = 2
        self.header_sbz1 = 0
        self.header_sbz2 = 0
        self.acl_bytes_in_use = 12
        self.acl_bytes_free = 4
        self.acl_size = 16
        self.header_acl_size = 16
        self.ace_count = 1
        self.header_ace_count = 1
        self.owner_pointer_override = None
        self.group_pointer_override = None
        self.dacl_pointer_override = None
        self.owner_length_override = None
        self.group_length_override = None
        self.owner_sid = bytes.fromhex("010100000000000520000000")
        self.group_sid = bytes.fromhex("010100000000000512000000")
        self.owner_offset = 20
        self.group_offset = 40
        self.dacl_offset = 64
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

    def _new_security_record(self, index):
        raw = bytearray(self.descriptor_length)
        owner_offset = self.owner_offset
        group_offset = self.group_offset
        dacl_offset = self.dacl_offset
        raw[owner_offset:owner_offset + len(self.owner_sid)] = self.owner_sid
        raw[group_offset:group_offset + len(self.group_sid)] = self.group_sid
        header = self.module.ACL()
        header.AclRevision = self.header_acl_revision
        header.Sbz1 = self.header_sbz1
        header.AclSize = self.header_acl_size
        header.AceCount = self.header_ace_count
        header.Sbz2 = self.header_sbz2
        header_bytes = bytes(header)
        raw[dacl_offset:dacl_offset + len(header_bytes)] = header_bytes
        payload_length = max(
            0, min(self.acl_bytes_in_use - 8, len(raw) - dacl_offset - 8),
        )
        raw[dacl_offset + 8:dacl_offset + 8 + payload_length] = (
            b"A" * payload_length
        )
        if self.security_mutator is not None:
            self.security_mutator(index, raw)
        buffer = ctypes.create_string_buffer(bytes(raw), len(raw))
        base = ctypes.addressof(buffer)
        record = {
            "buffer": buffer,
            "base": base,
            "owner": base + owner_offset,
            "group": base + group_offset,
            "dacl": base + dacl_offset,
            "ownerLength": len(self.owner_sid),
            "groupLength": len(self.group_sid),
        }
        self.security_buffers.append(buffer)
        self.security_records[base] = record
        return record

    def _record_for_pointer(self, pointer):
        address = pointer.value if hasattr(pointer, "value") else pointer
        for record in self.security_records.values():
            if record["base"] <= address < record["base"] + self.descriptor_length:
                return record
        raise AssertionError("pointer outside fake descriptor")

    def call_GetSecurityInfo(self, _handle, object_type, information, owner,
                             group, dacl, sacl, descriptor):
        index = self.security_call
        self.security_call += 1
        status = self.security_statuses[min(index, len(self.security_statuses) - 1)]
        if not self.allocate_descriptor:
            return status
        record = self._new_security_record(index)
        owner._obj.value = (record["owner"] if self.owner_pointer_override is None
                            else self.owner_pointer_override)
        group._obj.value = (record["group"] if self.group_pointer_override is None
                            else self.group_pointer_override)
        dacl._obj.value = (record["dacl"] if self.dacl_pointer_override is None
                           else self.dacl_pointer_override)
        descriptor._obj.value = record["base"]
        return status

    def call_LocalFree(self, _descriptor):
        if self.local_free_error is not None:
            raise self.local_free_error
        return self.local_free_result

    def call_IsValidSecurityDescriptor(self, _descriptor):
        return self.valid_descriptor

    def call_IsValidAcl(self, _dacl):
        return self.valid_acl

    def call_GetSecurityDescriptorLength(self, _descriptor):
        return self.descriptor_length

    def call_GetSecurityDescriptorOwner(self, descriptor, owner, defaulted):
        record = self._record_for_pointer(descriptor)
        owner._obj.value = (record["owner"] if self.owner_pointer_override is None
                            else self.owner_pointer_override)
        defaulted._obj.value = self.owner_defaulted
        return 1

    def call_GetSecurityDescriptorGroup(self, descriptor, group, defaulted):
        record = self._record_for_pointer(descriptor)
        group._obj.value = (record["group"] if self.group_pointer_override is None
                            else self.group_pointer_override)
        defaulted._obj.value = self.group_defaulted
        return 1

    def call_GetSecurityDescriptorDacl(self, descriptor, present, dacl, defaulted):
        record = self._record_for_pointer(descriptor)
        present._obj.value = self.dacl_present
        dacl._obj.value = (
            record["dacl"] if self.dacl_pointer_override is None
            else self.dacl_pointer_override
        ) if self.dacl_present else None
        defaulted._obj.value = self.dacl_defaulted
        return 1

    def call_GetSecurityDescriptorControl(self, _descriptor, control, revision):
        control._obj.value = self.control
        revision._obj.value = self.descriptor_revision
        return 1

    def call_IsValidSid(self, _sid):
        return self.valid_sid

    def call_GetLengthSid(self, sid):
        record = self._record_for_pointer(sid)
        if sid.value == record["owner"]:
            return (record["ownerLength"] if self.owner_length_override is None
                    else self.owner_length_override)
        if sid.value == record["group"]:
            return (record["groupLength"] if self.group_length_override is None
                    else self.group_length_override)
        raise AssertionError("unknown fake SID")

    def call_GetAclInformation(self, _dacl, output, output_size, info_class):
        if info_class == 2:
            self.assert_size(output._obj, output_size)
            output._obj.AceCount = self.ace_count
            output._obj.AclBytesInUse = self.acl_bytes_in_use
            output._obj.AclBytesFree = self.acl_bytes_free
            return 1
        if info_class == 1:
            self.assert_size(output._obj, output_size)
            output._obj.AclRevision = self.acl_revision
            return 1
        raise AssertionError("unexpected ACL information class")


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
        self.assertEqual(len(expected), 29)
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
        self.assertEqual(ctypes.sizeof(module.ACL_REVISION_INFORMATION), 4)
        self.assertEqual(ctypes.sizeof(module.ACL), 8)
        self.assertEqual(module.ACL.AclRevision.offset, 0)
        self.assertEqual(module.ACL.AclSize.offset, 2)
        self.assertEqual(module.ACL.AceCount.offset, 4)
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
        self.assertEqual(module.SECURITY_DESCRIPTOR_REVISION, 1)
        self.assertEqual(module.SE_SELF_RELATIVE, 0x8000)
        self.assertEqual(module.SecurityImpersonation, 2)
        self.assertEqual(module.TokenImpersonation, 2)


class CheckoutWindowsAclNativeBoundaryTests(unittest.TestCase):
    def assert_refused(self, module, callback):
        with self.assertRaisesRegex(module.WindowsAclRefused,
                                    "^acl_attestation$"):
            callback()

    @staticmethod
    def call_names(harness):
        return [name for name, _args in harness.calls]

    def opened(self, module, harness):
        return module._open_directory(harness.bundle(), harness.path)

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

    def test_two_exact_snapshots_copy_values_hash_used_bytes_and_free_bases(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        opened = self.opened(module, harness)
        with opened:
            snapshot = opened._security_snapshot()
            values = snapshot.values()
            expected_dacl = bytes.fromhex("0200100001000000") + b"AAAA"
            self.assertEqual(dict(values), {
                "ownerSidBytes": harness.owner_sid,
                "groupSidBytes": harness.group_sid,
                "daclDigest": hashlib.sha256(expected_dacl).hexdigest(),
                "control": 0x9004,
                "ownerDefaulted": False,
                "groupDefaulted": False,
                "daclDefaulted": False,
                "descriptorRevision": 1,
                "daclRevision": 2,
                "aceCount": 1,
                "daclBytesInUse": 12,
                "daclSize": 16,
            })
            with self.assertRaises(TypeError):
                values["control"] = 0
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$"):
                snapshot.extra = object()

        self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 2)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)
        security_calls = [args for name, args in harness.calls
                          if name == "GetSecurityInfo"]
        self.assertEqual([(args[1], args[2], args[6]) for args in security_calls],
                         [(1, 7, None), (1, 7, None)])
        acl_calls = [args for name, args in harness.calls
                     if name == "GetAclInformation"]
        self.assertEqual([(args[2], args[3]) for args in acl_calls],
                         [(12, 2), (4, 1)] * 2)
        security_names = {
            "GetSecurityInfo", "IsValidSecurityDescriptor",
            "GetSecurityDescriptorLength", "GetSecurityDescriptorOwner",
            "GetSecurityDescriptorGroup", "GetSecurityDescriptorDacl",
            "GetSecurityDescriptorControl", "IsValidSid", "GetLengthSid",
            "IsValidAcl", "GetAclInformation", "LocalFree",
        }
        one_snapshot_order = [
            "GetSecurityInfo", "IsValidSecurityDescriptor",
            "GetSecurityDescriptorLength", "GetSecurityDescriptorOwner",
            "GetSecurityDescriptorGroup", "GetSecurityDescriptorDacl",
            "GetSecurityDescriptorControl", "IsValidSid", "GetLengthSid",
            "IsValidSid", "GetLengthSid", "IsValidAcl", "GetAclInformation",
            "GetAclInformation", "LocalFree",
        ]
        self.assertEqual(
            [name for name in self.call_names(harness) if name in security_names],
            one_snapshot_order * 2,
        )
        freed = [args[0].value for name, args in harness.calls
                 if name == "LocalFree"]
        bases = list(harness.security_records)
        self.assertEqual(freed, bases)
        interiors = {
            record[key] for record in harness.security_records.values()
            for key in ("owner", "group", "dacl")
        }
        self.assertFalse(interiors.intersection(freed))
        forbidden = {"GetAce", "OpenProcessToken", "GetTokenInformation",
                     "DuplicateTokenEx", "AccessCheck",
                     "ConvertSidToStringSidW"}
        self.assertFalse(forbidden.intersection(self.call_names(harness)))

    def test_defaulted_values_must_agree_with_control_and_are_captured(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        harness.owner_defaulted = 1
        harness.group_defaulted = 1
        harness.dacl_defaulted = 1
        harness.control |= (module.SE_OWNER_DEFAULTED | module.SE_GROUP_DEFAULTED
                            | module.SE_DACL_DEFAULTED)
        opened = self.opened(module, harness)
        with opened:
            values = opened._security_snapshot().values()
            self.assertTrue(values["ownerDefaulted"])
            self.assertTrue(values["groupDefaulted"])
            self.assertTrue(values["daclDefaulted"])

        harness = NativeDirectoryHarness(module)
        harness.dacl_defaulted = 1
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)

    def test_descriptor_pointer_presence_control_and_revision_fail_closed(self):
        module = load_module()
        cases = (
            ("invalid", lambda h: setattr(h, "valid_descriptor", 0)),
            ("short", lambda h: setattr(h, "descriptor_length", 19)),
            ("long", lambda h: setattr(h, "descriptor_length", 65536)),
            ("owner_out", lambda h: setattr(h, "owner_pointer_override", 1)),
            ("group_out", lambda h: setattr(h, "group_pointer_override", 1)),
            ("dacl_out", lambda h: setattr(h, "dacl_pointer_override", 1)),
            ("owner_span", lambda h: setattr(h, "owner_offset", 124)),
            ("group_span", lambda h: setattr(h, "group_offset", 124)),
            ("dacl_header_span", lambda h: setattr(h, "dacl_offset", 124)),
            ("dacl_null", lambda h: setattr(h, "dacl_pointer_override", 0)),
            ("dacl_missing", lambda h: setattr(h, "dacl_present", 0)),
            ("revision", lambda h: setattr(h, "descriptor_revision", 2)),
            ("self_relative", lambda h: setattr(
                h, "control", h.control & ~module.SE_SELF_RELATIVE,
            )),
            ("protected", lambda h: setattr(
                h, "control", h.control & ~module.SE_DACL_PROTECTED,
            )),
            ("auto_inherited", lambda h: setattr(
                h, "control", h.control | module.SE_DACL_AUTO_INHERITED,
            )),
            ("bad_bool", lambda h: setattr(h, "dacl_defaulted", 2)),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("LocalFree"), 1)
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_sid_and_acl_bounds_header_revision_and_free_math_fail_closed(self):
        module = load_module()
        cases = (
            ("sid_invalid", lambda h: setattr(h, "valid_sid", 0)),
            ("sid_short", lambda h: setattr(h, "owner_length_override", 7)),
            ("sid_long", lambda h: setattr(h, "group_length_override", 69)),
            ("acl_revision", lambda h: setattr(h, "acl_revision", 1)),
            ("header_revision", lambda h: setattr(h, "header_acl_revision", 3)),
            ("malformed_acl", lambda h: setattr(h, "valid_acl", 0)),
            ("sbz1", lambda h: setattr(h, "header_sbz1", 1)),
            ("sbz2", lambda h: setattr(h, "header_sbz2", 1)),
            ("used_short", lambda h: setattr(h, "acl_bytes_in_use", 7)),
            ("used_over_size", lambda h: setattr(h, "acl_bytes_in_use", 17)),
            ("free_math", lambda h: setattr(h, "acl_bytes_free", 5)),
            ("header_size", lambda h: setattr(h, "header_acl_size", 17)),
            ("ace_count", lambda h: setattr(h, "header_ace_count", 2)),
            ("ace_capacity", lambda h: (
                setattr(h, "ace_count", 2), setattr(h, "header_ace_count", 2),
            )),
            ("span", lambda h: setattr(h, "header_acl_size", 80)),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("LocalFree"), 1)
            if name == "span":
                self.assertNotIn("IsValidAcl", self.call_names(harness))
                self.assertNotIn("GetAclInformation", self.call_names(harness))

    def test_get_security_error_with_buffer_and_ordinary_errors_clean_up(self):
        module = load_module()
        for status in (5, False, True):
            harness = NativeDirectoryHarness(module)
            harness.security_statuses = [status]
            opened = self.opened(module, harness)
            with self.subTest(status=status), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("LocalFree"), 1)

        harness = NativeDirectoryHarness(module)
        harness.security_statuses = [5]
        harness.allocate_descriptor = False
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 0)

        harness = NativeDirectoryHarness(module)
        harness.security_statuses = [0]
        harness.allocate_descriptor = False
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 0)

        harness = NativeDirectoryHarness(module)

        def private_error(*_args):
            raise RuntimeError("PRIVATE SECURITY DETAIL")

        harness.dlls["advapi32.dll"].functions[
            "GetSecurityDescriptorOwner"
        ].implementation = private_error
        opened = self.opened(module, harness)
        with opened:
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$") as raised:
                opened._security_snapshot()
            self.assertNotIn("PRIVATE", str(raised.exception))
        self.assertEqual(self.call_names(harness).count("LocalFree"), 1)

    def test_descriptor_getter_disagreement_and_acl_query_failure_refuse(self):
        module = load_module()
        for function_name in (
            "GetSecurityDescriptorOwner", "GetSecurityDescriptorGroup",
            "GetSecurityDescriptorDacl",
        ):
            harness = NativeDirectoryHarness(module)
            original = harness.dlls["advapi32.dll"].functions[
                function_name
            ].implementation

            def disagree(*args, original=original, function_name=function_name):
                result = original(*args)
                pointer_index = 1 if function_name != "GetSecurityDescriptorDacl" else 2
                args[pointer_index]._obj.value += 1
                return result

            harness.dlls["advapi32.dll"].functions[
                function_name
            ].implementation = disagree
            opened = self.opened(module, harness)
            with self.subTest(function=function_name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("LocalFree"), 1)

        harness = NativeDirectoryHarness(module)
        harness.dlls["advapi32.dll"].functions[
            "GetAclInformation"
        ].implementation = lambda *_args: 0
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 1)

    def test_local_free_requires_none_is_one_shot_and_preserves_primary(self):
        module = load_module()
        for failure in (0, RuntimeError("PRIVATE FREE DETAIL")):
            harness = NativeDirectoryHarness(module)
            if isinstance(failure, BaseException):
                harness.local_free_error = failure
            else:
                harness.local_free_result = failure
            opened = self.opened(module, harness)
            with self.subTest(failure=type(failure).__name__), opened:
                with self.assertRaisesRegex(module.WindowsAclRefused,
                                            "^acl_attestation$") as raised:
                    opened._security_snapshot()
                self.assertNotIn("PRIVATE", str(raised.exception))
            self.assertEqual(self.call_names(harness).count("LocalFree"), 1)

        harness = NativeDirectoryHarness(module)
        free_exit = SystemExit(6)
        harness.local_free_error = free_exit
        opened = self.opened(module, harness)
        with self.assertRaises(SystemExit) as raised:
            with opened:
                opened._security_snapshot()
        self.assertIs(raised.exception, free_exit)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 1)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

        harness = NativeDirectoryHarness(module)
        primary = KeyboardInterrupt()
        original = harness.dlls["advapi32.dll"].functions[
            "GetSecurityInfo"
        ].implementation

        def primary_after_allocation(*args):
            original(*args)
            raise primary

        harness.dlls["advapi32.dll"].functions[
            "GetSecurityInfo"
        ].implementation = primary_after_allocation
        harness.local_free_error = SystemExit(7)
        opened = self.opened(module, harness)
        with self.assertRaises(KeyboardInterrupt) as raised:
            with opened:
                opened._security_snapshot()
        self.assertIs(raised.exception, primary)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 1)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 1)

    def test_second_security_snapshot_and_post_handle_drift_are_rejected(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)

        def mutate_second(index, raw):
            if index == 1:
                raw[72] = ord("Z")

        harness.security_mutator = mutate_second
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 2)

        harness = NativeDirectoryHarness(module)
        harness.mutate_identity_after = 5
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 2)

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
