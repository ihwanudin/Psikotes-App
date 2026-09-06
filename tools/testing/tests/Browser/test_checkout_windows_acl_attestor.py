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
        self.process_handle = ctypes.c_void_p(-1).value
        self.token_handle = 456
        self.open_token_result = 1
        self.open_token_output = True
        self.token_handle_flags = 0
        self.token_set_result = 1
        self.token_get_result = 1
        self.token_probe_result = 0
        self.token_probe_error = 122
        self.token_fill_result = 1
        self.token_required_override = None
        self.token_returned_override = None
        self.token_sid_offset_override = None
        self.token_sid_length_override = None
        self.token_sid_mutate_during_validation = False
        self.token_sids = None
        self.token_fill_count = 0
        self.token_groups = [
            (bytes.fromhex("010100000000000512000000"), 0),
            (bytes.fromhex("010100000000000513000000"), 0x10),
        ]
        self.token_group_sets = None
        self.token_group_fill_count = 0
        self.token_group_probe_result = 0
        self.token_group_probe_error = 122
        self.token_group_fill_result = 1
        self.token_group_required_override = None
        self.token_group_returned_override = None
        self.token_group_count_override = None
        self.token_group_pointer_overrides = {}
        self.token_privileges = [
            (0xFFFFFFFF, -0x80000000, 0xFFFFFFFD),
            (2, 0x7FFFFFFF, 0x2),
        ]
        self.token_privilege_sets = None
        self.token_privilege_fill_count = 0
        self.token_privilege_probe_result = 0
        self.token_privilege_probe_error = 122
        self.token_privilege_fill_result = 1
        self.token_privilege_required_override = None
        self.token_privilege_returned_override = None
        self.token_privilege_count_override = None
        self.sensitive_luids = {
            "SeBackupPrivilege": (0xFFFFFFFF, -0x80000000),
            "SeRestorePrivilege": (2, 0x7FFFFFFF),
            "SeTakeOwnershipPrivilege": (3, 0),
        }
        self.sensitive_luid_sets = None
        self.lookup_privilege_result = 1
        self.lookup_privilege_call_count = 0
        self.token_type_values = [1, 1]
        self.token_type_call_count = 0
        self.token_app_container_values = [0xFFFFFFFF, 0xFFFFFFFF]
        self.token_app_container_call_count = 0
        self.token_fixed_results = {8: 1, 29: 1}
        self.token_fixed_returned = {8: 4, 29: 4}
        self.token_restricting_sids = []
        self.token_restricting_sid_sets = None
        self.token_restricted_fill_count = 0
        self.token_restricted_probe_result = 0
        self.token_restricted_probe_error = 122
        self.token_restricted_fill_result = 1
        self.token_restricted_required_override = None
        self.token_restricted_returned_override = None
        self.token_restricted_count_override = None
        self.token_restricted_pointer_overrides = {}
        self.is_token_restricted_results = None
        self.is_token_restricted_error = None
        self.is_token_restricted_call_count = 0
        self.token_sid_addresses = {}
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
        self.token_valid_sid = 1
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
        self.acl_bytes_in_use = 28
        self.acl_bytes_free = 4
        self.acl_size = 32
        self.header_acl_size = 32
        self.ace_count = 1
        self.header_ace_count = 1
        self.ace_type = 0
        self.ace_flags = 3
        self.ace_size = 20
        self.access_mask = 2032127
        self.trustee_length_override = None
        self.get_ace_result = 1
        self.get_ace_null = False
        self.ace_pointer_delta = 8
        self.owner_pointer_override = None
        self.group_pointer_override = None
        self.dacl_pointer_override = None
        self.owner_length_override = None
        self.group_length_override = None
        self.owner_sid = bytes.fromhex("010100000000000520000000")
        self.group_sid = bytes.fromhex("010100000000000512000000")
        self.trustee_sid = self.owner_sid
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

    def call_GetCurrentProcess(self):
        return self.process_handle

    def call_OpenProcessToken(self, process, access, output):
        if process != self.process_handle or access != 8:
            raise AssertionError("wrong process token request")
        if self.open_token_output:
            output._obj.value = self.token_handle
        return self.open_token_result

    def call_SetHandleInformation(self, handle, _mask, _flags):
        return self.token_set_result if handle == self.token_handle else 1

    def call_GetHandleInformation(self, handle, flags):
        flags._obj.value = (self.token_handle_flags
                            if handle == self.token_handle else self.handle_flags)
        return self.token_get_result if handle == self.token_handle else 1

    def call_GetTokenInformation(self, handle, info_class, output, capacity,
                                 returned):
        if handle != self.token_handle:
            raise AssertionError("wrong token handle")
        if info_class == 2:
            return self._call_token_groups(output, capacity, returned)
        if info_class == 3:
            return self._call_token_privileges(output, capacity, returned)
        if info_class in {8, 29}:
            return self._call_token_fixed(info_class, output, capacity, returned)
        if info_class == 11:
            return self._call_token_restricted_sids(output, capacity, returned)
        if info_class != 1:
            raise AssertionError("wrong TokenUser request")
        sid_values = self.token_sids or [self.owner_sid, self.owner_sid]
        sid = sid_values[min(self.token_fill_count, len(sid_values) - 1)]
        required = (ctypes.sizeof(self.module.TOKEN_USER) + len(sid)
                    if self.token_required_override is None
                    else self.token_required_override)
        returned._obj.value = required
        if output is None:
            if capacity != 0:
                raise AssertionError("TokenUser probe capacity must be zero")
            self.module.ctypes.set_last_error(self.token_probe_error)
            return self.token_probe_result
        if capacity != required:
            raise AssertionError("TokenUser fill capacity mismatch")
        base = ctypes.addressof(output)
        sid_offset = (ctypes.sizeof(self.module.TOKEN_USER)
                      if self.token_sid_offset_override is None
                      else self.token_sid_offset_override)
        user = self.module.TOKEN_USER.from_buffer(output)
        user.User.Sid = base + sid_offset
        user.User.Attributes = 0
        end = min(capacity, sid_offset + len(sid))
        if sid_offset < capacity and end > sid_offset:
            ctypes.memmove(base + sid_offset, sid, end - sid_offset)
        self.token_sid_addresses[base + sid_offset] = sid
        returned._obj.value = (required if self.token_returned_override is None
                               else self.token_returned_override)
        self.token_fill_count += 1
        return self.token_fill_result

    def _call_token_groups(self, output, capacity, returned):
        group_sets = self.token_group_sets or [self.token_groups, self.token_groups]
        groups = group_sets[min(self.token_group_fill_count,
                                len(group_sets) - 1)]
        table_end = (self.module.TOKEN_GROUPS.Groups.offset
                     + len(groups) * ctypes.sizeof(self.module.SID_AND_ATTRIBUTES))
        required = table_end + sum(len(sid) for sid, _attributes in groups)
        if self.token_group_required_override is not None:
            required = self.token_group_required_override
        returned._obj.value = required
        if output is None:
            if capacity != 0:
                raise AssertionError("TokenGroups probe capacity must be zero")
            self.module.ctypes.set_last_error(self.token_group_probe_error)
            return self.token_group_probe_result
        if capacity != required:
            raise AssertionError("TokenGroups fill capacity mismatch")
        base = ctypes.addressof(output)
        ctypes.c_uint32.from_address(base).value = (
            len(groups) if self.token_group_count_override is None
            else self.token_group_count_override
        )
        sid_offset = table_end
        for index, (sid, attributes) in enumerate(groups):
            entry_address = (base + self.module.TOKEN_GROUPS.Groups.offset
                             + index * ctypes.sizeof(
                                 self.module.SID_AND_ATTRIBUTES))
            entry = self.module.SID_AND_ATTRIBUTES.from_address(entry_address)
            pointer_offset = self.token_group_pointer_overrides.get(index, sid_offset)
            entry.Sid = base + pointer_offset
            entry.Attributes = attributes
            end = min(capacity, pointer_offset + len(sid))
            if pointer_offset >= table_end and pointer_offset < capacity \
                    and end > pointer_offset:
                ctypes.memmove(base + pointer_offset, sid, end - pointer_offset)
            self.token_sid_addresses[base + pointer_offset] = sid
            sid_offset += len(sid)
        returned._obj.value = (
            required if self.token_group_returned_override is None
            else self.token_group_returned_override
        )
        self.token_group_fill_count += 1
        return self.token_group_fill_result

    def _call_token_privileges(self, output, capacity, returned):
        privilege_sets = (self.token_privilege_sets
                          or [self.token_privileges, self.token_privileges])
        privileges = privilege_sets[min(self.token_privilege_fill_count,
                                        len(privilege_sets) - 1)]
        required = (self.module.TOKEN_PRIVILEGES.Privileges.offset
                    + len(privileges) * ctypes.sizeof(
                        self.module.LUID_AND_ATTRIBUTES))
        if self.token_privilege_required_override is not None:
            required = self.token_privilege_required_override
        returned._obj.value = required
        if output is None:
            if capacity != 0:
                raise AssertionError("TokenPrivileges probe capacity must be zero")
            self.module.ctypes.set_last_error(self.token_privilege_probe_error)
            return self.token_privilege_probe_result
        if capacity != required:
            raise AssertionError("TokenPrivileges fill capacity mismatch")
        base = ctypes.addressof(output)
        ctypes.c_uint32.from_address(base).value = (
            len(privileges) if self.token_privilege_count_override is None
            else self.token_privilege_count_override
        )
        for index, (low, high, attributes) in enumerate(privileges):
            entry = self.module.LUID_AND_ATTRIBUTES.from_address(
                base + self.module.TOKEN_PRIVILEGES.Privileges.offset
                + index * ctypes.sizeof(self.module.LUID_AND_ATTRIBUTES)
            )
            entry.Luid.LowPart = low
            entry.Luid.HighPart = high
            entry.Attributes = attributes
        returned._obj.value = (
            required if self.token_privilege_returned_override is None
            else self.token_privilege_returned_override
        )
        self.token_privilege_fill_count += 1
        return self.token_privilege_fill_result

    def call_LookupPrivilegeValueW(self, system, name, output):
        if system is not None or name not in self.sensitive_luids:
            raise AssertionError("wrong local privilege lookup")
        mappings = self.sensitive_luid_sets or [
            self.sensitive_luids, self.sensitive_luids,
        ]
        mapping = mappings[min(self.lookup_privilege_call_count // 3,
                               len(mappings) - 1)]
        low, high = mapping[name]
        output._obj.LowPart = low
        output._obj.HighPart = high
        self.lookup_privilege_call_count += 1
        return self.lookup_privilege_result

    def _call_token_fixed(self, info_class, output, capacity, returned):
        if output is None or capacity != 4:
            raise AssertionError("fixed token query must use a DWORD buffer")
        if info_class == 8:
            values = self.token_type_values
            index = min(self.token_type_call_count, len(values) - 1)
            self.token_type_call_count += 1
        else:
            values = self.token_app_container_values
            index = min(self.token_app_container_call_count, len(values) - 1)
            self.token_app_container_call_count += 1
        output._obj.value = values[index]
        returned._obj.value = self.token_fixed_returned[info_class]
        return self.token_fixed_results[info_class]

    def _call_token_restricted_sids(self, output, capacity, returned):
        sid_sets = (self.token_restricting_sid_sets
                    if self.token_restricting_sid_sets is not None
                    else [self.token_restricting_sids,
                          self.token_restricting_sids])
        records = sid_sets[min(self.token_restricted_fill_count,
                               len(sid_sets) - 1)]
        if records:
            table_end = (self.module.TOKEN_GROUPS.Groups.offset
                         + len(records) * ctypes.sizeof(
                             self.module.SID_AND_ATTRIBUTES))
            required = table_end + sum(len(sid) for sid, _attrs in records)
        else:
            table_end = 4
            required = 4
        if self.token_restricted_required_override is not None:
            required = self.token_restricted_required_override
        returned._obj.value = required
        if output is None:
            if capacity != 0:
                raise AssertionError("TokenRestrictedSids probe must be zero")
            self.module.ctypes.set_last_error(self.token_restricted_probe_error)
            return self.token_restricted_probe_result
        if capacity != required:
            raise AssertionError("TokenRestrictedSids fill capacity mismatch")
        base = ctypes.addressof(output)
        ctypes.c_uint32.from_address(base).value = (
            len(records) if self.token_restricted_count_override is None
            else self.token_restricted_count_override
        )
        sid_offset = table_end
        for index, (sid, attributes) in enumerate(records):
            entry = self.module.SID_AND_ATTRIBUTES.from_address(
                base + self.module.TOKEN_GROUPS.Groups.offset
                + index * ctypes.sizeof(self.module.SID_AND_ATTRIBUTES)
            )
            pointer_offset = self.token_restricted_pointer_overrides.get(
                index, sid_offset,
            )
            entry.Sid = base + pointer_offset
            entry.Attributes = attributes
            end = min(capacity, pointer_offset + len(sid))
            if pointer_offset >= table_end and pointer_offset < capacity \
                    and end > pointer_offset:
                ctypes.memmove(base + pointer_offset, sid, end - pointer_offset)
            self.token_sid_addresses[base + pointer_offset] = sid
            sid_offset += len(sid)
        returned._obj.value = (
            required if self.token_restricted_returned_override is None
            else self.token_restricted_returned_override
        )
        self.token_restricted_fill_count += 1
        return self.token_restricted_fill_result

    def call_IsTokenRestricted(self, handle):
        if handle != self.token_handle:
            raise AssertionError("wrong token handle")
        sid_sets = (self.token_restricting_sid_sets
                    if self.token_restricting_sid_sets is not None
                    else [self.token_restricting_sids,
                          self.token_restricting_sids])
        index = min(max(self.token_restricted_fill_count - 1, 0),
                    len(sid_sets) - 1)
        results = self.is_token_restricted_results
        result = (1 if sid_sets[index] else 0) if results is None else results[
            min(self.is_token_restricted_call_count, len(results) - 1)
        ]
        self.is_token_restricted_call_count += 1
        if self.is_token_restricted_error is not None:
            self.module.ctypes.set_last_error(self.is_token_restricted_error)
        return result

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
        ace_offset = dacl_offset + 8
        ace_header = self.module.ACE_HEADER()
        ace_header.AceType = self.ace_type
        ace_header.AceFlags = self.ace_flags
        ace_header.AceSize = self.ace_size
        raw[ace_offset:ace_offset + 4] = bytes(ace_header)
        raw[ace_offset + 4:ace_offset + 8] = int(self.access_mask).to_bytes(
            4, "little", signed=False,
        )
        trustee_end = min(len(raw), ace_offset + 8 + len(self.trustee_sid))
        raw[ace_offset + 8:trustee_end] = self.trustee_sid[:trustee_end - ace_offset - 8]
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
            "ace": base + ace_offset,
            "trustee": base + ace_offset + 8,
            "ownerLength": len(self.owner_sid),
            "groupLength": len(self.group_sid),
            "trusteeLength": len(self.trustee_sid),
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
        if _sid.value in self.token_sid_addresses:
            if self.token_sid_mutate_during_validation:
                length = len(self.token_sid_addresses[_sid.value])
                ctypes.c_ubyte.from_address(_sid.value + length - 1).value ^= 1
            return self.token_valid_sid
        return self.valid_sid

    def call_GetLengthSid(self, sid):
        if sid.value in self.token_sid_addresses:
            token_sid = self.token_sid_addresses[sid.value]
            return (len(token_sid) if self.token_sid_length_override is None
                    else self.token_sid_length_override)
        record = self._record_for_pointer(sid)
        if sid.value == record["owner"]:
            return (record["ownerLength"] if self.owner_length_override is None
                    else self.owner_length_override)
        if sid.value == record["group"]:
            return (record["groupLength"] if self.group_length_override is None
                    else self.group_length_override)
        if sid.value == record["trustee"]:
            return (record["trusteeLength"]
                    if self.trustee_length_override is None
                    else self.trustee_length_override)
        raise AssertionError("unknown fake SID")

    def call_GetAce(self, dacl, ace_index, output):
        if ace_index != 0:
            raise AssertionError("wrong ACE index")
        record = self._record_for_pointer(dacl)
        if self.get_ace_result and not self.get_ace_null:
            output._obj.value = record["dacl"] + self.ace_pointer_delta
        return self.get_ace_result

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
        self.assertEqual(ctypes.sizeof(ctypes.c_int), 4)
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
        self.assertEqual(module.LUID.LowPart.offset, 0)
        self.assertEqual(module.LUID.HighPart.offset, 4)
        self.assertEqual(ctypes.sizeof(module.LUID_AND_ATTRIBUTES), 12)
        self.assertEqual(module.LUID_AND_ATTRIBUTES.Luid.offset, 0)
        self.assertEqual(module.LUID_AND_ATTRIBUTES.Attributes.offset, 8)
        self.assertEqual(module.TOKEN_PRIVILEGES.PrivilegeCount.offset, 0)
        self.assertEqual(module.TOKEN_PRIVILEGES.Privileges.offset, 4)
        pointer_size = ctypes.sizeof(ctypes.c_void_p)
        self.assertEqual(ctypes.sizeof(module.SID_AND_ATTRIBUTES),
                         16 if pointer_size == 8 else 8)
        self.assertEqual(module.SID_AND_ATTRIBUTES.Sid.offset, 0)
        self.assertEqual(module.SID_AND_ATTRIBUTES.Attributes.offset,
                         8 if pointer_size == 8 else 4)
        self.assertEqual(module.TOKEN_GROUPS.GroupCount.offset, 0)
        self.assertEqual(module.TOKEN_GROUPS.Groups.offset,
                         8 if pointer_size == 8 else 4)
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
        self.assertEqual(module.TokenGroups, 2)
        self.assertEqual(module.TokenPrivileges, 3)
        self.assertEqual(module.TokenType, 8)
        self.assertEqual(module.TokenIsAppContainer, 29)
        self.assertEqual(module.TokenPrimary, 1)
        self.assertEqual(module.MAX_TOKEN_GROUPS_BYTES, 262144)
        self.assertEqual(module.MAX_TOKEN_GROUP_COUNT, 4096)
        self.assertEqual(module.MAX_TOKEN_PRIVILEGES_BYTES, 262144)
        self.assertEqual(module.MAX_TOKEN_PRIVILEGE_COUNT, 4096)
        self.assertEqual(module.SENSITIVE_PRIVILEGE_NAMES, (
            "SeBackupPrivilege",
            "SeRestorePrivilege",
            "SeTakeOwnershipPrivilege",
        ))


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
            expected_dacl = (bytes.fromhex("0200200001000000")
                             + bytes.fromhex("00031400ff011f00")
                             + harness.owner_sid)
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
                "daclBytesInUse": 28,
                "daclSize": 32,
                "ownerSid": "S-1-5-32",
                "trusteeSid": "S-1-5-32",
                "aceType": 0,
                "aceFlags": 3,
                "accessMask": 2032127,
                "aceSize": 20,
                "processTokenSidBytes": harness.owner_sid,
                "processTokenSid": "S-1-5-32",
                "processTokenGroups": (
                    (harness.token_groups[0][0], "S-1-5-18", 0),
                    (harness.token_groups[1][0], "S-1-5-19", 0x10),
                ),
                "processTokenPrivileges": (
                    (0xFFFFFFFF, -0x80000000, 0xFFFFFFFD),
                    (2, 0x7FFFFFFF, 0x2),
                ),
                "processTokenSensitivePrivileges": (
                    ("SeBackupPrivilege", 0xFFFFFFFF, -0x80000000,
                     True, False),
                    ("SeRestorePrivilege", 2, 0x7FFFFFFF, True, True),
                    ("SeTakeOwnershipPrivilege", 3, 0, False, False),
                ),
                "processTokenType": 1,
                "processTokenIsAppContainerRaw": 0xFFFFFFFF,
                "processTokenIsAppContainer": True,
                "processTokenRestrictingSids": (),
                "processTokenHasRestrictingSids": False,
                "processTokenRestrictedSidsReturnedLength": 4,
            })
            with self.assertRaises(TypeError):
                values["control"] = 0
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$"):
                snapshot.extra = object()

        self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 2)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)
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
            "IsValidAcl", "GetAclInformation", "GetAce", "LocalFree",
        }
        one_snapshot_order = [
            "GetSecurityInfo", "IsValidSecurityDescriptor",
            "GetSecurityDescriptorLength", "GetSecurityDescriptorOwner",
            "GetSecurityDescriptorGroup", "GetSecurityDescriptorDacl",
            "GetSecurityDescriptorControl", "IsValidSid", "GetLengthSid",
            "IsValidSid", "GetLengthSid", "IsValidAcl", "GetAclInformation",
            "GetAclInformation", "GetAce", "IsValidSid", "GetLengthSid",
            "LocalFree",
        ]
        names = self.call_names(harness)
        descriptor_start = names.index("GetSecurityInfo")
        descriptor_end = max(index for index, name in enumerate(names)
                             if name == "LocalFree") + 1
        self.assertEqual(
            [name for name in names[descriptor_start:descriptor_end]
             if name in security_names],
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
        forbidden = {"DuplicateTokenEx", "AccessCheck",
                     "ConvertSidToStringSidW", "LookupPrivilegeNameW"}
        self.assertFalse(forbidden.intersection(self.call_names(harness)))
        self.assertEqual(self.call_names(harness).count("IsTokenRestricted"), 2)

        token_information = [args for name, args in harness.calls
                             if name == "GetTokenInformation"]
        required = ctypes.sizeof(module.TOKEN_USER) + len(harness.owner_sid)
        group_required = (
            module.TOKEN_GROUPS.Groups.offset
            + len(harness.token_groups) * ctypes.sizeof(module.SID_AND_ATTRIBUTES)
            + sum(len(sid) for sid, _attributes in harness.token_groups)
        )
        privilege_required = (
            module.TOKEN_PRIVILEGES.Privileges.offset
            + len(harness.token_privileges)
            * ctypes.sizeof(module.LUID_AND_ATTRIBUTES)
        )
        self.assertEqual(len(token_information), 20)
        self.assertEqual(
            [(args[1], args[2] is None, args[3]) for args in token_information],
            [(1, True, 0), (1, False, required),
             (2, True, 0), (2, False, group_required),
             (3, True, 0), (3, False, privilege_required),
             (8, False, 4), (29, False, 4),
             (11, True, 0), (11, False, 4)] * 2,
        )
        lookup_calls = [args for name, args in harness.calls
                        if name == "LookupPrivilegeValueW"]
        self.assertEqual(
            [(args[0], args[1]) for args in lookup_calls],
            [(None, name) for name in (
                "SeBackupPrivilege", "SeRestorePrivilege",
                "SeTakeOwnershipPrivilege",
            )] * 2,
        )
        names = self.call_names(harness)
        token_positions = [index for index, name in enumerate(names)
                           if name == "GetTokenInformation"]
        descriptor_positions = [index for index, name in enumerate(names)
                                if name == "GetSecurityInfo"]
        self.assertLess(token_positions[9], descriptor_positions[0])
        self.assertLess(descriptor_positions[1], token_positions[10])
        self.assertNotIn(9, (args[1] for args in token_information))
        open_token = [args for name, args in harness.calls
                      if name == "OpenProcessToken"]
        self.assertEqual([(args[0], args[1]) for args in open_token],
                         [(harness.process_handle, 8)])
        token_set = [args for name, args in harness.calls
                     if name == "SetHandleInformation"
                     and args[0] == harness.token_handle]
        self.assertEqual(token_set, [(harness.token_handle, 1, 0)])
        closed = [args[0] for name, args in harness.calls
                  if name == "CloseHandle"]
        self.assertEqual(closed, [harness.token_handle, harness.handle])
        self.assertNotIn(harness.process_handle, closed)

    def test_token_groups_snapshot_is_complete_ordered_and_immutable(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        with module._open_current_process_token(harness.bundle()) as token:
            snapshot = token._groups_snapshot()
            self.assertEqual(snapshot.values(), (
                (harness.token_groups[0][0], "S-1-5-18", 0),
                (harness.token_groups[1][0], "S-1-5-19", 0x10),
            ))
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$"):
                snapshot.extra = object()
        calls = [args for name, args in harness.calls
                 if name == "GetTokenInformation"]
        required = (
            module.TOKEN_GROUPS.Groups.offset
            + 2 * ctypes.sizeof(module.SID_AND_ATTRIBUTES)
            + sum(len(sid) for sid, _attributes in harness.token_groups)
        )
        self.assertEqual(
            [(args[1], args[2] is None, args[3]) for args in calls],
            [(2, True, 0), (2, False, required)],
        )
        group_sid_calls = [name for name, _args in harness.calls
                           if name in {"GetLengthSid", "IsValidSid"}]
        self.assertEqual(
            group_sid_calls,
            ["IsValidSid", "GetLengthSid"] * len(harness.token_groups),
        )
        self.assertEqual([args[0] for name, args in harness.calls
                          if name == "CloseHandle"], [harness.token_handle])

    def test_token_groups_bounds_duplicates_and_native_sid_order_fail_closed(self):
        module = load_module()
        table_end = (module.TOKEN_GROUPS.Groups.offset
                     + 2 * ctypes.sizeof(module.SID_AND_ATTRIBUTES))

        def partial_overlap(harness):
            interior_sid = bytes.fromhex("010100000000000513000000")
            long_sid = (bytes.fromhex("0104000000000005")
                        + interior_sid + b"\x04\x00\x00\x00")
            harness.token_groups = [(long_sid, 0), (interior_sid, 0x10)]
            harness.token_group_pointer_overrides[1] = table_end + 8

        cases = (
            ("probe_true", lambda h: setattr(h, "token_group_probe_result", 1)),
            ("probe_bool", lambda h: setattr(h, "token_group_probe_result", False)),
            ("probe_error", lambda h: setattr(h, "token_group_probe_error", 5)),
            ("required_small", lambda h: setattr(
                h, "token_group_required_override",
                module.TOKEN_GROUPS.Groups.offset - 1,
            )),
            ("required_large", lambda h: setattr(
                h, "token_group_required_override",
                module.MAX_TOKEN_GROUPS_BYTES + 1,
            )),
            ("fill_false", lambda h: setattr(h, "token_group_fill_result", 0)),
            ("fill_bool", lambda h: setattr(h, "token_group_fill_result", True)),
            ("returned", lambda h: setattr(h, "token_group_returned_override", 1)),
            ("count", lambda h: setattr(h, "token_group_count_override", 4097)),
            ("table_span", lambda h: setattr(h, "token_group_count_override", 3)),
            ("sid_overlap", lambda h: h.token_group_pointer_overrides.update(
                {0: table_end - 1},
            )),
            ("sid_tail", lambda h: h.token_group_pointer_overrides.update({0:
                module.TOKEN_GROUPS.Groups.offset
                + 2 * ctypes.sizeof(module.SID_AND_ATTRIBUTES)
                + sum(len(sid) for sid, _attributes in h.token_groups) - 4})),
            ("sid_partial_overlap", partial_overlap),
            ("sid_revision", lambda h: setattr(h, "token_groups", [
                (b"\x02" + h.token_groups[0][0][1:], 0), h.token_groups[1],
            ])),
            ("sid_count", lambda h: setattr(h, "token_groups", [
                (b"\x01\x0f" + h.token_groups[0][0][2:], 0), h.token_groups[1],
            ])),
            ("sid_native", lambda h: setattr(h, "token_valid_sid", 0)),
            ("sid_length", lambda h: setattr(h, "token_sid_length_override", 8)),
            ("sid_live_drift", lambda h: setattr(
                h, "token_sid_mutate_during_validation", True,
            )),
            ("duplicate", lambda h: setattr(h, "token_groups", [
                h.token_groups[0], (h.token_groups[0][0], 0x10),
            ])),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            with self.subTest(case=name), \
                    module._open_current_process_token(harness.bundle()) as token:
                self.assert_refused(module, token._groups_snapshot)
            if name in {"count", "table_span", "sid_overlap", "sid_tail",
                        "sid_revision", "sid_count"}:
                native_sid_calls = [call for call, _args in harness.calls
                                    if call in {"IsValidSid", "GetLengthSid"}]
                self.assertEqual(native_sid_calls, [])
            if name == "sid_native":
                self.assertEqual(
                    [call for call, _args in harness.calls
                     if call in {"IsValidSid", "GetLengthSid"}],
                    ["IsValidSid"],
                )
            if name == "sid_partial_overlap":
                self.assertEqual(
                    [call for call, _args in harness.calls
                     if call in {"IsValidSid", "GetLengthSid"}],
                    ["IsValidSid", "GetLengthSid"],
                )
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle])

    def test_token_group_drift_is_bound_to_process_profile(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        different = bytes.fromhex("010100000000000514000000")
        harness.token_group_sets = [
            harness.token_groups,
            [harness.token_groups[0], (different, 0x10)],
        ]
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_token_groups_redact_ordinary_errors_and_preserve_base_exception(self):
        module = load_module()
        for error in (RuntimeError("PRIVATE TOKEN DETAIL"), KeyboardInterrupt()):
            harness = NativeDirectoryHarness(module)
            bundle = harness.bundle()
            token = module._open_current_process_token(bundle)
            harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation = lambda *_args, error=error: (_ for _ in ()).throw(error)
            with self.subTest(error=type(error).__name__):
                if isinstance(error, Exception):
                    with self.assertRaisesRegex(
                            module.WindowsAclRefused,
                            "^acl_attestation$") as raised:
                        with token:
                            token._groups_snapshot()
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(KeyboardInterrupt) as raised:
                        with token:
                            token._groups_snapshot()
                    self.assertIs(raised.exception, error)
                self.assertEqual([args[0] for call, args in harness.calls
                                  if call == "CloseHandle"],
                                 [harness.token_handle])

    def test_token_groups_base_exception_after_user_preserves_both_cleanups(self):
        module = load_module()
        for primary in (KeyboardInterrupt(), SystemExit(31)):
            harness = NativeDirectoryHarness(module)
            original = harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation

            def interrupt_groups(*args, primary=primary):
                if args[1] == 2:
                    raise primary
                return original(*args)

            harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation = interrupt_groups
            harness.close_error = RuntimeError("PRIVATE CLOSE DETAIL")
            opened = self.opened(module, harness)
            with self.subTest(error=type(primary).__name__), \
                    self.assertRaises(type(primary)) as raised:
                with opened:
                    opened._security_snapshot()
            self.assertIs(raised.exception, primary)
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle, harness.handle])

    def test_token_privileges_snapshot_is_complete_ordered_and_immutable(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        with module._open_current_process_token(harness.bundle()) as token:
            snapshot = token._privileges_snapshot()
            self.assertEqual(snapshot.values(), (
                (0xFFFFFFFF, -0x80000000, 0xFFFFFFFD),
                (2, 0x7FFFFFFF, 0x2),
            ))
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$"):
                snapshot.extra = object()
        calls = [args for name, args in harness.calls
                 if name == "GetTokenInformation"]
        required = (module.TOKEN_PRIVILEGES.Privileges.offset
                    + 2 * ctypes.sizeof(module.LUID_AND_ATTRIBUTES))
        self.assertEqual(
            [(args[1], args[2] is None, args[3]) for args in calls],
            [(3, True, 0), (3, False, required)],
        )
        self.assertEqual([args[0] for name, args in harness.calls
                          if name == "CloseHandle"], [harness.token_handle])

    def test_token_privileges_bounds_duplicates_and_results_fail_closed(self):
        module = load_module()
        cases = (
            ("probe_true", lambda h: setattr(
                h, "token_privilege_probe_result", 1,
            )),
            ("probe_bool", lambda h: setattr(
                h, "token_privilege_probe_result", False,
            )),
            ("probe_error", lambda h: setattr(
                h, "token_privilege_probe_error", 5,
            )),
            ("required_small", lambda h: setattr(
                h, "token_privilege_required_override",
                module.TOKEN_PRIVILEGES.Privileges.offset - 1,
            )),
            ("required_large", lambda h: setattr(
                h, "token_privilege_required_override",
                module.MAX_TOKEN_PRIVILEGES_BYTES + 1,
            )),
            ("trailing", lambda h: setattr(
                h, "token_privilege_required_override",
                module.TOKEN_PRIVILEGES.Privileges.offset
                + 2 * ctypes.sizeof(module.LUID_AND_ATTRIBUTES) + 1,
            )),
            ("fill_false", lambda h: setattr(
                h, "token_privilege_fill_result", 0,
            )),
            ("fill_bool", lambda h: setattr(
                h, "token_privilege_fill_result", True,
            )),
            ("returned", lambda h: setattr(
                h, "token_privilege_returned_override", 1,
            )),
            ("count", lambda h: setattr(
                h, "token_privilege_count_override", 4097,
            )),
            ("table_span", lambda h: setattr(
                h, "token_privilege_count_override", 3,
            )),
            ("duplicate", lambda h: setattr(h, "token_privileges", [
                h.token_privileges[0], (0xFFFFFFFF, -0x80000000, 0x2),
            ])),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            with self.subTest(case=name), \
                    module._open_current_process_token(harness.bundle()) as token:
                self.assert_refused(module, token._privileges_snapshot)
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle])

    def test_token_privilege_drift_is_bound_to_process_profile(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        harness.token_privilege_sets = [
            harness.token_privileges,
            [harness.token_privileges[0], (2, 0x7FFFFFFF, 0)],
        ]
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_token_privileges_errors_preserve_both_cleanups_and_redaction(self):
        module = load_module()
        for primary in (RuntimeError("PRIVATE TOKEN DETAIL"),
                        KeyboardInterrupt(), SystemExit(37)):
            harness = NativeDirectoryHarness(module)
            original = harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation

            def interrupt_privileges(*args, primary=primary):
                if args[1] == 3:
                    raise primary
                return original(*args)

            harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation = interrupt_privileges
            harness.close_error = RuntimeError("PRIVATE CLOSE DETAIL")
            opened = self.opened(module, harness)
            with self.subTest(error=type(primary).__name__):
                if isinstance(primary, Exception):
                    with self.assertRaisesRegex(
                            module.WindowsAclRefused,
                            "^acl_attestation$") as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(type(primary)) as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertIs(raised.exception, primary)
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle, harness.handle])

    def test_sensitive_privilege_observations_are_exact_ordered_and_immutable(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        with module._open_current_process_token(harness.bundle()) as token:
            raw = token._privileges_snapshot()
            snapshot = token._sensitive_privileges_snapshot(raw)
            self.assertEqual(snapshot.values(), (
                ("SeBackupPrivilege", 0xFFFFFFFF, -0x80000000, True, False),
                ("SeRestorePrivilege", 2, 0x7FFFFFFF, True, True),
                ("SeTakeOwnershipPrivilege", 3, 0, False, False),
            ))
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$"):
                snapshot.extra = object()
        lookup_calls = [args for name, args in harness.calls
                        if name == "LookupPrivilegeValueW"]
        self.assertEqual(
            [(args[0], args[1]) for args in lookup_calls],
            [(None, "SeBackupPrivilege"),
             (None, "SeRestorePrivilege"),
             (None, "SeTakeOwnershipPrivilege")],
        )
        self.assertFalse({"LookupPrivilegeNameW", "AccessCheck",
                          "DuplicateTokenEx"}.intersection(
                              self.call_names(harness)))

    def test_sensitive_privilege_lookup_failures_and_duplicates_fail_closed(self):
        module = load_module()
        cases = (
            ("false", lambda h: setattr(h, "lookup_privilege_result", 0)),
            ("bool", lambda h: setattr(h, "lookup_privilege_result", True)),
            ("duplicate", lambda h: setattr(h, "sensitive_luids", {
                "SeBackupPrivilege": (1, -1),
                "SeRestorePrivilege": (1, -1),
                "SeTakeOwnershipPrivilege": (3, 0),
            })),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            with self.subTest(case=name), \
                    module._open_current_process_token(harness.bundle()) as token:
                raw = token._privileges_snapshot()
                self.assert_refused(
                    module,
                    lambda: token._sensitive_privileges_snapshot(raw),
                )

    def test_sensitive_privilege_mapping_drift_is_bound_to_process_profile(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        changed = dict(harness.sensitive_luids)
        changed["SeTakeOwnershipPrivilege"] = (4, 0)
        harness.sensitive_luid_sets = [harness.sensitive_luids, changed]
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_sensitive_privilege_errors_preserve_cleanup_and_redaction(self):
        module = load_module()
        for primary in (RuntimeError("PRIVATE LOOKUP DETAIL"),
                        KeyboardInterrupt(), SystemExit(41)):
            harness = NativeDirectoryHarness(module)
            harness.dlls["advapi32.dll"].functions[
                "LookupPrivilegeValueW"
            ].implementation = lambda *_args, primary=primary: (
                _ for _ in ()
            ).throw(primary)
            harness.close_error = RuntimeError("PRIVATE CLOSE DETAIL")
            opened = self.opened(module, harness)
            with self.subTest(error=type(primary).__name__):
                if isinstance(primary, Exception):
                    with self.assertRaisesRegex(
                            module.WindowsAclRefused,
                            "^acl_attestation$") as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(type(primary)) as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertIs(raised.exception, primary)
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle, harness.handle])

    def test_fixed_process_token_observations_are_exact_and_immutable(self):
        module = load_module()
        for raw, normalized in ((0, False), (1, True), (2, True),
                                (0xFFFFFFFF, True)):
            harness = NativeDirectoryHarness(module)
            harness.token_app_container_values = [raw, raw]
            with self.subTest(raw=raw), \
                    module._open_current_process_token(harness.bundle()) as token:
                snapshot = token._fixed_snapshot()
                self.assertEqual(snapshot.values(), (1, raw, normalized))
                with self.assertRaisesRegex(module.WindowsAclRefused,
                                            "^acl_attestation$"):
                    snapshot.extra = object()
            calls = [args for name, args in harness.calls
                     if name == "GetTokenInformation"]
            self.assertEqual(
                [(args[1], args[2] is None, args[3]) for args in calls],
                [(8, False, 4), (29, False, 4)],
            )

    def test_fixed_process_token_type_and_query_failures_refuse(self):
        module = load_module()
        cases = (
            ("type_zero", lambda h: setattr(h, "token_type_values", [0, 0])),
            ("type_impersonation", lambda h: setattr(
                h, "token_type_values", [2, 2],
            )),
            ("type_other", lambda h: setattr(h, "token_type_values", [3, 3])),
            ("type_false", lambda h: h.token_fixed_results.update({8: 0})),
            ("type_bool", lambda h: h.token_fixed_results.update({8: True})),
            ("type_length", lambda h: h.token_fixed_returned.update({8: 3})),
            ("app_false", lambda h: h.token_fixed_results.update({29: 0})),
            ("app_bool", lambda h: h.token_fixed_results.update({29: True})),
            ("app_length", lambda h: h.token_fixed_returned.update({29: 5})),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            with self.subTest(case=name), \
                    module._open_current_process_token(harness.bundle()) as token:
                self.assert_refused(module, token._fixed_snapshot)
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle])

    def test_fixed_process_token_drift_is_bound_to_profile(self):
        module = load_module()
        for name, mutate in (
            ("app_container", lambda h: setattr(
                h, "token_app_container_values", [0, 1],
            )),
            ("token_type", lambda h: setattr(h, "token_type_values", [1, 2])),
        ):
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_fixed_process_token_errors_preserve_cleanup_and_redaction(self):
        module = load_module()
        for info_class in (8, 29):
            for primary in (RuntimeError("PRIVATE FIXED DETAIL"),
                            KeyboardInterrupt(), SystemExit(43)):
                harness = NativeDirectoryHarness(module)
                original = harness.dlls["advapi32.dll"].functions[
                    "GetTokenInformation"
                ].implementation

                def interrupt_fixed(*args, primary=primary):
                    if args[1] == info_class:
                        raise primary
                    return original(*args)

                harness.dlls["advapi32.dll"].functions[
                    "GetTokenInformation"
                ].implementation = interrupt_fixed
                harness.close_error = RuntimeError("PRIVATE CLOSE DETAIL")
                opened = self.opened(module, harness)
                with self.subTest(info_class=info_class,
                                  error=type(primary).__name__):
                    if isinstance(primary, Exception):
                        with self.assertRaisesRegex(
                                module.WindowsAclRefused,
                                "^acl_attestation$") as raised:
                            with opened:
                                opened._security_snapshot()
                        self.assertNotIn("PRIVATE", str(raised.exception))
                    else:
                        with self.assertRaises(type(primary)) as raised:
                            with opened:
                                opened._security_snapshot()
                        self.assertIs(raised.exception, primary)
                self.assertEqual([args[0] for call, args in harness.calls
                                  if call == "CloseHandle"],
                                 [harness.token_handle, harness.handle])
                forbidden_classes = {9, 11}
                self.assertFalse(forbidden_classes.intersection(
                    args[1] for call, args in harness.calls
                    if call == "GetTokenInformation"
                ))
                self.assertFalse({"IsTokenRestricted", "DuplicateTokenEx",
                                  "AccessCheck"}.intersection(
                                      self.call_names(harness)))

    def test_restricting_sid_snapshot_supports_empty_and_distinct_duplicates(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        with module._open_current_process_token(harness.bundle()) as token:
            empty = token._restricted_sids_snapshot()
            self.assertEqual(empty.values(), ((), False, 4))

        duplicate_sid = bytes.fromhex("010100000000000515000000")
        harness = NativeDirectoryHarness(module)
        harness.token_restricting_sids = [
            (duplicate_sid, 0), (duplicate_sid, 0),
        ]
        with module._open_current_process_token(harness.bundle()) as token:
            snapshot = token._restricted_sids_snapshot()
            expected_length = (
                module.TOKEN_GROUPS.Groups.offset
                + 2 * ctypes.sizeof(module.SID_AND_ATTRIBUTES)
                + 2 * len(duplicate_sid)
            )
            self.assertEqual(snapshot.values(), (
                ((duplicate_sid, "S-1-5-21"),
                 (duplicate_sid, "S-1-5-21")),
                True,
                expected_length,
            ))
            with self.assertRaisesRegex(module.WindowsAclRefused,
                                        "^acl_attestation$"):
                snapshot.extra = object()
        calls = [args for name, args in harness.calls
                 if name == "GetTokenInformation"]
        self.assertEqual(
            [(args[1], args[2] is None, args[3]) for args in calls],
            [(11, True, 0), (11, False, expected_length)],
        )
        names = self.call_names(harness)
        self.assertLess(
            max(index for index, name in enumerate(names)
                if name == "GetTokenInformation"),
            names.index("IsTokenRestricted"),
        )

    def test_restricted_parity_uses_authoritative_list_and_strict_bool_contract(self):
        module = load_module()
        sid = bytes.fromhex("010100000000000515000000")
        cases = (
            ("empty_false", [], [0], None, True),
            ("stale_error_cleared", [], [0], None, True),
            ("false_error", [], [0], 5, False),
            ("empty_true", [], [1], None, False),
            ("nonempty_two", [(sid, 0)], [2], None, True),
            ("nonempty_two_ignores_error", [(sid, 0)], [2], 5, True),
            ("nonempty_negative_ignores_error", [(sid, 0)], [-1], 5, True),
            ("nonempty_false", [(sid, 0)], [0], None, False),
            ("bool_false", [], [False], None, False),
            ("bool_true", [(sid, 0)], [True], None, False),
        )
        for name, records, results, error, accepted in cases:
            harness = NativeDirectoryHarness(module)
            harness.token_restricting_sids = records
            harness.is_token_restricted_results = results
            harness.is_token_restricted_error = error
            if name == "stale_error_cleared":
                module.ctypes.set_last_error(999)
            with self.subTest(case=name), \
                    module._open_current_process_token(harness.bundle()) as token:
                if accepted:
                    snapshot = token._restricted_sids_snapshot()
                    self.assertEqual(snapshot.values()[1], bool(records))
                else:
                    self.assert_refused(module, token._restricted_sids_snapshot)
            names = self.call_names(harness)
            fill = max(index for index, call in enumerate(names)
                       if call == "GetTokenInformation")
            parity = names.index("IsTokenRestricted")
            self.assertLess(fill, parity)

    def test_restricted_parity_errors_preserve_cleanup_and_redaction(self):
        module = load_module()
        for primary in (RuntimeError("PRIVATE PARITY DETAIL"),
                        KeyboardInterrupt(), SystemExit(53)):
            harness = NativeDirectoryHarness(module)

            def interrupt(_handle, primary=primary):
                raise primary

            harness.dlls["advapi32.dll"].functions[
                "IsTokenRestricted"
            ].implementation = interrupt
            harness.close_error = RuntimeError("PRIVATE CLOSE DETAIL")
            opened = self.opened(module, harness)
            with self.subTest(error=type(primary).__name__):
                if isinstance(primary, Exception):
                    with self.assertRaisesRegex(
                            module.WindowsAclRefused,
                            "^acl_attestation$") as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(type(primary)) as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertIs(raised.exception, primary)
            self.assertEqual(
                [args[0] for call, args in harness.calls
                 if call == "CloseHandle"],
                [harness.token_handle, harness.handle],
            )
            self.assertFalse({"DuplicateTokenEx", "AccessCheck",
                              "CheckTokenMembership"}.intersection(
                                  self.call_names(harness)))

    def test_restricting_sid_bounds_attributes_and_overlap_fail_closed(self):
        module = load_module()
        sid = bytes.fromhex("010100000000000515000000")
        table_end = (module.TOKEN_GROUPS.Groups.offset
                     + 2 * ctypes.sizeof(module.SID_AND_ATTRIBUTES))

        def partial_overlap(harness):
            interior = bytes.fromhex("010100000000000516000000")
            long_sid = (bytes.fromhex("0104000000000005")
                        + interior + b"\x04\x00\x00\x00")
            harness.token_restricting_sids = [(long_sid, 0), (interior, 0)]
            harness.token_restricted_pointer_overrides[1] = table_end + 8

        cases = (
            ("probe_true", lambda h: setattr(
                h, "token_restricted_probe_result", 1,
            )),
            ("probe_bool", lambda h: setattr(
                h, "token_restricted_probe_result", False,
            )),
            ("probe_error", lambda h: setattr(
                h, "token_restricted_probe_error", 5,
            )),
            ("required_small", lambda h: setattr(
                h, "token_restricted_required_override", 3,
            )),
            ("required_large", lambda h: setattr(
                h, "token_restricted_required_override",
                module.MAX_TOKEN_RESTRICTED_SIDS_BYTES + 1,
            )),
            ("empty_not_four", lambda h: setattr(
                h, "token_restricted_required_override", 8,
            )),
            ("fill_false", lambda h: setattr(
                h, "token_restricted_fill_result", 0,
            )),
            ("fill_bool", lambda h: setattr(
                h, "token_restricted_fill_result", True,
            )),
            ("returned", lambda h: setattr(
                h, "token_restricted_returned_override", 1,
            )),
            ("count", lambda h: setattr(
                h, "token_restricted_count_override", 4097,
            )),
            ("table_span", lambda h: (
                setattr(h, "token_restricting_sids", [(sid, 0)]),
                setattr(h, "token_restricted_count_override", 2),
            )),
            ("attributes", lambda h: setattr(
                h, "token_restricting_sids", [(sid, 1)],
            )),
            ("pointer_table", lambda h: (
                setattr(h, "token_restricting_sids", [(sid, 0)]),
                h.token_restricted_pointer_overrides.update({0: 4}),
            )),
            ("pointer_tail", lambda h: (
                setattr(h, "token_restricting_sids", [(sid, 0)]),
                h.token_restricted_pointer_overrides.update({0:
                    module.TOKEN_GROUPS.Groups.offset
                    + ctypes.sizeof(module.SID_AND_ATTRIBUTES)
                    + len(sid) - 4}),
            )),
            ("sid_revision", lambda h: setattr(
                h, "token_restricting_sids", [(b"\x02" + sid[1:], 0)],
            )),
            ("sid_count", lambda h: setattr(
                h, "token_restricting_sids", [(
                    b"\x01\x0f" + sid[2:], 0,
                )],
            )),
            ("sid_native", lambda h: (
                setattr(h, "token_restricting_sids", [(sid, 0)]),
                setattr(h, "token_valid_sid", 0),
            )),
            ("sid_length", lambda h: (
                setattr(h, "token_restricting_sids", [(sid, 0)]),
                setattr(h, "token_sid_length_override", 8),
            )),
            ("sid_live_drift", lambda h: (
                setattr(h, "token_restricting_sids", [(sid, 0)]),
                setattr(h, "token_sid_mutate_during_validation", True),
            )),
            ("overlap", partial_overlap),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            with self.subTest(case=name), \
                    module._open_current_process_token(harness.bundle()) as token:
                self.assert_refused(module, token._restricted_sids_snapshot)
            if name == "overlap":
                self.assertEqual(
                    [call for call, _args in harness.calls
                     if call in {"IsValidSid", "GetLengthSid"}],
                    ["IsValidSid", "GetLengthSid"],
                )
            if name == "sid_native":
                self.assertNotIn("GetLengthSid", self.call_names(harness))
            if name in {"sid_length", "sid_live_drift"}:
                self.assertEqual(
                    [call for call, _args in harness.calls
                     if call in {"IsValidSid", "GetLengthSid"}],
                    ["IsValidSid", "GetLengthSid"],
                )
            if name == "sid_count":
                self.assertFalse(
                    {"IsValidSid", "GetLengthSid"}.intersection(
                        self.call_names(harness),
                    ),
                )

    def test_restricting_sid_drift_is_bound_to_process_profile(self):
        module = load_module()
        first = bytes.fromhex("010100000000000515000000")
        second = bytes.fromhex("010100000000000516000000")
        for name, sets in (
            ("encoding", [[], [(first, 0)]]),
            ("order", [[(first, 0), (second, 0)],
                       [(second, 0), (first, 0)]]),
            ("attributes", [[(first, 0)], [(first, 1)]]),
        ):
            harness = NativeDirectoryHarness(module)
            harness.token_restricting_sid_sets = sets
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_restricting_sid_errors_preserve_cleanup_and_forbidden_calls(self):
        module = load_module()
        for primary in (RuntimeError("PRIVATE RESTRICTED DETAIL"),
                        KeyboardInterrupt(), SystemExit(47)):
            harness = NativeDirectoryHarness(module)
            original = harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation

            def interrupt_restricted(*args, primary=primary):
                if args[1] == 11:
                    raise primary
                return original(*args)

            harness.dlls["advapi32.dll"].functions[
                "GetTokenInformation"
            ].implementation = interrupt_restricted
            harness.close_error = RuntimeError("PRIVATE CLOSE DETAIL")
            opened = self.opened(module, harness)
            with self.subTest(error=type(primary).__name__):
                if isinstance(primary, Exception):
                    with self.assertRaisesRegex(
                            module.WindowsAclRefused,
                            "^acl_attestation$") as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertNotIn("PRIVATE", str(raised.exception))
                else:
                    with self.assertRaises(type(primary)) as raised:
                        with opened:
                            opened._security_snapshot()
                    self.assertIs(raised.exception, primary)
            self.assertEqual([args[0] for call, args in harness.calls
                              if call == "CloseHandle"],
                             [harness.token_handle, harness.handle])
            self.assertFalse({"IsTokenRestricted", "DuplicateTokenEx",
                              "AccessCheck", "CheckTokenMembership"}.intersection(
                                  self.call_names(harness)))

    def test_process_token_open_and_query_boundaries_fail_closed(self):
        module = load_module()
        cases = (
            ("process_null", lambda h: setattr(h, "process_handle", 0)),
            ("process_bool", lambda h: setattr(h, "process_handle", True)),
            ("open_failure_with_output", lambda h: setattr(h, "open_token_result", 0)),
            ("open_null", lambda h: setattr(h, "open_token_output", False)),
            ("open_invalid", lambda h: setattr(h, "token_handle",
                                                module.INVALID_HANDLE_VALUE)),
            ("set_inherit_failure", lambda h: setattr(h, "token_set_result", 0)),
            ("get_flags_failure", lambda h: setattr(h, "token_get_result", 0)),
            ("inherit", lambda h: setattr(h, "token_handle_flags", 1)),
            ("protect_close", lambda h: setattr(h, "token_handle_flags", 2)),
            ("probe_true", lambda h: setattr(h, "token_probe_result", 1)),
            ("probe_bool_false", lambda h: setattr(h, "token_probe_result", False)),
            ("probe_error", lambda h: setattr(h, "token_probe_error", 5)),
            ("required_small", lambda h: setattr(
                h, "token_required_override", ctypes.sizeof(module.TOKEN_USER) + 7,
            )),
            ("required_large", lambda h: setattr(
                h, "token_required_override", module.MAX_TOKEN_USER_BYTES + 1,
            )),
            ("fill_false", lambda h: setattr(h, "token_fill_result", 0)),
            ("fill_bool_true", lambda h: setattr(h, "token_fill_result", True)),
            ("returned_mismatch", lambda h: setattr(h, "token_returned_override", 1)),
            ("sid_overlap", lambda h: setattr(h, "token_sid_offset_override", 8)),
            ("sid_tail", lambda h: setattr(
                h, "token_sid_offset_override",
                ctypes.sizeof(module.TOKEN_USER) + len(h.owner_sid) - 4,
            )),
            ("sid_revision", lambda h: setattr(
                h, "token_sids", [b"\x02" + h.owner_sid[1:]] * 2,
            )),
            ("sid_count", lambda h: setattr(
                h, "token_sids", [b"\x01\x02" + h.owner_sid[2:]] * 2,
            )),
            ("sid_native", lambda h: setattr(h, "token_valid_sid", 0)),
            ("sid_length", lambda h: setattr(h, "token_sid_length_override", 8)),
            ("sid_live_drift", lambda h: setattr(
                h, "token_sid_mutate_during_validation", True,
            )),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            closed = [args[0] for call, args in harness.calls
                      if call == "CloseHandle"]
            if name in {"process_null", "process_bool", "open_null", "open_invalid"}:
                self.assertEqual(closed, [harness.handle])
            else:
                self.assertEqual(closed, [harness.token_handle, harness.handle])
            self.assertNotIn(harness.process_handle, closed)
            self.assertFalse({"TokenGroups", "TokenPrivileges",
                              "DuplicateTokenEx", "AccessCheck",
                              "ConvertSidToStringSidW"}.intersection(
                                  self.call_names(harness)))

    def test_process_token_and_descriptor_owner_must_be_stable_and_exact(self):
        module = load_module()
        different = bytes.fromhex("010100000000000521000000")
        for name, token_sids in (
            ("token_drift", [NativeDirectoryHarness(module).owner_sid, different]),
            ("owner_mismatch", [different, different]),
        ):
            harness = NativeDirectoryHarness(module)
            harness.token_sids = token_sids
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)
            self.assertEqual(self.call_names(harness).count("GetTokenInformation"), 20)
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_local_system_token_user_is_rejected_before_descriptor_access(self):
        module = load_module()
        local_system = bytes.fromhex("010100000000000512000000")
        self.assertEqual(module.LOCAL_SYSTEM_SID_BYTES, local_system)
        self.assertEqual(module.LOCAL_SYSTEM_SID, "S-1-5-18")
        self.assertEqual(module._canonical_sid(local_system), "S-1-5-18")

        for owner_matches in (False, True):
            harness = NativeDirectoryHarness(module)
            harness.token_sids = [local_system, local_system]
            if owner_matches:
                harness.owner_sid = local_system
                harness.trustee_sid = local_system
            opened = self.opened(module, harness)
            with self.subTest(owner_matches=owner_matches), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertNotIn("GetSecurityInfo", self.call_names(harness))
            self.assertEqual(
                [args[0] for call, args in harness.calls
                 if call == "CloseHandle"],
                [harness.token_handle, harness.handle],
            )
            self.assertFalse({"DuplicateTokenEx", "AccessCheck",
                              "CheckTokenMembership"}.intersection(
                                  self.call_names(harness)))

    def test_local_system_group_or_restricting_sid_is_not_token_identity(self):
        module = load_module()
        local_system = bytes.fromhex("010100000000000512000000")
        harness = NativeDirectoryHarness(module)
        harness.token_groups = [(local_system, 0)]
        harness.token_restricting_sids = [(local_system, 0)]
        opened = self.opened(module, harness)
        with opened:
            values = opened._security_snapshot().values()
        self.assertNotEqual(values["processTokenSid"], "S-1-5-18")
        self.assertEqual(values["processTokenGroups"], (
            (local_system, "S-1-5-18", 0),
        ))
        self.assertEqual(values["processTokenRestrictingSids"], (
            (local_system, "S-1-5-18"),
        ))
        self.assertEqual(self.call_names(harness).count("GetSecurityInfo"), 2)

    def test_process_token_cleanup_preserves_primary_base_exception(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        primary = KeyboardInterrupt()

        def interrupt(*_args):
            raise primary

        opened = self.opened(module, harness)
        harness.dlls["advapi32.dll"].functions[
            "GetTokenInformation"
        ].implementation = interrupt
        harness.close_error = SystemExit(19)
        with self.assertRaises(KeyboardInterrupt) as raised:
            with opened:
                opened._security_snapshot()
        self.assertIs(raised.exception, primary)
        closed = [args[0] for name, args in harness.calls
                  if name == "CloseHandle"]
        self.assertEqual(closed, [harness.token_handle, harness.handle])

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
            if name in {"owner_span", "group_span"}:
                expected_prior_sids = 3 if name == "owner_span" else 4
                self.assertEqual(
                    self.call_names(harness).count("IsValidSid"),
                    expected_prior_sids,
                )
                self.assertEqual(
                    self.call_names(harness).count("GetLengthSid"),
                    expected_prior_sids,
                )
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

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
            ("used_over_size", lambda h: setattr(h, "acl_bytes_in_use", 33)),
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

    def test_single_allowed_ace_oracle_and_copied_parser_fail_closed(self):
        module = load_module()
        cases = (
            ("count_zero", lambda h: (
                setattr(h, "ace_count", 0), setattr(h, "header_ace_count", 0),
            )),
            ("count_two", lambda h: (
                setattr(h, "ace_count", 2), setattr(h, "header_ace_count", 2),
            )),
            ("get_ace_failure", lambda h: setattr(h, "get_ace_result", 0)),
            ("get_ace_null", lambda h: setattr(h, "get_ace_null", True)),
            ("get_ace_wrong_start", lambda h: setattr(h, "ace_pointer_delta", 12)),
            ("get_ace_misaligned", lambda h: setattr(h, "ace_pointer_delta", 9)),
            ("get_ace_outside", lambda h: setattr(h, "ace_pointer_delta", 200)),
            ("wrong_type", lambda h: setattr(h, "ace_type", 1)),
            ("missing_inheritance", lambda h: setattr(h, "ace_flags", 1)),
            ("inherited", lambda h: setattr(h, "ace_flags", 0x13)),
            ("ace_size_under", lambda h: setattr(h, "ace_size", 12)),
            ("ace_size_over", lambda h: setattr(h, "ace_size", 24)),
            ("ace_size_unaligned", lambda h: setattr(h, "ace_size", 18)),
            ("trailing_used_bytes", lambda h: (
                setattr(h, "acl_bytes_in_use", 32), setattr(h, "acl_bytes_free", 0),
            )),
            ("trustee_revision", lambda h: setattr(
                h, "trustee_sid", b"\x02" + h.trustee_sid[1:],
            )),
            ("trustee_zero_subauth", lambda h: setattr(
                h, "trustee_sid", b"\x01\x00" + h.trustee_sid[2:8],
            )),
            ("trustee_count_mismatch", lambda h: setattr(
                h, "trustee_sid", b"\x01\x02" + h.trustee_sid[2:],
            )),
            ("trustee_length_mismatch", lambda h: setattr(
                h, "trustee_length_override", 8,
            )),
            ("trustee_owner_mismatch", lambda h: setattr(
                h, "trustee_sid", bytes.fromhex("010100000000000521000000"),
            )),
        )
        for name, mutate in cases:
            harness = NativeDirectoryHarness(module)
            mutate(harness)
            opened = self.opened(module, harness)
            with self.subTest(case=name), opened:
                self.assert_refused(module, opened._security_snapshot)
            self.assertEqual(self.call_names(harness).count("LocalFree"), 1)
            self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

    def test_sid_canonicalization_locks_authority_and_subauthority_byte_order(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)
        sid = bytes.fromhex("0102010203040506040302010d0c0b0a")
        harness.owner_sid = sid
        harness.trustee_sid = sid
        harness.ace_size = 24
        harness.acl_bytes_in_use = 32
        harness.acl_size = 36
        harness.header_acl_size = 36
        harness.acl_bytes_free = 4
        opened = self.opened(module, harness)
        with opened:
            values = opened._security_snapshot().values()
        expected = "S-1-1108152157446-16909060-168496141"
        self.assertEqual(values["ownerSid"], expected)
        self.assertEqual(values["trusteeSid"], expected)

        get_ace_calls = [args for name, args in harness.calls if name == "GetAce"]
        self.assertEqual([args[1] for args in get_ace_calls], [0, 0])
        self.assertFalse({"DuplicateTokenEx", "AccessCheck",
                          "ConvertSidToStringSidW"}.intersection(
                              self.call_names(harness)))

    def test_second_snapshot_rejects_ace_semantic_drift(self):
        module = load_module()
        harness = NativeDirectoryHarness(module)

        def mutate_second(index, raw):
            if index == 1:
                raw[harness.dacl_offset + 12] ^= 1

        harness.security_mutator = mutate_second
        opened = self.opened(module, harness)
        with opened:
            self.assert_refused(module, opened._security_snapshot)
        self.assertEqual(self.call_names(harness).count("GetAce"), 2)
        self.assertEqual(self.call_names(harness).count("LocalFree"), 2)

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
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

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
        self.assertEqual(self.call_names(harness).count("CloseHandle"), 2)

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
