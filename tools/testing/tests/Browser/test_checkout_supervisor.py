"""Supervisor unit tests plus one owned local listener inspection; no browser or DB."""
import importlib.util
import json
import os
from pathlib import Path
import socket
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("supervisor", Path(__file__).with_name("checkout-supervisor.py"))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class Fake:
    def __init__(self, fail=None, cleanup=True, duration=.01):
        self.calls = []
        self.time = 0
        self.fail = fail
        self.clean = cleanup
        self.duration = duration
        self.claimed = False
        self.launched = []

    def clock(self):
        return self.time

    def call(self, name):
        self.calls.append(name)
        self.time += self.duration
        if name == self.fail:
            raise RuntimeError("PRIVATE_PAYLOAD")

    def preflight(self, left): self.call("preflight")
    def assert_ports_free(self, left): self.call("ports")
    def claim(self, left):
        self.claimed = True
        self.call("claim")
    def harness(self, mode, left, assertions=False):
        self.calls.append(("assertions", assertions))
        self.call(mode)
    def launch(self, role, left):
        self.launched.append(role)
        self.call("launch-" + role)
    def assert_owned(self, left): self.call("ownership")
    def smoke_request(self, left):
        self.call("request")
        return .5
    def full_matrix(self, left): self.call("matrix")
    def cleanup(self, budget):
        self.call("cleanup")
        return self.clean
    def invalidate(self): self.call("invalid")


class SupervisorTests(unittest.TestCase):
    def test_listener_outcomes_distinguish_free_occupied_and_inspection_failure(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run._ps = lambda command, timeout=3: "[]"
        self.assertEqual(run._listeners(), [])
        run.assert_ports_free(10)

        row = {"pid": 123, "port": 8126, "address": "127.0.0.1"}
        run._ps = lambda command, timeout=3: json.dumps([row])
        self.assertEqual(run._listeners(), [row])
        with self.assertRaisesRegex(m.Refused, "^occupied_port$"):
            run.assert_ports_free(10)

        for value in ("null", "{}", '"PRIVATE"', '[{"pid":true,"port":8126,"address":"x"}]'):
            with self.subTest(value=value):
                run._ps = lambda command, timeout=3, value=value: value
                with self.assertRaisesRegex(m.Refused, "^listener_inspection_failed$"):
                    run._listeners()
        for failure in (RuntimeError("PRIVATE"), m.Refused("command")):
            with self.subTest(failure=type(failure).__name__):
                run._ps = lambda command, timeout=3, failure=failure: (_ for _ in ()).throw(failure)
                with self.assertRaisesRegex(m.Refused, "^listener_inspection_failed$"):
                    run._listeners()

    def test_listener_inspection_failure_is_not_reported_as_occupation(self):
        for failure in (m.Refused("listener_inspection_failed"), RuntimeError("PRIVATE")):
            with self.subTest(failure=type(failure).__name__):
                f = Fake()
                f.assert_ports_free = lambda left, failure=failure: (_ for _ in ()).throw(failure)
                result = m.supervise(f)
                self.assertEqual(result["reason"], "listener_inspection_failed")
                self.assertEqual(f.launched, [])
        f = Fake()
        f.assert_ports_free = lambda left: (_ for _ in ()).throw(m.Refused("occupied_port"))
        self.assertEqual(m.supervise(f)["reason"], "occupied_port")

    @unittest.skipUnless(socket.has_ipv6, "IPv6 is unavailable")
    def test_real_listener_inspector_covers_ipv4_and_ipv6_wildcards_without_killing(self):
        run = m.WindowsRun({"directory": str(Path.cwd()), "powershell": "C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe"})
        run.env = {key: os.environ[key] for key in ("SystemRoot", "TEMP", "TMP")}
        run.io_deadline = float("inf")
        if run._listeners():
            self.skipTest("controlled ports are occupied")
        sockets = []
        try:
            ipv4 = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            ipv4.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            ipv4.bind(("0.0.0.0", 8126))
            ipv4.listen()
            sockets.append(ipv4)
            ipv6 = socket.socket(socket.AF_INET6, socket.SOCK_STREAM)
            ipv6.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
            ipv6.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            ipv6.bind(("::", 443))
            ipv6.listen()
            sockets.append(ipv6)
        except OSError:
            for owned in sockets:
                owned.close()
            self.skipTest("controlled IPv4/IPv6 wildcard listeners could not both bind")
        try:
            rows = run._listeners()
            self.assertEqual({row["port"] for row in rows}, {443, 8126})
            self.assertEqual({row["pid"] for row in rows}, {os.getpid()})
            self.assertTrue(any(":" in row["address"] for row in rows))
            self.assertTrue(any("." in row["address"] or row["address"] == "0.0.0.0" for row in rows))
        finally:
            for owned in sockets:
                owned.close()
        self.assertEqual(run._listeners(), [])

    def test_smoke_exact_three_only_after_start_and_never_accepts(self):
        f = Fake()
        result = m.supervise(f)
        self.assertEqual(result, dict(state="incomplete", accepted=False, requests=3, reason="fresh_run_required"))
        self.assertLess(f.calls.index("integrity-start"), f.calls.index("request"))
        self.assertEqual(f.launched, ["php", "tls", "browser"])
        self.assertEqual(f.calls.count("request"), 3)
        self.assertIn("invalid", f.calls)
        for forbidden in ("matrix", "verify", "integrity-stop", "integrity-post", ("assertions", True)):
            self.assertNotIn(forbidden, f.calls)

    def test_full_cleanup_then_stop_verify_cleanup_post(self):
        f = Fake()
        result = m.supervise(f, mode="full")
        self.assertEqual(result["state"], "postverified")
        self.assertFalse(result["accepted"])
        self.assertEqual(f.calls.count("cleanup"), 3)
        self.assertLess(f.calls.index("cleanup"), f.calls.index("integrity-stop"))
        self.assertLess(f.calls.index("verify"), f.calls.index("integrity-post"))
        self.assertIn(("assertions", True), f.calls)

    def test_each_stage_failure_sanitizes_and_cleans_without_rearm(self):
        stages = ("preflight", "ports", "claim", "integrity-pre", "launch-php", "launch-tls", "launch-browser", "ownership", "integrity-start", "request", "cleanup")
        for stage in stages:
            with self.subTest(stage=stage):
                f = Fake(fail=stage)
                result = m.supervise(f)
                self.assertEqual(result["state"], "invalid")
                self.assertFalse(result["accepted"])
                self.assertNotIn("PRIVATE", str(result))
                self.assertNotIn("integrity-post", f.calls)
                if f.claimed:
                    self.assertIn("invalid", f.calls)
                    self.assertIn("cleanup", f.calls)
                else:
                    self.assertEqual(f.launched, [])

    def test_partial_spawn_cleanup_failure_never_becomes_smoke_success(self):
        f = Fake(fail="launch-browser", cleanup=False)
        result = m.supervise(f)
        self.assertEqual(result["reason"], "cleanup")
        self.assertIn("invalid", f.calls)
        self.assertNotIn("request", f.calls)

    def test_budget_exhaustion_runs_cleanup(self):
        f = Fake(duration=.3)
        result = m.supervise(f, budget=1)
        self.assertEqual(result["state"], "invalid")
        self.assertIn("cleanup", f.calls)
        self.assertNotIn("request", f.calls)

    def test_request_target_failure_does_not_continue(self):
        f = Fake()
        def slow(left):
            f.call("request")
            return 5.01
        f.smoke_request = slow
        result = m.supervise(f)
        self.assertEqual(result["state"], "invalid")
        self.assertEqual(f.calls.count("request"), 1)

    def test_bounds_rejected_before_preflight(self):
        for options in ({"requests": 0}, {"requests": 4}, {"requests": True}, {"budget": 0}, {"budget": 1201}, {"mode": "other"}):
            f = Fake()
            with self.assertRaises(m.Refused):
                m.supervise(f, **options)
            self.assertEqual(f.calls, [])

    def test_smoke_one_two_three(self):
        for count in (1, 2, 3):
            f = Fake()
            self.assertEqual(m.supervise(f, requests=count)["requests"], count)

    def test_failed_full_verifier_or_post_never_accepts(self):
        for stage in ("matrix", "integrity-stop", "verify", "integrity-post"):
            f = Fake(fail=stage)
            self.assertEqual(m.supervise(f, mode="full")["state"], "invalid")
            self.assertIn("invalid", f.calls)

    def test_descendants_and_pid_reuse(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        run._snapshot = lambda: [{"pid": 10, "parent": 1, "started": "100"}, {"pid": 11, "parent": 10, "started": "101"}, {"pid": 12, "parent": 11, "started": "102"}]
        run._discover()
        self.assertEqual(run.owned, {10: "100", 11: "101", 12: "102"})
        run._snapshot = lambda: [{"pid": 10, "parent": 1, "started": "900"}, {"pid": 13, "parent": 10, "started": "901"}]
        run._discover()
        self.assertTrue(run.uncertain)
        self.assertNotIn(13, run.owned)

    def test_unknown_child_tick_is_not_adopted(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        run._snapshot = lambda: [{"pid": 11, "parent": 10, "started": None}]
        run._discover()
        self.assertTrue(run.uncertain)
        self.assertNotIn(11, run.owned)

    def test_full_mode_explicitly_releases_offline_after_abort_guard(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        seen = []
        run._run_code = lambda code, left: seen.append(code) or dict(fullBusinessPostcondition=True, credentialMaterialRecorded=False, checks=[1]*11)
        with patch.object(Path, "read_text", return_value="async(page)=>({})"):
            run.full_matrix(30)
        self.assertIn("setOffline(false)", seen[0])
        self.assertLess(seen[0].index("route('**/*'"), seen[0].index("setOffline(false)"))

    def test_missing_asset_delivery_review_blocks_before_process_or_source_read(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        with patch.object(Path, "read_bytes", side_effect=AssertionError("must not read")), patch.object(m.subprocess, "Popen", side_effect=AssertionError("must not spawn")):
            with self.assertRaisesRegex(m.Refused, "asset_delivery_review_required"):
                run.preflight(1)

    def test_keyboard_interrupt_still_cleans_owned_work(self):
        f = Fake()
        def interrupt(left):
            raise KeyboardInterrupt
        f.smoke_request = interrupt
        with self.assertRaises(KeyboardInterrupt):
            m.supervise(f)
        self.assertIn("cleanup", f.calls)
        self.assertIn("invalid", f.calls)

    def test_cleanup_kills_only_exact_owned_identity_not_unknown_listener(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        current = {10: {"started": "100"}, 999: {"started": "900"}}
        commands = []
        run._discover = lambda: current
        def ps(command):
            commands.append(command)
            current.pop(10, None)
            return ""
        run._ps = ps
        run._listeners = lambda: [{"pid": 999, "port": 443, "address": "127.0.0.1"}]
        with patch.object(m.time, "sleep"):
            self.assertFalse(run.cleanup(1))
        self.assertEqual(len(commands), 1)
        self.assertIn("GetProcessById(10)", commands[0])
        self.assertIn("-eq '100'", commands[0])
        self.assertNotIn("999", commands[0])

    def test_cleanup_stolen_pid_never_killed(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        run.uncertain = True
        run._discover = lambda: {10: {"started": "999"}}
        run._ps = lambda command: self.fail("must not kill reused PID")
        run._listeners = lambda: []
        self.assertFalse(run.cleanup(1))

    def test_partial_spawn_handle_cleanup_even_when_inspection_fails(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        class Handle:
            killed = False
            def poll(self): return None
            def kill(self): self.killed = True
            def wait(self, timeout): return 0
        handle = Handle()
        run.handles = [handle]
        run._discover = lambda: (_ for _ in ()).throw(m.Refused("inspection"))
        self.assertFalse(run.cleanup(1))
        self.assertTrue(handle.killed)

    def test_no_postcheck_after_second_cleanup_failure(self):
        f = Fake()
        calls = []
        def cleanup(budget):
            f.call("cleanup")
            calls.append(True)
            return len(calls) == 1
        f.cleanup = cleanup
        result = m.supervise(f, mode="full")
        self.assertEqual(result["reason"], "final_cleanup")
        self.assertNotIn("integrity-post", f.calls)
        self.assertIn("invalid", f.calls)

    def test_deadline_prevents_adapter_spawn(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.io_deadline = 0
        with patch.object(m.subprocess, "Popen", side_effect=AssertionError("must not spawn")):
            with self.assertRaisesRegex(m.Refused, "budget"):
                run._command(["unused"], 1)

    def test_smoke_offline_only_released_after_exact_one_request_route(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        seen = []
        run._run_code = lambda code, left: seen.append(code) or {"seconds": .2, "requests": 1}
        self.assertEqual(run.smoke_request(3), .2)
        self.assertIn("++n===1", seen[0])
        self.assertIn("timeout:30000", seen[0])
        self.assertLess(seen[0].index("route('**/*'"), seen[0].index("setOffline(false)"))

    def test_numeric_orphan_parent_is_not_ownership_proof(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        run._snapshot = lambda: [{"pid": 11, "parent": 10, "started": "101"}]
        run._discover()
        self.assertTrue(run.uncertain)
        self.assertNotIn(11, run.owned)

    def test_late_stage_errors_always_cleanup_after_the_failure(self):
        for stage in ("integrity-stop", "verify", "integrity-post"):
            for error_type in (RuntimeError, KeyboardInterrupt, SystemExit):
                with self.subTest(stage=stage, error=error_type.__name__):
                    f = Fake()
                    original = f.harness
                    failure = error_type("SYNTHETIC")
                    def harness(mode, left, assertions=False):
                        original(mode, left, assertions)
                        if mode == stage:
                            raise failure
                    f.harness = harness
                    if error_type is RuntimeError:
                        result = m.supervise(f, mode="full")
                        self.assertEqual(result["state"], "invalid")
                        self.assertFalse(result["accepted"])
                    else:
                        with self.assertRaises(error_type) as caught:
                            m.supervise(f, mode="full")
                        self.assertIs(caught.exception, failure)
                    self.assertIn("cleanup", f.calls[f.calls.index(stage)+1:])
                    self.assertIn("invalid", f.calls)

    def test_successful_post_requires_fresh_final_cleanup(self):
        f = Fake()
        result = m.supervise(f, mode="full")
        self.assertIn("cleanup", f.calls[f.calls.index("integrity-post")+1:])
        self.assertEqual(result["state"], "postverified")

    def test_uncertainty_discovered_after_post_invalidates_even_true_cleanup(self):
        f = Fake()
        def cleanup(budget):
            f.call("cleanup")
            if "integrity-post" in f.calls:
                f.uncertain = True
            return True
        f.cleanup = cleanup
        result = m.supervise(f, mode="full")
        self.assertEqual(result["state"], "invalid")
        self.assertIn("invalid", f.calls)

    def test_final_cleanup_failure_or_interrupt_invalidates(self):
        for kind in ("false", "error", "interrupt", "exit"):
            with self.subTest(kind=kind):
                f = Fake()
                failure = KeyboardInterrupt() if kind == "interrupt" else SystemExit(3)
                def cleanup(budget):
                    f.call("cleanup")
                    if "integrity-post" in f.calls:
                        if kind == "false": return False
                        if kind == "error": raise RuntimeError("SYNTHETIC")
                        raise failure
                    return True
                f.cleanup = cleanup
                if kind in ("interrupt", "exit"):
                    with self.assertRaises(type(failure)) as caught:
                        m.supervise(f, mode="full")
                    self.assertIs(caught.exception, failure)
                else:
                    self.assertEqual(m.supervise(f, mode="full")["state"], "invalid")
                self.assertIn("invalid", f.calls)

    def test_cleanup_reserve_is_shared_without_reset_or_recursion(self):
        f = Fake()
        allowances = []
        def cleanup(budget):
            f.call("cleanup")
            allowances.append(budget)
            f.time += 4
            return True
        f.cleanup = cleanup
        m.supervise(f, mode="full")
        self.assertEqual(len(allowances), 3)
        self.assertLess(allowances[1], allowances[0] - 3.9)
        self.assertLess(allowances[2], allowances[1] - 3.9)
        self.assertLessEqual(allowances[0], 15)

    def test_known_child_alive_after_post_is_cleaned_but_evidence_invalid(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        run.postcheck_complete = True
        current = {10: {"started": "100"}}
        run._discover = lambda: current
        run._ps = lambda command: current.pop(10, None)
        run._listeners = lambda: []
        with patch.object(m.time, "sleep"):
            self.assertFalse(run.cleanup(1))
        self.assertEqual(current, {})
        self.assertTrue(run.uncertain)

    def test_exhausted_cleanup_reserve_never_gets_another_fifteen_seconds(self):
        f = Fake()
        allowances = []
        def cleanup(budget):
            f.call("cleanup")
            allowances.append(budget)
            f.time += 15
            return True  # A lying/slow adapter cannot bypass the supervisor's elapsed check.
        f.cleanup = cleanup
        result = m.supervise(f, mode="full")
        self.assertEqual(allowances, [15.0])
        self.assertEqual(result["state"], "invalid")
        self.assertIn("invalid", f.calls)
        self.assertNotIn("integrity-stop", f.calls)

    def test_error_after_post_with_new_recorded_child_gets_final_cleanup(self):
        f = Fake()
        owned = []
        original = f.harness
        def harness(mode, left, assertions=False):
            original(mode, left, assertions)
            if mode == "integrity-post":
                owned.append("known-child")
                raise RuntimeError("SYNTHETIC")
        def cleanup(budget):
            f.call("cleanup")
            owned.clear()
            return True
        f.harness, f.cleanup = harness, cleanup
        result = m.supervise(f, mode="full")
        self.assertEqual(owned, [])
        self.assertEqual(result["state"], "invalid")
        self.assertIn("invalid", f.calls)


if __name__ == "__main__":
    unittest.main()
