"""Supervisor unit tests plus one owned local listener inspection; no browser or DB."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import socket
from tempfile import TemporaryDirectory
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


class AnchorStore:
    def __init__(self, hook=None):
        self.latest = None
        self.hook = hook
        self.frozen = False

    def __call__(self, anchor):
        if self.hook is not None:
            self.hook(anchor)
        if not self.frozen:
            self.latest = dict(anchor)

    def load(self):
        return None if self.latest is None else dict(self.latest)


class StaticPublisher:
    def __init__(self, value=None, error=None):
        self.value = value
        self.error = error
        self.calls = []

    def __call__(self, anchor):
        self.calls.append(dict(anchor))

    def load(self):
        if self.error is not None:
            raise self.error("load failed")
        return self.value


class SupervisorTests(unittest.TestCase):
    def setUp(self):
        self.anchor_stores = {}

    def publisher(self, directory, hook=None):
        previous = self.anchor_stores.get(str(Path(directory)))
        store = AnchorStore(hook)
        if previous is not None:
            store.latest = previous.load()
        self.anchor_stores[str(Path(directory))] = store
        return store

    def journal_run(self, directory):
        filenames = {
            "php": "php", "python": "python", "node": "node", "powershell": "powershell",
            "cli": "cli", "browser": "browser", "ini": "runtime.ini",
            "browser_config": "browser-config.json", "cert": "cert.pem", "key": "key.pem",
        }
        paths = {name: str(Path(directory) / filename) for name, filename in filenames.items()}
        for index, path in enumerate(paths.values(), 1):
            if not Path(path).exists():
                Path(path).write_bytes(f"synthetic-{index}".encode())
        Path(paths["browser_config"]).write_text(
            json.dumps(self.browser_config(paths["browser"])), encoding="utf-8"
        )
        store = self.anchor_stores.setdefault(str(Path(directory)), AnchorStore())
        return m.WindowsRun({
            "directory": directory, "manifest": "a" * 64, **paths,
            "tool_hashes": {name: hashlib.sha256(Path(path).read_bytes()).hexdigest()
                            for name, path in paths.items()},
            "asset_delivery_review": {name: "b" * 64 for name in m.ASSET_REVIEW_FILES},
        }, anchor_publisher=store)

    def rewrite_journal(self, run, mutate):
        path = sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1]
        document = json.loads(path.read_text(encoding="utf-8"))
        document.pop("integrity")
        mutate(document)
        document["integrity"] = run._journal_digest(document)
        path.write_text(json.dumps(document, sort_keys=True, separators=(",", ":")), encoding="utf-8")
        lines = (run.run / m.JOURNAL).read_text(encoding="ascii").splitlines()
        lines[-1] = f'{document["generation"]:08d} {document["integrity"]}'
        (run.run / m.JOURNAL).write_text("\n".join(lines) + "\n", encoding="ascii")

    @staticmethod
    def matrix_result():
        return {
            "checks": [
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
            ],
            "exchangePosts": 11,
            "hostileForms": 6,
            "opaqueNetworkBlocks": 2,
            "expectedSandboxInstrumentationErrors": 2,
            "controlledNetworkEntries": 14,
            "credentialMaterialRecorded": False,
            "screenshotsContainingCredentials": 0,
            "fullBusinessPostcondition": True,
            "historyObservations": [
                {"phase": "after-logout-back", "documentResponseObserved": True,
                 "summaryDOMVisible": False},
                {"phase": "after-recovery-back", "documentResponseObserved": False,
                 "summaryDOMVisible": True},
            ],
            "immediateDeliveredDOMRemovalClaimed": False,
        }

    @staticmethod
    def browser_config(browser="C:/approved/chrome.exe"):
        return {
            "browser": {
                "contextOptions": {"offline": True, "serviceWorkers": "block"},
                "isolated": True,
                "launchOptions": {
                    "args": list(m.BROWSER_LAUNCH_ARGS),
                    "executablePath": browser,
                    "headless": True,
                },
                "timeouts": {"action": 30000, "navigation": 30000},
            },
        }

    @staticmethod
    def candidate_config():
        tool_keys = (
            "php", "python", "node", "powershell", "cli", "browser", "ini",
            "browser_config", "cert", "key",
        )
        filenames = {
            "ini": "runtime.ini", "browser_config": "browser-config.json",
            "cert": "cert.pem", "key": "key.pem",
        }
        run = (Path.cwd() / ("oncam-checkout-" + "a" * 32)).absolute()
        paths = {
            name: str((run / filenames[name]).absolute()) if name in filenames
            else str((Path.cwd() / f"synthetic-{name}").absolute())
            for name in tool_keys
        }
        return {
            "directory": str(run),
            "manifest": "a" * 64,
            **paths,
            "tool_hashes": {name: "b" * 64 for name in tool_keys},
            "asset_delivery_review": {name: "c" * 64 for name in m.ASSET_REVIEW_FILES},
        }

    def test_candidate_config_shape_is_exact_before_filesystem_or_identity(self):
        valid = self.candidate_config()
        run = m.WindowsRun(valid)
        self.assertEqual(run._validate_candidate_config(), valid["asset_delivery_review"])

        invalid = []
        for key in valid:
            candidate = json.loads(json.dumps(valid))
            candidate.pop(key)
            invalid.append((f"missing-{key}", candidate))
        extra = json.loads(json.dumps(valid)); extra["extra"] = True
        invalid.append(("extra", extra))
        for key in valid["tool_hashes"]:
            candidate = json.loads(json.dumps(valid))
            candidate["tool_hashes"].pop(key)
            invalid.append((f"missing-hash-{key}", candidate))
        extra_hash = json.loads(json.dumps(valid)); extra_hash["tool_hashes"]["extra"] = "d" * 64
        invalid.append(("extra-hash", extra_hash))
        for label, value in (
            ("manifest-bool", True), ("manifest-upper", "A" * 64),
            ("manifest-short", "a" * 63),
        ):
            candidate = json.loads(json.dumps(valid)); candidate["manifest"] = value
            invalid.append((label, candidate))
        for label, value in (
            ("hash-bool", True), ("hash-upper", "B" * 64), ("hash-short", "b" * 63),
        ):
            candidate = json.loads(json.dumps(valid)); candidate["tool_hashes"]["php"] = value
            invalid.append((label, candidate))
        for label, value in (
            ("path-empty", ""), ("path-relative", "relative/php"),
            ("path-bool", True), ("path-nul", "C:/php\0hidden"),
        ):
            candidate = json.loads(json.dumps(valid)); candidate["php"] = value
            invalid.append((label, candidate))
        php_path = valid["php"]
        php_parent = str(Path(php_path).parent)
        php_name = Path(php_path).name
        for label, value in (
            ("path-dot", php_parent + "\\.\\" + php_name),
            ("path-dot-dot", php_parent + "\\child\\..\\" + php_name),
            ("path-forward-slash", php_path.replace("\\", "/")),
            ("path-duplicate-separator", php_parent + "\\\\" + php_name),
        ):
            candidate = json.loads(json.dumps(valid)); candidate["php"] = value
            invalid.append((label, candidate))
        directory = json.loads(json.dumps(valid)); directory["directory"] = str(Path(valid["directory"]).with_name("other"))
        invalid.append(("directory-drift", directory))
        duplicate_path = json.loads(json.dumps(valid)); duplicate_path["python"] = duplicate_path["php"]
        invalid.append(("duplicate-path", duplicate_path))
        local_name = json.loads(json.dumps(valid)); local_name["ini"] = str(Path(valid["directory"]) / "other.ini")
        invalid.append(("local-name", local_name))
        review = json.loads(json.dumps(valid)); review["asset_delivery_review"].pop(m.ASSET_REVIEW_FILES[0])
        invalid.append(("asset-review", review))

        for label, candidate in invalid:
            with self.subTest(label=label):
                candidate_run = m.WindowsRun(valid)
                candidate_run.c = candidate
                with patch.object(candidate_run, "_canonical", side_effect=AssertionError("filesystem")), \
                        patch.object(candidate_run, "_identity", side_effect=AssertionError("identity")), \
                        patch.object(Path, "read_bytes", side_effect=AssertionError("read")), \
                        patch.object(m.subprocess, "Popen", side_effect=AssertionError("spawn")):
                    with self.assertRaisesRegex(m.Refused, "^candidate_config$"):
                        candidate_run.preflight(1)

        directory_path = valid["directory"]
        directory_parent = str(Path(directory_path).parent)
        directory_name = Path(directory_path).name
        for label, alias in (
            ("directory-self-dot-dot", directory_parent + "\\child\\..\\" + directory_name),
            ("directory-self-forward-slash", directory_path.replace("\\", "/")),
            ("directory-self-duplicate-separator", directory_parent + "\\\\" + directory_name),
        ):
            candidate = json.loads(json.dumps(valid))
            candidate["directory"] = alias
            candidate_run = m.WindowsRun(candidate)
            with self.subTest(label=label), \
                    patch.object(candidate_run, "_canonical", side_effect=AssertionError("filesystem")), \
                    patch.object(Path, "read_bytes", side_effect=AssertionError("read")), \
                    patch.object(m.subprocess, "Popen", side_effect=AssertionError("spawn")):
                with self.assertRaisesRegex(m.Refused, "^candidate_config$"):
                    candidate_run.preflight(1)

    def test_candidate_config_shape_guards_binding_and_recovery_before_anchor_load(self):
        valid = self.candidate_config()
        run = m.WindowsRun(valid, anchor_publisher=AnchorStore())
        session = "checkout-" + "c" * 32
        original_binding = run._config_binding(session)
        run.c["asset_delivery_review"][m.ASSET_REVIEW_FILES[0]] = "d" * 64
        self.assertNotEqual(run._config_binding(session), original_binding)

        run.c = json.loads(json.dumps(valid))
        run.c["tool_hashes"]["php"] = "B" * 64
        with self.assertRaisesRegex(m.Refused, "^candidate_config$"):
            run._config_binding(session)
        with patch.object(run, "_publisher_anchor", side_effect=AssertionError("anchor")), \
                patch.object(run, "_snapshot", side_effect=AssertionError("snapshot")), \
                patch.object(m.subprocess, "Popen", side_effect=AssertionError("spawn")):
            with self.assertRaisesRegex(m.Refused, "^recovery_config$"):
                run.recover_ownership(
                    session,
                    {"generation": 1, "digest": "d" * 64},
                )

    def test_browser_config_decoder_accepts_only_exact_semantic_schema(self):
        browser = "C:/approved/chrome.exe"
        valid = self.browser_config(browser)
        encoded = json.dumps(valid, sort_keys=True, indent=2).encode("utf-8")
        self.assertEqual(m._validated_browser_config(encoded, browser), valid["browser"])

        invalid_documents = []
        for path, key, value in (
            ((), "projects", []),
            (("browser",), "use", {}),
            (("browser",), "options", {}),
            (("browser", "contextOptions"), "proxy", {"server": "http://127.0.0.1"}),
            (("browser", "launchOptions"), "proxy", {"server": "http://127.0.0.1"}),
            (("browser", "timeouts"), "expect", 30000),
        ):
            candidate = json.loads(json.dumps(valid))
            target = candidate
            for part in path:
                target = target[part]
            target[key] = value
            invalid_documents.append(candidate)
        for path, key in (
            ((), "browser"),
            (("browser",), "contextOptions"),
            (("browser",), "isolated"),
            (("browser",), "launchOptions"),
            (("browser",), "timeouts"),
            (("browser", "contextOptions"), "offline"),
            (("browser", "contextOptions"), "serviceWorkers"),
            (("browser", "launchOptions"), "args"),
            (("browser", "launchOptions"), "executablePath"),
            (("browser", "launchOptions"), "headless"),
            (("browser", "timeouts"), "action"),
            (("browser", "timeouts"), "navigation"),
        ):
            candidate = json.loads(json.dumps(valid))
            target = candidate
            for part in path:
                target = target[part]
            target.pop(key)
            invalid_documents.append(candidate)
        for mutation in (
            lambda c: c["browser"].__setitem__("isolated", 1),
            lambda c: c["browser"]["contextOptions"].__setitem__("offline", 1),
            lambda c: c["browser"]["contextOptions"].__setitem__("serviceWorkers", "allow"),
            lambda c: c["browser"]["launchOptions"].__setitem__("headless", 1),
            lambda c: c["browser"]["launchOptions"].__setitem__("executablePath", browser + ".other"),
            lambda c: c["browser"]["launchOptions"].__setitem__("args", list(m.BROWSER_LAUNCH_ARGS)[::-1]),
            lambda c: c["browser"]["timeouts"].__setitem__("action", True),
            lambda c: c["browser"]["timeouts"].__setitem__("navigation", 29999),
        ):
            candidate = json.loads(json.dumps(valid))
            mutation(candidate)
            invalid_documents.append(candidate)
        for candidate in invalid_documents:
            with self.subTest(candidate=candidate), self.assertRaisesRegex(m.Refused, "^browser_config$"):
                m._validated_browser_config(json.dumps(candidate).encode("utf-8"), browser)

        compact = json.dumps(valid, separators=(",", ":")).encode("utf-8")
        malformed = (
            b"null", b"[]", b'"scalar"', b"{} {}", b"{", b'{"browser":NaN}',
            b'{"browser":Infinity}', b"\xff", b" " * 65537,
            b'{"browser":{},"browser":{}}',
            b'{"browser":{"contextOptions":{},"contextOptions":{}}}',
            b'{"browser":{"contextOptions":{"offline":true,"offline":false}}}',
            compact.replace(b'"headless":true', b'"headless":true,"headless":true'),
            compact.replace(b'"action":30000', b'"action":30000,"action":30000'),
        )
        for raw in malformed:
            with self.subTest(raw=raw[:80]), self.assertRaisesRegex(m.Refused, "^browser_config$"):
                m._validated_browser_config(raw, browser)

        with TemporaryDirectory(prefix="oncam-browser-config-") as directory:
            path = Path(directory) / "browser-config.json"
            path.write_bytes(encoded)
            self.assertEqual(
                m._read_validated_browser_config(
                    path, hashlib.sha256(encoded).hexdigest(), browser, Path(directory)
                ),
                valid["browser"],
            )
            original_validator = m._validated_browser_config

            def mutate_after_parse(raw, expected):
                result = original_validator(raw, expected)
                path.write_bytes(raw.replace(b"30000", b"30001", 1))
                return result

            with patch.object(m, "_validated_browser_config", side_effect=mutate_after_parse):
                with self.assertRaisesRegex(m.Refused, "^browser_config$"):
                    m._read_validated_browser_config(
                        path, hashlib.sha256(encoded).hexdigest(), browser, Path(directory)
                    )

    def test_browser_launch_revalidates_config_before_intent_or_cli(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            config = self.browser_config(run.c["browser"])
            config["browser"]["contextOptions"]["proxy"] = {
                "server": "http://127.0.0.1:9999"
            }
            raw = json.dumps(config).encode("utf-8")
            Path(run.c["browser_config"]).write_bytes(raw)
            run.c["tool_hashes"]["browser_config"] = hashlib.sha256(raw).hexdigest()
            run._cli = lambda args, remaining: self.fail("must not invoke CLI")
            with patch.object(m.subprocess, "Popen", side_effect=AssertionError("must not spawn")):
                with self.assertRaisesRegex(m.Refused, "^browser_config$"):
                    run.launch("browser", 1)
            self.assertEqual(run.launch_intents, [])

    def test_malformed_pinned_browser_config_preflight_leaves_no_claim_or_spawn(self):
        with TemporaryDirectory(prefix="oncam-preflight-root-") as temp_root:
            root = Path(temp_root)
            run_path = root / ("oncam-checkout-" + "a" * 32)
            run_path.mkdir()
            source = run_path / "source"
            source.mkdir()
            for name in ("browser.sqlite", "baseline.json", "fixtures.json"):
                (run_path / name).write_bytes(b"synthetic")
            review = {}
            for index, name in enumerate(m.ASSET_REVIEW_FILES):
                path = source / Path(name)
                path.parent.mkdir(parents=True, exist_ok=True)
                value = f"asset-{index}".encode()
                path.write_bytes(value)
                review[name] = hashlib.sha256(value).hexdigest()
            manifest_bytes = json.dumps(review, sort_keys=True, separators=(",", ":")).encode()
            (run_path / "source-manifest.json").write_bytes(manifest_bytes)
            paths = {}
            local_names = {"ini": "runtime.ini", "cert": "cert.pem", "key": "key.pem"}
            for name in ("php", "python", "node", "powershell", "ini", "cert", "key"):
                path = run_path / local_names.get(name, name)
                path.write_bytes(name.encode())
                paths[name] = str(path.absolute())
            cli = run_path / Path(m.CLI_SUFFIX)
            browser_path = run_path / Path(m.BROWSER_SUFFIX)
            cli.parent.mkdir(parents=True)
            browser_path.parent.mkdir(parents=True)
            cli.write_bytes(b"cli")
            browser_path.write_bytes(b"browser")
            paths["cli"], paths["browser"] = str(cli.absolute()), str(browser_path.absolute())
            malformed_config = self.browser_config(paths["browser"])
            malformed_config["browser"]["projects"] = []
            browser_bytes = json.dumps(malformed_config).encode()
            browser_config = run_path / "browser-config.json"
            browser_config.write_bytes(browser_bytes)
            paths["browser_config"] = str(browser_config.absolute())
            config = {
                "directory": str(run_path.absolute()),
                "manifest": hashlib.sha256(manifest_bytes).hexdigest(),
                **paths,
                "tool_hashes": {
                    name: hashlib.sha256(Path(path).read_bytes()).hexdigest()
                    for name, path in paths.items()
                },
                "asset_delivery_review": review,
            }
            run = m.WindowsRun(config)
            with patch.object(m.tempfile, "gettempdir", return_value=str(root)), \
                    patch.object(run, "_identity", side_effect=AssertionError("identity")), \
                    patch.object(m.subprocess, "Popen", side_effect=AssertionError("spawn")):
                with self.assertRaisesRegex(m.Refused, "^browser_config$"):
                    run.preflight(1)
            self.assertFalse((run_path / "supervisor.json").exists())
            self.assertFalse((run_path / m.JOURNAL).exists())
            self.assertEqual(run.launch_intents, [])

    def test_browser_launch_args_require_exact_canonical_ordered_list(self):
        approved = [
            "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND",
            "--no-proxy-server",
            "--disable-background-networking",
        ]
        self.assertIsNone(m.WindowsRun._validate_browser_launch_args(approved))
        invalid = (
            tuple(approved),
            approved[::-1],
            approved + ["--incognito"],
            approved + [approved[1]],
            [approved[0], "--proxy-server=http://127.0.0.1:8080", approved[2]],
            [approved[0].replace("127.0.0.1", "127.0.0.2", 1), *approved[1:]],
            [approved[0], approved[1], True],
            [approved[0], approved[1]],
            None,
        )
        for args in invalid:
            with self.subTest(args=args), self.assertRaisesRegex(m.Refused, "^browser_network_guard$"):
                m.WindowsRun._validate_browser_launch_args(args)

    def test_tool_suffix_requires_a_path_component_boundary(self):
        cases = (
            ("C:\\approved\\" + m.CLI_SUFFIX.replace("/", "\\"), m.CLI_SUFFIX, True),
            ("/approved/" + m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, True),
            ("C:\\evil" + m.CLI_SUFFIX.replace("/", "\\"), m.CLI_SUFFIX, False),
            ("/evil" + m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, False),
            (m.CLI_SUFFIX, m.CLI_SUFFIX, False),
            (m.BROWSER_SUFFIX, m.BROWSER_SUFFIX, False),
        )
        for path, suffix, expected in cases:
            with self.subTest(path=path):
                self.assertEqual(m._approved_tool_path(path, suffix), expected)

    def test_snapshot_schema_is_strict_before_relevance_filtering(self):
        executable = str((Path.cwd() / "synthetic.exe").absolute())
        valid = [
            {"pid": 10, "parent": 1, "started": "100", "executable": executable},
            {"pid": 11, "parent": 10, "started": "101", "executable": executable},
            {"pid": 99, "parent": 1, "started": None, "executable": None},
        ]
        current = m.WindowsRun._validate_snapshot(valid, {10})
        self.assertEqual(set(current), {10, 11, 99})
        invalid = (
            None,
            {},
            ["row"],
            [{"pid": 10, "parent": 1, "started": "100"}],
            [{"pid": 10, "parent": 1, "started": "100", "executable": executable, "extra": 1}],
            [{"pid": True, "parent": 1, "started": "100", "executable": executable}],
            [{"pid": 10, "parent": False, "started": "100", "executable": executable}],
            [{"pid": 0, "parent": 1, "started": "100", "executable": executable}],
            [{"pid": 10, "parent": -1, "started": "100", "executable": executable}],
            [{"pid": 10, "parent": 1, "started": True, "executable": executable}],
            [{"pid": 10, "parent": 1, "started": "", "executable": executable}],
            [{"pid": 10, "parent": 1, "started": "0", "executable": executable}],
            [{"pid": 10, "parent": 1, "started": "01", "executable": executable}],
            [{"pid": 10, "parent": 1, "started": "100", "executable": "relative.exe"}],
            [{"pid": 10, "parent": 1, "started": "100", "executable": executable + "\0hidden"}],
            [{"pid": 10, "parent": 1, "started": None, "executable": executable}],
            [{"pid": 10, "parent": 1, "started": "100", "executable": None}],
            [valid[0], {**valid[0], "parent": 2}],
            [{"pid": 10, "parent": 1, "started": None, "executable": None}],
            [valid[0], {"pid": 11, "parent": 10, "started": None, "executable": None}],
        )
        for rows in invalid:
            with self.subTest(rows=rows), self.assertRaisesRegex(m.Refused, "^snapshot_schema$"):
                m.WindowsRun._validate_snapshot(rows, {10})

    def test_snapshot_script_commits_identity_pair_and_resets_it_with_finally_dispose(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        scripts = []
        run._ps = lambda script: scripts.append(script) or "[]"
        self.assertEqual(run._snapshot(), [])
        script = scripts[0]
        self.assertIn("$candidateTick=$p.StartTime.ToUniversalTime().Ticks.ToString()", script)
        self.assertIn("$candidateExecutable=$p.MainModule.FileName", script)
        self.assertIn("$t=$candidateTick; $x=$candidateExecutable", script)
        self.assertLess(script.index("$candidateExecutable="), script.index("$t=$candidateTick"))
        self.assertIn("catch {$t=$null; $x=$null}", script)
        self.assertIn("finally {if($null -ne $p){$p.Dispose()}}", script)

    def test_identity_rejects_zero_and_leading_zero_ticks(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        for tick in ("0", "01"):
            with self.subTest(tick=tick):
                run._ps = lambda script, tick=tick: tick
                with self.assertRaisesRegex(m.Refused, "^identity$"):
                    run._identity(10)

    def test_claim_creates_integrity_bound_empty_journal_and_browser_intent_precedes_cli(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            journal = run._read_journal()
            self.assertEqual(journal["owned"], [])
            self.assertEqual(journal["launchIntents"], [])
            self.assertEqual(journal["session"], run.session)

            observed = []
            run._cli = lambda args, remaining: observed.append(run._read_journal()["launchIntents"]) or (_ for _ in ()).throw(m.Refused("synthetic"))
            with self.assertRaisesRegex(m.Refused, "synthetic"):
                run.launch("browser", 1)
            self.assertEqual(observed, [[{"role": "browser", "executable": run.c["browser"]}]])

    def test_claim_without_publisher_refuses_before_claim_journal_or_popen(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            baseline = self.journal_run(directory)
            run = m.WindowsRun(baseline.c)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^anchor_publisher$"):
                    run.claim(1)
                popen.assert_not_called()
            self.assertFalse((Path(directory) / "supervisor.json").exists())
            self.assertFalse((Path(directory) / m.JOURNAL).exists())
            self.assertEqual(list(Path(directory).glob(m.JOURNAL_PREFIX + "*.json")), [])

    def test_anchor_publisher_bind_is_attach_once_and_normal_path_claims(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            baseline = self.journal_run(directory)
            run = m.WindowsRun(baseline.c)
            publisher = self.publisher(directory)
            self.assertIsNone(run.bind_anchor_publisher(publisher))
            self.assertIs(run.anchor_publisher, publisher)
            with self.assertRaisesRegex(m.Refused, "^anchor_publisher_bind$"):
                run.bind_anchor_publisher(publisher)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            self.assertEqual(publisher.load(), run.journal_anchor())

    def test_anchor_publisher_bind_rejects_wrong_type_or_phase(self):
        for publisher in (None, object(), lambda anchor: None):
            with self.subTest(publisher=publisher):
                run = m.WindowsRun({"directory": "synthetic-unused"})
                with self.assertRaisesRegex(m.Refused, "^anchor_publisher_bind$"):
                    run.bind_anchor_publisher(publisher)
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.lifecycle_phase = "recovery"
        with self.assertRaisesRegex(m.Refused, "^anchor_publisher_bind$"):
            run.bind_anchor_publisher(StaticPublisher())

    def test_anchor_publisher_alias_drift_blocks_claim_before_artifacts_or_popen(self):
        for replacement in (None, StaticPublisher()):
            with self.subTest(replacement=replacement), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                baseline = self.journal_run(directory)
                run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(directory))
                run.anchor_publisher = replacement
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                        run.claim(1)
                    popen.assert_not_called()
                self.assertFalse((Path(directory) / "supervisor.json").exists())

    def test_publisher_callable_drift_before_claim_blocks_before_artifacts(self):
        for drift in ("instance_load", "class_publish"):
            with self.subTest(drift=drift), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                baseline = self.journal_run(directory)

                class Publisher(AnchorStore):
                    pass

                publisher = Publisher()
                run = m.WindowsRun(baseline.c, anchor_publisher=publisher)
                if drift == "instance_load":
                    publisher.load = lambda: None
                else:
                    Publisher.__call__ = lambda self, anchor: None
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                        run.claim(1)
                    popen.assert_not_called()
                self.assertFalse((Path(directory) / "supervisor.json").exists())

    def test_publisher_callable_drift_blocks_command_or_launch_before_popen(self):
        for operation, drift in (
            ("command", "class_publish_during_publish"),
            ("command", "instance_load_during_load"),
            ("launch", "instance_load_during_publish"),
        ):
            with self.subTest(operation=operation, drift=drift), \
                    TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                baseline = self.journal_run(directory)

                class Publisher(AnchorStore):
                    active = False

                    def __call__(self, anchor):
                        super().__call__(anchor)
                        if self.active and drift == "class_publish_during_publish":
                            Publisher.__call__ = lambda self, value: None
                        if self.active and drift == "instance_load_during_publish":
                            self.load = lambda: dict(self.latest)

                    def load(self):
                        anchor = super().load()
                        if self.active and drift == "instance_load_during_load":
                            self.load = lambda: dict(self.latest)
                        return anchor

                publisher = Publisher()
                run = m.WindowsRun(baseline.c, anchor_publisher=publisher)
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)
                publisher.active = True
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                        if operation == "command":
                            run._command([run.c["php"], "synthetic"], 1, policy="owned")
                        else:
                            run.launch("php", 1)
                    popen.assert_not_called()

    def test_claim_rejects_noop_stale_malformed_or_failing_publisher_acknowledgement(self):
        cases = (
            StaticPublisher(None),
            StaticPublisher({"generation": 1, "digest": "b" * 64}),
            StaticPublisher("malformed"),
            StaticPublisher(error=RuntimeError),
        )
        for publisher in cases:
            with self.subTest(value=publisher.value, error=publisher.error), \
                    TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                baseline = self.journal_run(directory)
                run = m.WindowsRun(baseline.c, anchor_publisher=publisher)
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^anchor_publish$"):
                        run.claim(1)
                    popen.assert_not_called()
                self.assertEqual(run.lifecycle_phase, "claiming")
                self.assertEqual(len(publisher.calls), 1)

    def test_publisher_alias_drift_blocks_command_and_direct_launch_before_popen(self):
        for operation in ("command", "launch"):
            for replacement in (None, StaticPublisher()):
                with self.subTest(operation=operation, replacement=replacement), \
                        TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                    run = self.journal_run(directory)
                    run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                    run.session = "checkout-" + "c" * 32
                    run.claim(1)
                    run.anchor_publisher = replacement
                    with patch.object(m.subprocess, "Popen") as popen:
                        with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                            if operation == "command":
                                run._command([run.c["php"], "synthetic"], 1, policy="owned")
                            else:
                                run.launch("php", 1)
                        popen.assert_not_called()
                    expected_intent = {
                        "role": "command" if operation == "command" else "php",
                        "executable": run.c["php"],
                    }
                    self.assertEqual(run.launch_intents, [expected_intent])
                    self.assertEqual(run._read_journal()["launchIntents"], [])

    def test_publisher_alias_drift_during_publish_or_reload_blocks_before_popen(self):
        for stage in ("publish", "load"):
            with self.subTest(stage=stage), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                baseline = self.journal_run(directory)

                class DriftingPublisher(AnchorStore):
                    active = False
                    run = None

                    def __call__(self, anchor):
                        super().__call__(anchor)
                        if self.active and stage == "publish":
                            self.run.anchor_publisher = StaticPublisher()

                    def load(self):
                        anchor = super().load()
                        if self.active and stage == "load":
                            self.run.anchor_publisher = StaticPublisher()
                        return anchor

                publisher = DriftingPublisher()
                run = m.WindowsRun(baseline.c, anchor_publisher=publisher)
                publisher.run = run
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)
                publisher.active = True
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                        run._command([run.c["php"], "synthetic"], 1, policy="owned")
                    popen.assert_not_called()

    def test_stale_bound_publisher_ack_blocks_command_before_popen(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run.anchor_publisher.frozen = True
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^anchor_publish$"):
                    run._command([run.c["php"], "synthetic"], 1, policy="owned")
                popen.assert_not_called()
            self.assertEqual(run._read_journal()["launchIntents"], [{
                "role": "command",
                "executable": run.c["php"],
            }])

    def test_reopened_publisher_ack_allows_normal_claim_and_owned_command(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)

            class Process:
                pid = 10
                returncode = 0

                def poll(self): return 0

            run._identity = lambda pid: {"pid": pid, "started": "110"}
            run._discover = lambda: {}
            with patch.object(m.subprocess, "Popen", return_value=Process()) as popen:
                self.assertEqual(run._command([run.c["php"], "synthetic"], 1, policy="owned"), "")
                popen.assert_called_once()
            self.assertEqual(run.anchor_publisher.load(), run.journal_anchor())
            self.assertEqual(run.launch_intents, [])

    def test_journal_persists_exact_lineage_and_rejects_tampering(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])
            journal = run._read_journal()
            self.assertEqual(journal["owned"], [{
                "pid": 10, "started": "110", "role": "php",
                "parent": {"pid": 7, "started": "100"}, "executable": run.c["php"],
            }])

            path = sorted(Path(directory).glob(m.JOURNAL_PREFIX + "*.json"))[-1]
            raw = json.loads(path.read_text(encoding="utf-8"))
            raw["owned"][0]["started"] = "999"
            path.write_text(json.dumps(raw), encoding="utf-8")
            with self.assertRaisesRegex(m.Refused, "^journal_integrity$"):
                run._read_journal()

    def test_rechained_published_claim_rejects_noncanonical_owner_tick(self):
        for tick in ("0", "01"):
            with self.subTest(tick=tick), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                run = self.journal_run(directory)
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)
                claim_path = run.run / "supervisor.json"
                claim = json.loads(claim_path.read_text(encoding="utf-8"))
                claim["owner"]["started"] = tick
                claim_path.write_text(json.dumps(claim, sort_keys=True, separators=(",", ":")), encoding="utf-8")
                generation_path = sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1]
                document = json.loads(generation_path.read_text(encoding="utf-8"))
                document["claimDigest"] = run._journal_digest(claim)
                document.pop("integrity")
                document["integrity"] = run._journal_digest(document)
                generation_path.write_text(json.dumps(document, sort_keys=True, separators=(",", ":")), encoding="utf-8")
                (run.run / m.JOURNAL).write_text(
                    f'{document["generation"]:08d} {document["integrity"]}\n', encoding="ascii"
                )
                anchor = {"generation": document["generation"], "digest": document["integrity"]}
                run.anchor_publisher.latest = anchor
                with self.assertRaisesRegex(m.Refused, "^journal_claim$"):
                    self.journal_run(directory)._read_journal(anchor)

    def test_rechained_published_journal_rejects_noncanonical_owned_or_parent_tick(self):
        for field in ("owned", "parent"):
            for tick in ("0", "01"):
                with self.subTest(field=field, tick=tick), \
                        TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                    run = self.journal_run(directory)
                    run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                    run.session = "checkout-" + "c" * 32
                    run.claim(1)
                    run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])

                    def mutate(document):
                        target = document["owned"][0]
                        if field == "parent":
                            target = target["parent"]
                        target["started"] = tick

                    self.rewrite_journal(run, mutate)
                    document = json.loads(sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1].read_text())
                    anchor = {"generation": document["generation"], "digest": document["integrity"]}
                    run.anchor_publisher.latest = anchor
                    with self.assertRaisesRegex(m.Refused, "^journal_shape$"):
                        self.journal_run(directory)._read_journal(anchor)

    def test_recovery_requires_exact_session_config_executable_tick_and_parent(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])
            run._register_owned({"pid": 11, "started": "120"}, "descendant",
                                {"pid": 10, "started": "110"}, run.c["php"])
            rows = [
                {"pid": 10, "parent": 7, "started": "110", "executable": run.c["php"]},
                {"pid": 11, "parent": 10, "started": "120", "executable": run.c["php"]},
            ]
            anchor = run.journal_anchor()

            recovered = self.journal_run(directory)
            recovered._snapshot = lambda: rows
            recovered.recover_ownership(run.session, anchor)
            self.assertEqual(recovered.owned, {10: "110", 11: "120"})

            missing_parent = self.journal_run(directory)
            missing_parent._snapshot = lambda: rows[1:]
            with self.assertRaisesRegex(m.Refused, "^recovery_parent$"):
                missing_parent.recover_ownership(run.session, anchor)

            cases = {
                "recovery_session": ("checkout-" + "d" * 32, rows, None),
                "recovery_parent": (run.session, [{**rows[0], "parent": 8}, rows[1]], None),
                "recovery_identity": (run.session, [{**rows[0], "started": "999"}, rows[1]], None),
                "recovery_executable": (run.session, [{**rows[0], "executable": str(Path(directory) / "other.exe")}, rows[1]], None),
                "recovery_config": (run.session, rows, "manifest"),
            }
            for reason, (session, snapshot, drift) in cases.items():
                with self.subTest(reason=reason):
                    candidate = self.journal_run(directory)
                    if drift:
                        candidate.c[drift] = "f" * 64
                    candidate._snapshot = lambda snapshot=snapshot: snapshot
                    with self.assertRaisesRegex(m.Refused, f"^{reason}$"):
                        candidate.recover_ownership(session, anchor)

    def test_recovery_refuses_active_owner_and_takes_its_own_snapshot(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])

            candidate = self.journal_run(directory)
            snapshots = [[
                {"pid": 7, "parent": 1, "started": "100", "executable": str(Path(directory) / "owner.exe")},
                {"pid": 10, "parent": 7, "started": "110", "executable": run.c["php"]},
            ]]
            candidate._snapshot = lambda: snapshots.pop()
            with self.assertRaisesRegex(m.Refused, "^recovery_owner_active$"):
                candidate.recover_ownership(run.session, run.journal_anchor())
            self.assertEqual(snapshots, [])

    def test_recovery_refuses_unresolved_launch_intent(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._push_launch_intent("php", run.c["php"])

            candidate = self.journal_run(directory)
            candidate._snapshot = lambda: []
            with self.assertRaisesRegex(m.Refused, "^recovery_incomplete$"):
                candidate.recover_ownership(run.session, run.journal_anchor())

    def test_recovery_requires_publisher_before_hydration_or_popen(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            candidate = m.WindowsRun(run.c)
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^anchor_publisher$"):
                    candidate.recover_ownership(run.session, run.journal_anchor())
                popen.assert_not_called()
            self.assertFalse(candidate.claimed)
            self.assertFalse(candidate.recovery_hydrated)

    def test_publisher_alias_drift_blocks_recovery_before_snapshot_or_hydration(self):
        for replacement in (None, StaticPublisher()):
            with self.subTest(replacement=replacement), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                run = self.journal_run(directory)
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)
                candidate = m.WindowsRun(run.c, anchor_publisher=run.anchor_publisher)
                candidate.anchor_publisher = replacement
                candidate._snapshot = lambda: self.fail("snapshot must not run")
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                        candidate.recover_ownership(run.session, run.journal_anchor())
                    popen.assert_not_called()
                self.assertFalse(candidate.recovery_hydrated)

    def test_publisher_load_implementation_drift_blocks_recovery_before_snapshot(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            candidate = m.WindowsRun(run.c, anchor_publisher=run.anchor_publisher)
            candidate.anchor_publisher.load = lambda: run.journal_anchor()
            candidate._snapshot = lambda: self.fail("snapshot must not run")
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^anchor_publisher_identity$"):
                    candidate.recover_ownership(run.session, run.journal_anchor())
                popen.assert_not_called()
            self.assertFalse(candidate.recovery_hydrated)

    def test_recovery_hydrates_before_census_and_publishes_helper_intent(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            observed = []

            def publish(anchor):
                reader = self.journal_run(directory)
                observed.append(reader._read_journal(anchor)["launchIntents"])

            candidate = m.WindowsRun(run.c, anchor_publisher=self.publisher(directory, publish))

            class Process:
                pid = 10
                returncode = 0

                def poll(self): return 0

            def popen(*args, **kwargs):
                kwargs["stdout"].write(b"[]")
                return Process()

            with patch.object(m.subprocess, "Popen", side_effect=popen):
                candidate.recover_ownership(run.session, run.journal_anchor())
            self.assertTrue(candidate.recovery_hydrated)
            self.assertTrue(candidate.recovery_validated)
            self.assertEqual(observed[-2:], [
                [{"role": "helper", "executable": run.c["powershell"]}],
                [],
            ])

    def test_recovery_census_interrupt_retains_helper_intent_and_blocks_new_instance(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            published = []
            store = self.publisher(directory, lambda anchor: published.append(dict(anchor)))
            candidate = m.WindowsRun(
                run.c, anchor_publisher=store
            )

            class Process:
                pid = 10
                returncode = 0

                def poll(self): raise KeyboardInterrupt()

            process = Process()
            with patch.object(m.subprocess, "Popen", return_value=process):
                with self.assertRaises(KeyboardInterrupt):
                    candidate.recover_ownership(run.session, run.journal_anchor())
            self.assertTrue(candidate.recovery_hydrated)
            self.assertFalse(candidate.recovery_validated)
            self.assertIn(process, candidate.handles)
            self.assertEqual(candidate.launch_intents, [
                {"role": "helper", "executable": run.c["powershell"]},
            ])
            self.assertTrue((Path(directory) / "integrity-invalid").is_file())
            retry = self.journal_run(directory)
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^recovery_incomplete$"):
                    retry.recover_ownership(run.session, store.load())
                popen.assert_not_called()

    def test_recovery_retry_requires_newest_anchor_after_completed_census(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            original_anchor = run.journal_anchor()
            published = []
            store = self.publisher(directory, lambda anchor: published.append(dict(anchor)))
            candidate = m.WindowsRun(
                run.c, anchor_publisher=store
            )

            class Process:
                pid = 10
                returncode = 0

                def poll(self): return 0

            def active_owner(*args, **kwargs):
                kwargs["stdout"].write(json.dumps([{
                    "pid": 7, "parent": 1, "started": "100",
                    "executable": str(Path(directory) / "owner.exe"),
                }]).encode())
                return Process()

            with patch.object(m.subprocess, "Popen", side_effect=active_owner):
                with self.assertRaisesRegex(m.Refused, "^recovery_owner_active$"):
                    candidate.recover_ownership(run.session, original_anchor)
            latest_anchor = store.load()
            stale = self.journal_run(directory)
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^journal_anchor$"):
                    stale.recover_ownership(run.session, original_anchor)
                popen.assert_not_called()

            retry = m.WindowsRun(run.c, anchor_publisher=store)
            def clean(*args, **kwargs):
                kwargs["stdout"].write(b"[]")
                return Process()
            with patch.object(m.subprocess, "Popen", side_effect=clean):
                retry.recover_ownership(run.session, latest_anchor)
            self.assertTrue(retry.recovery_validated)

    def test_every_tracked_popen_persists_intent_before_identity_registration(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)

            class Process:
                pid = 10
                returncode = 0

                def poll(self): return 1

            with patch.object(m.subprocess, "Popen", return_value=Process()), \
                    patch.object(run, "_identity", side_effect=RuntimeError("crash-before-identity")):
                with self.assertRaisesRegex(RuntimeError, "crash-before-identity"):
                    run._command([run.c["php"], "synthetic"], 1, policy="owned", role="command")
            self.assertEqual(run._read_journal()["launchIntents"], [
                {"role": "command", "executable": run.c["php"]},
            ])

            candidate = self.journal_run(directory)
            candidate._snapshot = lambda: []
            with self.assertRaisesRegex(m.Refused, "^recovery_incomplete$"):
                candidate.recover_ownership(run.session, run.journal_anchor())

    def test_close_cli_is_owned_while_ps_routes_by_claim_state(self):
        run = m.WindowsRun({"directory": "synthetic-unused", "node": "node.exe",
                            "cli": "playwright-cli.js", "powershell": "powershell.exe"})
        calls = []
        run._command = lambda args, timeout, **kwargs: calls.append((args, kwargs)) or ""
        run._cli(["close"], 1)
        run._ps("synthetic", timeout=1)
        run.claimed = True
        run._ps("synthetic", timeout=1)
        self.assertEqual(calls[0][1], {"policy": "owned", "role": "command"})
        self.assertEqual(calls[1][1], {"policy": "untracked", "role": "helper"})
        self.assertEqual(calls[2][1], {"policy": "intent_only", "role": "helper"})

    def test_tracking_policy_matrix_refuses_every_invalid_pair_before_intent_or_popen(self):
        invalid = (
            (False, "untracked", "command"),
            (False, "intent_only", "helper"),
            (False, "intent_only", "command"),
            (False, "owned", "command"),
            (False, "owned", "browser_launcher"),
            (False, "owned", "helper"),
            (True, "untracked", "helper"),
            (True, "untracked", "command"),
            (True, "intent_only", "command"),
            (True, "intent_only", "browser_launcher"),
            (True, "owned", "helper"),
            (True, "owned", "php"),
            (True, "unknown", "helper"),
            (True, None, "helper"),
        )
        for claimed, policy, role in invalid:
            with self.subTest(claimed=claimed, policy=policy, role=role):
                run = m.WindowsRun({"directory": "synthetic-unused"})
                run.claimed = claimed
                with patch.object(m.subprocess, "Popen") as popen:
                    with self.assertRaisesRegex(m.Refused, "^tracking_policy$"):
                        run._command(["unused"], 1, policy=policy, role=role)
                    popen.assert_not_called()
                self.assertEqual(run.launch_intents, [])

    def test_direct_launch_and_public_run_paths_refuse_preclaim_or_recovery_phase_before_popen(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            preclaim = self.journal_run(directory)
            operations = (
                lambda run: run.launch("php", 1),
                lambda run: run.harness("integrity-pre", 1),
                lambda run: run.assert_owned(1),
                lambda run: run._run_code("async()=>({})", 1),
            )
            for operation in operations:
                with self.subTest(phase="preclaim", operation=operation):
                    with patch.object(m.subprocess, "Popen") as popen:
                        with self.assertRaisesRegex(m.Refused, "^lifecycle_phase$"):
                            operation(preclaim)
                        popen.assert_not_called()

            preclaim.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            preclaim.session = "checkout-" + "c" * 32
            preclaim.claim(1)
            recovered = self.journal_run(directory)
            recovered._snapshot = lambda: []
            recovered.recover_ownership(preclaim.session, preclaim.journal_anchor())
            for operation in operations:
                with self.subTest(phase="recovery", operation=operation):
                    with patch.object(m.subprocess, "Popen") as popen:
                        with self.assertRaisesRegex(m.Refused, "^lifecycle_phase$"):
                            operation(recovered)
                        popen.assert_not_called()

    def test_postclaim_ps_success_publishes_intent_then_clears_without_recursive_discovery(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            observed = []

            def publish(anchor):
                candidate = self.journal_run(directory)
                candidate._read_journal(anchor)
                observed.append(candidate._read_journal()["launchIntents"])

            baseline = self.journal_run(directory)
            run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(directory, publish))
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)

            class Process:
                pid = 10
                returncode = 0

                def poll(self): return 0

            run._identity = lambda pid: (_ for _ in ()).throw(AssertionError("recursive identity"))
            run._discover = lambda: (_ for _ in ()).throw(AssertionError("recursive discovery"))
            with patch.object(m.subprocess, "Popen", return_value=Process()):
                self.assertEqual(run._ps("synthetic", timeout=1), "")
            self.assertEqual(observed[-2:], [[{"role": "helper", "executable": run.c["powershell"]}], []])
            self.assertEqual(run._read_journal()["launchIntents"], [])

    def test_postclaim_ps_publisher_failure_prevents_helper_popen(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            baseline = self.journal_run(directory)
            run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(
                directory,
                lambda anchor: (_ for _ in ()).throw(RuntimeError("offline"))
                if anchor["generation"] == 2 else None,
            ))
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^anchor_publish$"):
                    run._ps("synthetic", timeout=1)
                popen.assert_not_called()
            self.assertEqual(run._read_journal()["launchIntents"], [
                {"role": "helper", "executable": run.c["powershell"]},
            ])

    def test_postclaim_ps_failure_or_interrupt_retains_intent_handle_and_blocks_recovery(self):
        for outcome in ("exit", "timeout", "exception", "interrupt", "system_exit"):
            with self.subTest(outcome=outcome), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                published = []
                baseline = self.journal_run(directory)
                store = self.publisher(directory, lambda anchor: published.append(dict(anchor)))
                run = m.WindowsRun(
                    baseline.c,
                    anchor_publisher=store,
                )
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)

                class Process:
                    pid = 10
                    returncode = 1 if outcome == "exit" else 0

                    def __init__(self): self.calls = 0
                    def poll(self):
                        self.calls += 1
                        if outcome == "timeout": return None
                        if outcome == "exception": raise RuntimeError("poll")
                        if outcome == "interrupt": raise KeyboardInterrupt()
                        if outcome == "system_exit": raise SystemExit(3)
                        return 0
                    def kill(self): pass
                    def wait(self, timeout): return 0

                process = Process()
                if outcome == "timeout":
                    ticks = iter((0.0, 0.1, 0.2, 2.0, 2.1))
                    run.clock = lambda: next(ticks, 2.1)
                expected = {"interrupt": KeyboardInterrupt, "system_exit": SystemExit}.get(outcome, Exception)
                with patch.object(m.subprocess, "Popen", return_value=process):
                    with self.assertRaises(expected):
                        run._ps("synthetic", timeout=1)
                self.assertIn(process, run.handles)
                self.assertEqual(run._read_journal()["launchIntents"], [
                    {"role": "helper", "executable": run.c["powershell"]},
                ])
                candidate = self.journal_run(directory)
                candidate._snapshot = lambda: []
                with self.assertRaisesRegex(m.Refused, "^recovery_incomplete$"):
                    candidate.recover_ownership(run.session, store.load())

    def test_postclaim_ps_keeps_intent_until_output_is_validated(self):
        for outcome in ("stderr", "oversized", "malformed_utf8"):
            with self.subTest(outcome=outcome), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                baseline = self.journal_run(directory)
                run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(directory))
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)

                class Process:
                    pid = 10
                    returncode = 0

                    def poll(self): return 0

                process = Process()

                def popen(*args, **kwargs):
                    if outcome == "stderr":
                        kwargs["stderr"].write(b"refused")
                    elif outcome == "oversized":
                        kwargs["stdout"].write(b"x" * 262145)
                    else:
                        kwargs["stdout"].write(b"\xff")
                    return process

                with patch.object(m.subprocess, "Popen", side_effect=popen):
                    with self.assertRaises((m.Refused, UnicodeDecodeError)):
                        run._ps("synthetic", timeout=1)
                self.assertIn(process, run.handles)
                self.assertEqual(run._read_journal()["launchIntents"], [
                    {"role": "helper", "executable": run.c["powershell"]},
                ])

    def test_nested_browser_launcher_intent_does_not_overwrite_outer_browser_intent(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            outer = run._push_launch_intent("browser", run.c["browser"])
            inner = run._push_launch_intent("browser_launcher", run.c["node"])
            self.assertEqual(run._read_journal()["launchIntents"], [outer, inner])
            run._clear_launch_intent(inner)
            self.assertEqual(run._read_journal()["launchIntents"], [outer])
            run._clear_launch_intent(outer)
            self.assertEqual(run._read_journal()["launchIntents"], [])

    def test_clear_intent_restores_memory_after_persist_failure_and_next_generation_remains_incomplete(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            intent = run._push_launch_intent("helper", run.c["powershell"])
            persist = run._persist_journal
            with patch.object(run, "_persist_journal", side_effect=m.Refused("journal_write")):
                with self.assertRaisesRegex(m.Refused, "^journal_write$"):
                    run._clear_launch_intent(intent)
            self.assertEqual(run.launch_intents, [intent])
            persist()
            self.assertEqual(run._read_journal(run.journal_anchor())["launchIntents"], [intent])
            retry = self.journal_run(directory)
            with self.assertRaisesRegex(m.Refused, "^recovery_incomplete$"):
                retry.recover_ownership(run.session, run.journal_anchor())

    def test_stale_valid_generation_cannot_replace_current_journal(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            first = sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1].read_bytes()
            run._push_launch_intent("php", run.c["php"])
            latest = sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1]
            latest.write_bytes(first)
            with self.assertRaisesRegex(m.Refused, "^journal_integrity$"):
                run._read_journal()

    def test_external_latest_anchor_rejects_coordinated_suffix_and_head_rollback(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            old_head = (run.run / m.JOURNAL).read_text(encoding="ascii")
            run._push_launch_intent("php", run.c["php"])
            latest_anchor = run.journal_anchor()

            sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1].unlink()
            (run.run / m.JOURNAL).write_text(old_head, encoding="ascii")
            candidate = self.journal_run(directory)
            candidate._snapshot = lambda: []
            with self.assertRaisesRegex(m.Refused, "^journal_anchor$"):
                candidate.recover_ownership(run.session, latest_anchor)

    def test_recovery_anchor_requires_exact_shape_and_types(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            candidate = self.journal_run(directory)
            candidate._snapshot = lambda: []
            invalid = [None, {}, {"generation": True, "digest": "a" * 64},
                       {"generation": 1, "digest": "A" * 64},
                       {"generation": 1, "digest": "a" * 64, "extra": False}]
            for anchor in invalid:
                with self.subTest(anchor=anchor), self.assertRaisesRegex(m.Refused, "^journal_anchor$"):
                    candidate.recover_ownership(run.session, anchor)

    def test_publisher_receives_ordered_durable_anchor_copies(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            published = []

            def publish(anchor):
                self.assertTrue((Path(directory) / m.JOURNAL).is_file())
                self.assertEqual(len(list(Path(directory).glob(m.JOURNAL_PREFIX + "*.json"))),
                                 anchor["generation"])
                published.append(dict(anchor))
                original = dict(anchor)
                anchor["generation"] = 999  # Callback cannot mutate supervisor state.
                anchor.clear()
                anchor.update(original)

            baseline = self.journal_run(directory)
            run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(directory, publish))
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._push_launch_intent("php", run.c["php"])
            self.assertEqual([anchor["generation"] for anchor in published], [1, 2])
            self.assertEqual(run.journal_anchor(), published[-1])

    def test_publisher_failure_prevents_managed_popen_and_preserves_chain(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            delivered = []

            def publish(anchor):
                if anchor["generation"] == 2:
                    raise RuntimeError("publisher unavailable")
                delivered.append(dict(anchor))

            baseline = self.journal_run(directory)
            run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(directory, publish))
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^anchor_publish$"):
                    run._command([run.c["php"], "synthetic"], 1, policy="owned")
                popen.assert_not_called()
            self.assertEqual(run._read_journal()["launchIntents"], [
                {"role": "command", "executable": run.c["php"]},
            ])
            self.assertEqual(len(list(run.run.glob(m.JOURNAL_PREFIX + "*.json"))), 2)
            self.assertEqual(delivered, [{"generation": 1, "digest": delivered[0]["digest"]}])

    def test_reopened_publisher_reconciles_write_then_raise_to_new_anchor(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            baseline = self.journal_run(directory)
            store = self.publisher(directory)
            run = m.WindowsRun(baseline.c, anchor_publisher=store)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            old_anchor = store.load()

            def write_then_raise(anchor):
                store.latest = dict(anchor)
                raise RuntimeError("post-write lock failure")

            store.hook = write_then_raise
            with self.assertRaisesRegex(m.Refused, "^anchor_publish$"):
                run._push_launch_intent("helper", run.c["powershell"])
            self.assertEqual(store.load(), run.journal_anchor())

            reopened = self.journal_run(directory)
            with patch.object(m.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(m.Refused, "^journal_anchor$"):
                    reopened.recover_ownership(run.session, old_anchor)
                with self.assertRaisesRegex(m.Refused, "^recovery_incomplete$"):
                    reopened.recover_ownership(run.session, store.load())
                popen.assert_not_called()

    def test_publisher_failure_after_popen_retains_handle_and_never_yields_success(self):
        for error_type in (RuntimeError, KeyboardInterrupt, SystemExit):
            with self.subTest(error=error_type.__name__), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                delivered = []

                def publish(anchor):
                    if anchor["generation"] == 3:
                        raise error_type("publisher-window")
                    delivered.append(dict(anchor))

                baseline = self.journal_run(directory)
                run = m.WindowsRun(baseline.c, anchor_publisher=self.publisher(directory, publish))
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)

                class Process:
                    pid = 10
                    returncode = 0

                    def poll(self): return 1

                process = Process()
                run._identity = lambda pid: {"pid": pid, "started": "110"}
                expected = m.Refused if error_type is RuntimeError else error_type
                with patch.object(m.subprocess, "Popen", return_value=process):
                    with self.assertRaises(expected):
                        run._command([run.c["php"], "synthetic"], 1, policy="owned")
                self.assertIn(process, run.handles)
                self.assertEqual(run.journal_anchor()["generation"], 3)
                self.assertEqual(delivered[-1]["generation"], 2)
                candidate = self.journal_run(directory)
                candidate._snapshot = lambda: []
                with self.assertRaisesRegex(m.Refused, "^journal_anchor$"):
                    candidate.recover_ownership(run.session, delivered[-1])

                orchestration = Fake()

                def fail_launch(role, left):
                    orchestration.launched.append(role)
                    orchestration.call("launch-" + role)
                    if role == "php":
                        raise error_type("publisher-window")

                orchestration.launch = fail_launch
                if error_type is RuntimeError:
                    result = m.supervise(orchestration)
                    self.assertEqual(result["state"], "invalid")
                    self.assertFalse(result["accepted"])
                else:
                    with self.assertRaises(error_type):
                        m.supervise(orchestration)
                self.assertIn("cleanup", orchestration.calls)
                self.assertIn("invalid", orchestration.calls)

    def test_latest_published_anchor_supports_new_instance_recovery(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            published = []
            baseline = self.journal_run(directory)
            store = self.publisher(directory, lambda anchor: published.append(dict(anchor)))
            run = m.WindowsRun(
                baseline.c,
                anchor_publisher=store,
            )
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])

            candidate = self.journal_run(directory)
            candidate._snapshot = lambda: [
                {"pid": 10, "parent": 7, "started": "110", "executable": run.c["php"]},
            ]
            candidate.recover_ownership(run.session, store.load())
            self.assertEqual(candidate.owned, {10: "110"})

    def test_recovery_rejects_malformed_or_incomplete_claim_journal_pairs(self):
        def claimed(directory):
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            return run

        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = claimed(directory)
            sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1].write_text('{"truncated":', encoding="utf-8")
            with self.assertRaisesRegex(m.Refused, "^journal_read$"):
                run._read_journal()
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = claimed(directory)
            (run.run / m.JOURNAL).unlink()
            with self.assertRaisesRegex(m.Refused, "^journal_read$"):
                run._read_journal()
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = claimed(directory)
            (run.run / "supervisor.json").unlink()
            with self.assertRaisesRegex(m.Refused, "^journal_claim$"):
                run._read_journal()

    def test_recovery_rejects_duplicate_pid_or_singleton_role_even_with_valid_mac(self):
        for mutation in ("pid", "role"):
            with self.subTest(mutation=mutation), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                run = self.journal_run(directory)
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)
                run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])

                def duplicate(document):
                    clone = dict(document["owned"][0])
                    if mutation == "role":
                        clone["pid"], clone["started"] = 11, "120"
                    document["owned"].append(clone)

                self.rewrite_journal(run, duplicate)
                with self.assertRaisesRegex(m.Refused, "^journal_shape$"):
                    run._read_journal()

    def test_recovery_rejects_tool_hash_and_executable_config_drift(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            for mutate in (lambda candidate: candidate.c["tool_hashes"].__setitem__("php", "f" * 64),
                           lambda candidate: candidate.c.__setitem__("php", str(Path(directory) / "other-php"))):
                candidate = self.journal_run(directory)
                mutate(candidate)
                with self.assertRaisesRegex(m.Refused, "^recovery_config$"):
                    candidate.recover_ownership(run.session, run.journal_anchor())
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            Path(run.c["php"]).write_bytes(b"drifted-after-claim")
            candidate = self.journal_run(directory)
            candidate.c["tool_hashes"] = dict(run.c["tool_hashes"])
            with self.assertRaisesRegex(m.Refused, "^recovery_config$"):
                candidate.recover_ownership(run.session, run.journal_anchor())

    def test_journal_clears_only_when_final_exact_cleanup_is_allowed(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            run._discover = lambda: {}
            run._listeners = lambda: []
            self.assertTrue(run.cleanup(1))
            self.assertTrue((Path(directory) / m.JOURNAL).is_file())
            run.postcheck_complete = True  # Public mutation is not the private monotonic gate.
            self.assertTrue(run.cleanup(1))
            self.assertTrue((Path(directory) / m.JOURNAL).is_file())
            with self.assertRaisesRegex(m.Refused, "^harness$"):
                run._WindowsRun__accept_harness_result(
                    "integrity-post", {"state": "postverified", "accepted": True}
                )
            self.assertTrue((Path(directory) / m.JOURNAL).is_file())
            run._WindowsRun__accept_harness_result(
                "integrity-post", {"state": "postverified", "accepted": False}
            )
            self.assertTrue(run.cleanup(1))
            self.assertFalse((Path(directory) / m.JOURNAL).exists())

    def test_failed_or_interrupted_cleanup_cannot_clear_postchecked_journal(self):
        for outcome in ("failure", "interrupt"):
            with self.subTest(outcome=outcome), TemporaryDirectory(prefix="oncam-journal-test-") as directory:
                run = self.journal_run(directory)
                run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
                run.session = "checkout-" + "c" * 32
                run.claim(1)
                run._WindowsRun__accept_harness_result(
                    "integrity-post", {"state": "postverified", "accepted": False}
                )
                if outcome == "failure":
                    run._discover = lambda: {}
                    run._listeners = lambda: (_ for _ in ()).throw(RuntimeError("synthetic"))
                    self.assertFalse(run.cleanup(1))
                else:
                    run._discover = lambda: (_ for _ in ()).throw(KeyboardInterrupt())
                    with self.assertRaises(KeyboardInterrupt):
                        run.cleanup(1)
                self.assertTrue((Path(directory) / m.JOURNAL).is_file())

    def test_stale_prefix_cleanup_fails_before_any_journal_artifact_is_deleted(self):
        with TemporaryDirectory(prefix="oncam-journal-test-") as directory:
            run = self.journal_run(directory)
            run.owner = {"pid": 7, "started": "100", "nonce": "b" * 64}
            run.session = "checkout-" + "c" * 32
            run.claim(1)
            old_head = (run.run / m.JOURNAL).read_text(encoding="ascii")
            run._register_owned({"pid": 10, "started": "110"}, "php", run.owner, run.c["php"])
            suffix = sorted(run.run.glob(m.JOURNAL_PREFIX + "*.json"))[-1]
            suffix.unlink()
            (run.run / m.JOURNAL).write_text(old_head, encoding="ascii")
            run._discover = lambda: {}
            run._listeners = lambda: []
            run._WindowsRun__accept_harness_result(
                "integrity-post", {"state": "postverified", "accepted": False}
            )

            self.assertFalse(run.cleanup(1))
            self.assertEqual(run.uncertainty_categories, {"cleanup_exception"})
            self.assertTrue((run.run / "supervisor.json").is_file())
            self.assertTrue((run.run / m.JOURNAL).is_file())
            self.assertEqual(len(list(run.run.glob(m.JOURNAL_PREFIX + "*.json"))), 1)
            run.invalidate()
            self.assertTrue((run.run / "integrity-invalid").is_file())

    def test_recover_flow_invokes_persisted_recovery_cleanup_and_invalidation(self):
        class Recovery:
            uncertain = False

            def __init__(self):
                self.calls = []

            def clock(self): return 10
            def recover_ownership(self, session, anchor): self.calls.append(("recover", session, anchor))
            def cleanup(self, budget): self.calls.append(("cleanup", budget)); return True
            def invalidate(self): self.calls.append(("invalidate",))

        io = Recovery()
        anchor = {"generation": 3, "digest": "a" * 64}
        result = m.recover(io, session="checkout-" + "c" * 32, anchor=anchor, budget=12)
        self.assertEqual(result, {"state": "recovered_cleanup", "accepted": False})
        self.assertEqual(io.calls, [("recover", "checkout-" + "c" * 32, anchor),
                                    ("cleanup", 12), ("invalidate",)])

    def test_recover_invalidates_hydrated_failure_without_premature_cleanup(self):
        class Recovery:
            uncertain = False
            recovery_hydrated = False

            def __init__(self): self.calls = []
            def clock(self): return 10
            def recover_ownership(self, session, anchor):
                self.calls.append(("recover", session, anchor))
                self.recovery_hydrated = True
                raise KeyboardInterrupt()
            def cleanup(self, budget): self.calls.append(("cleanup", budget)); return True
            def invalidate(self): self.calls.append(("invalidate",))

        io = Recovery()
        anchor = {"generation": 3, "digest": "a" * 64}
        with self.assertRaises(KeyboardInterrupt):
            m.recover(io, session="checkout-" + "c" * 32, anchor=anchor, budget=12)
        self.assertEqual(io.calls, [
            ("recover", "checkout-" + "c" * 32, anchor),
            ("invalidate",),
        ])

    def test_primary_reason_and_cleanup_status_matrix_are_independent(self):
        cases = [
            ("preflight", True, False, "preflight", "not_required"),
            ("integrity-start", True, False, "start", "clean"),
            ("launch-browser", False, False, "spawn_browser", "failed"),
            ("integrity-start", True, True, "start", "uncertain"),
            (None, False, False, "cleanup", "failed"),
        ]
        for failure, clean, uncertain, primary, cleanup in cases:
            with self.subTest(failure=failure, cleanup=cleanup):
                f = Fake(fail=failure, cleanup=clean)
                if uncertain:
                    f.uncertain = True
                    f.uncertainty_categories = {"parent_missing"}
                result = m.supervise(f)
                self.assertEqual(result["primary_reason"], primary)
                self.assertEqual(result["reason"], primary)
                self.assertEqual(result["cleanup_status"], cleanup)
                self.assertEqual(result["uncertainty_categories"], ["parent_missing"] if uncertain else [])
                self.assertFalse(result["accepted"])

        smoke = m.supervise(Fake())
        self.assertEqual((smoke["primary_reason"], smoke["cleanup_status"]), ("fresh_run_required", "clean"))
        full = m.supervise(Fake(), mode="full")
        self.assertEqual((full["primary_reason"], full["cleanup_status"]), ("root_review_required", "clean"))
        untrusted = Fake(fail="integrity-start")
        untrusted.uncertain, untrusted.uncertainty_categories = True, {"PRIVATE_PAYLOAD"}
        result = m.supervise(untrusted)
        self.assertEqual(result["uncertainty_categories"], ["cleanup_exception"])
        self.assertNotIn("PRIVATE", str(result))

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
        self.assertEqual(result, dict(state="incomplete", accepted=False, requests=3,
            reason="fresh_run_required", primary_reason="fresh_run_required",
            cleanup_status="clean", uncertainty_categories=[]))
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
        self.assertEqual(result["reason"], "spawn_browser")
        self.assertEqual(result["cleanup_status"], "failed")
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
        executable = str((Path.cwd() / "synthetic.exe").absolute())
        run._snapshot = lambda: [{"pid": 10, "parent": 1, "started": "100", "executable": executable}, {"pid": 11, "parent": 10, "started": "101", "executable": executable}, {"pid": 12, "parent": 11, "started": "102", "executable": executable}]
        run._discover()
        self.assertEqual(run.owned, {10: "100", 11: "101", 12: "102"})
        run._snapshot = lambda: [{"pid": 10, "parent": 1, "started": "900", "executable": executable}, {"pid": 13, "parent": 10, "started": "901", "executable": executable}]
        run._discover()
        self.assertTrue(run.uncertain)
        self.assertEqual(run.uncertainty_categories, {"pid_reuse", "parent_identity_mismatch"})
        self.assertNotIn(13, run.owned)

    def test_every_uncertainty_category_is_fixed_and_triggerable_without_payload(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100", 12: "100"}
        executable = str((Path.cwd() / "synthetic.exe").absolute())
        run._snapshot = lambda: [
            {"pid": 10, "parent": 1, "started": "999", "executable": executable},
            {"pid": 11, "parent": 12, "started": "101", "executable": executable},
            {"pid": 12, "parent": 1, "started": "900", "executable": executable},
        ]
        run._discover()
        self.assertEqual(run.uncertainty_categories, {"pid_reuse", "parent_identity_mismatch"})

        invalid = m.WindowsRun({"directory": "synthetic-unused"})
        invalid.owned = {10: "100"}
        invalid._snapshot = lambda: [
            {"pid": 10, "parent": 1, "started": "100", "executable": executable},
            {"pid": 11, "parent": 10, "started": "99", "executable": executable},
        ]
        invalid._discover()
        self.assertEqual(invalid.uncertainty_categories, {"child_tick_invalid"})

        source = Path(m.__file__).read_text(encoding="utf-8")
        sites = {"pid_reuse": 1, "parent_missing": 1, "parent_identity_mismatch": 1,
                 "child_tick_invalid": 1, "identity_probe_failed": 3,
                 "postcheck_live_process": 2, "cleanup_exception": 3}
        self.assertEqual(set(sites), m.UNCERTAINTY_CATEGORIES)
        for category, count in sites.items():
            self.assertEqual(source.count(f'_mark_uncertain("{category}")'), count)
        self.assertNotIn("self.uncertain =", source)
        with self.assertRaisesRegex(m.Refused, "^internal$"):
            run._mark_uncertain("PRIVATE_PAYLOAD")

    def test_unknown_relevant_child_identity_is_rejected_before_adoption(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run.owned = {10: "100"}
        run._snapshot = lambda: [{"pid": 11, "parent": 10, "started": None, "executable": None}]
        with self.assertRaisesRegex(m.Refused, "^snapshot_schema$"):
            run._discover()
        self.assertNotIn(11, run.owned)

    def test_full_mode_explicitly_releases_offline_after_abort_guard(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        seen = []
        run._run_code = lambda code, left: seen.append(code) or self.matrix_result()
        with patch.object(Path, "read_text", return_value="async(page)=>({})"):
            run.full_matrix(30)
        self.assertIn("setOffline(false)", seen[0])
        self.assertLess(seen[0].index("route('**/*'"), seen[0].index("setOffline(false)"))

    def test_driver_result_frame_requires_one_fully_consumed_strict_json_object(self):
        with TemporaryDirectory(prefix="oncam-driver-result-") as directory:
            run = m.WindowsRun({"directory": directory})
            run.claimed = True
            run.lifecycle_phase = "normal"
            valid = '### Result\r\n{"seconds":0.25,"requests":1}\r\n'
            run._cli = lambda args, remaining: valid
            self.assertEqual(run._run_code("async()=>({})", 1), {
                "seconds": 0.25, "requests": 1,
            })
            invalid = (
                "{}",
                "### Result\n{}\n### Result\n{}",
                "### Error\nPRIVATE\n### Result\n{}",
                '### Result\n{"x":1,"x":2}',
                '### Result\n{"x":NaN}',
                '### Result\n{"x":Infinity}',
                "### Result\nnull",
                "### Result\n[]",
                "### Result\n1",
                "### Result\n{} {}",
                "prefix ### Result\n{}",
                "### Result\n{",
                "### Result\n{\"x\":\"\ud800\"}",
                "### Result\n" + "[" * 1100 + "]" * 1100,
            )
            for output in invalid:
                with self.subTest(output=output), self.assertRaisesRegex(m.Refused, "^driver$"):
                    run._cli = lambda args, remaining, output=output: output
                    run._run_code("async()=>({})", 1)

    def test_smoke_result_schema_types_bounds_and_fixed_counts_are_exact(self):
        run = m.WindowsRun({"directory": "synthetic-unused"})
        valid = {"seconds": 0.2, "requests": 1}
        run._run_code = lambda code, left: valid
        self.assertEqual(run.smoke_request(3), 0.2)
        invalid = (
            ({"requests": 1, "seconds": 0.2}, "smoke_result"),
            ({"seconds": 0.2, "requests": True}, "smoke_count"),
            ({"seconds": 0.2, "requests": 2}, "smoke_count"),
            ({"seconds": True, "requests": 1}, "smoke_result"),
            ({"seconds": -0.1, "requests": 1}, "smoke_result"),
            ({"seconds": 5.1, "requests": 1}, "smoke_result"),
            ({"seconds": float("nan"), "requests": 1}, "smoke_result"),
            ({"seconds": float("inf"), "requests": 1}, "smoke_result"),
            ({"seconds": 0.2, "requests": 1, "extra": 1}, "smoke_result"),
            ({"requests": 1}, "smoke_result"),
        )
        for result, reason in invalid:
            with self.subTest(result=result), self.assertRaisesRegex(m.Refused, f"^{reason}$"):
                run._run_code = lambda code, left, result=result: result
                run.smoke_request(3)

    def test_full_matrix_result_requires_exact_ordered_canonical_envelope(self):
        driver = (Path(__file__).with_name("checkout-session.browser.mjs")).read_text("utf-8")
        def assert_exact_driver_return(source):
            fields = (
                ("checks", None),
                ("exchangePosts", "exchangePosts"),
                ("hostileForms", "hostileForms"),
                ("opaqueNetworkBlocks", "opaqueNetworkBlocks"),
                ("expectedSandboxInstrumentationErrors", "expectedSandboxInstrumentationErrors"),
                ("controlledNetworkEntries", "safeNetwork.size"),
                ("credentialMaterialRecorded", "false"),
                ("screenshotsContainingCredentials", "0"),
                ("fullBusinessPostcondition", "true"),
                ("historyObservations", "historyObservations"),
                ("immediateDeliveredDOMRemovalClaimed", "false"),
            )
            if tuple(key for key, _ in fields) != m.FULL_MATRIX_KEYS:
                raise AssertionError("driver_result_keys")
            lines = ["    return {", "        checks: ["]
            lines.extend(f"            '{value}'," for value in m.FULL_MATRIX_CHECKS)
            lines.append("        ],")
            for key, expression in fields[1:]:
                lines.append(
                    f"        {key}," if key == expression else f"        {key}: {expression},"
                )
            lines.append("    }")
            snippet = "\n".join(lines)
            if source.count("    return {\n        checks: [") != 1 or source.count(snippet) != 1:
                raise AssertionError("driver_result_source")

        assert_exact_driver_return(driver)
        reordered_driver = driver.replace(
            "        exchangePosts,\n        hostileForms,",
            "        hostileForms,\n        exchangePosts,",
            1,
        )
        missing_driver = driver.replace("        hostileForms,\n", "", 1)
        renamed_driver = driver.replace(
            "        controlledNetworkEntries: safeNetwork.size,",
            "        controlledEntries: safeNetwork.size,",
            1,
        )
        for label, invalid in (
            ("reordered", reordered_driver),
            ("missing", missing_driver),
            ("renamed", renamed_driver),
        ):
            with self.subTest(driver=label), self.assertRaises(AssertionError):
                assert_exact_driver_return(invalid)
        run = m.WindowsRun({"directory": "synthetic-unused"})
        run._run_code = lambda code, left: self.matrix_result()
        with patch.object(Path, "read_text", return_value="async(page)=>({})"):
            self.assertIsNone(run.full_matrix(30))

        cases = {}
        missing = self.matrix_result(); missing.pop("exchangePosts"); cases["missing"] = missing
        extra = self.matrix_result(); extra["extra"] = 1; cases["extra"] = extra
        reordered = self.matrix_result(); reordered["checks"] = reordered.pop("checks"); cases["reordered"] = reordered
        checks = self.matrix_result(); checks["checks"] = checks["checks"][::-1]; cases["checks"] = checks
        exchange = self.matrix_result(); exchange["exchangePosts"] = True; cases["exchange"] = exchange
        hostile = self.matrix_result(); hostile["hostileForms"] = 5; cases["hostile"] = hostile
        screenshots = self.matrix_result(); screenshots["screenshotsContainingCredentials"] = False; cases["screenshots"] = screenshots
        credential = self.matrix_result(); credential["credentialMaterialRecorded"] = 0; cases["credential"] = credential
        full = self.matrix_result(); full["fullBusinessPostcondition"] = 1; cases["full"] = full
        immediate = self.matrix_result(); immediate["immediateDeliveredDOMRemovalClaimed"] = True; cases["immediate"] = immediate
        counter_bool = self.matrix_result(); counter_bool["opaqueNetworkBlocks"] = True; cases["counter_bool"] = counter_bool
        counter_range = self.matrix_result(); counter_range["opaqueNetworkBlocks"] = 3; cases["counter_range"] = counter_range
        instrumentation = self.matrix_result(); instrumentation["expectedSandboxInstrumentationErrors"] = -1; cases["instrumentation"] = instrumentation
        entries_range = self.matrix_result(); entries_range["controlledNetworkEntries"] = 0; cases["entries_range"] = entries_range
        entries_bool = self.matrix_result(); entries_bool["controlledNetworkEntries"] = True; cases["entries_bool"] = entries_bool
        entries_upper = self.matrix_result(); entries_upper["controlledNetworkEntries"] = 65; cases["entries_upper"] = entries_upper
        history_count = self.matrix_result(); history_count["historyObservations"].pop(); cases["history_count"] = history_count
        history_phase = self.matrix_result(); history_phase["historyObservations"][0]["phase"] = "other"; cases["history_phase"] = history_phase
        history_type = self.matrix_result(); history_type["historyObservations"][0]["summaryDOMVisible"] = 0; cases["history_type"] = history_type
        history_keys = self.matrix_result(); history_keys["historyObservations"][0]["extra"] = False; cases["history_keys"] = history_keys
        for label, result in cases.items():
            with self.subTest(label=label), self.assertRaisesRegex(m.Refused, "^matrix$"):
                run._run_code = lambda code, left, result=result: result
                with patch.object(Path, "read_text", return_value="async(page)=>({})"):
                    run.full_matrix(30)

    def test_missing_asset_delivery_review_blocks_before_process_or_source_read(self):
        config = self.candidate_config()
        config.pop("asset_delivery_review")
        run = m.WindowsRun(config)
        with patch.object(Path, "read_bytes", side_effect=AssertionError("must not read")), patch.object(m.subprocess, "Popen", side_effect=AssertionError("must not spawn")):
            with self.assertRaisesRegex(m.Refused, "candidate_config"):
                run.preflight(1)

    def test_asset_delivery_review_contract_rejects_old_or_malformed_before_identity(self):
        valid = {name: "a" * 64 for name in m.ASSET_REVIEW_FILES}
        old_four = {
            name: valid[name]
            for name in (
                "public/css/checkout-summary-v1.css",
                "public/brand/oncam-logo-full-color.png",
                "resources/views/checkout/summary.blade.php",
                m.HARNESS,
            )
        }
        invalid = (
            old_four,
            {key: value for key, value in valid.items() if key != m.ASSET_REVIEW_FILES[0]},
            {**valid, "public/extra.js": "a" * 64},
            {**valid, m.ASSET_REVIEW_FILES[0]: True},
            {**valid, m.ASSET_REVIEW_FILES[0]: "a" * 63},
        )
        for review in invalid:
            with self.subTest(keys=tuple(review)):
                config = self.candidate_config()
                config["asset_delivery_review"] = review
                run = m.WindowsRun(config)
                with patch.object(run, "_canonical", side_effect=AssertionError("identity")), \
                        patch.object(m.subprocess, "Popen", side_effect=AssertionError("spawn")):
                    with self.assertRaisesRegex(m.Refused, "^candidate_config$"):
                        run.preflight(1)

        manifest = dict(valid)
        manifest[m.ASSET_REVIEW_FILES[0]] = "b" * 64
        with self.assertRaisesRegex(m.Refused, "^asset_overlay_mismatch$"):
            m._validated_asset_review(valid, manifest)

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
        run._mark_uncertain("pid_reuse")
        run._discover = lambda: {10: {"started": "999"}}
        run._ps = lambda command: self.fail("must not kill reused PID")
        run._listeners = lambda: []
        self.assertFalse(run.cleanup(1))

    def test_malformed_active_owner_snapshot_cannot_disappear_and_cleanup_is_uncertain(self):
        executable = str((Path.cwd() / "synthetic.exe").absolute())
        malformed = (
            [{"pid": True, "parent": 1, "started": "100", "executable": executable}],
            [{"pid": 10, "parent": 1, "started": None, "executable": None}],
            [{"pid": 10, "parent": 1, "started": "100", "executable": "relative.exe"}],
            [
                {"pid": 10, "parent": 1, "started": "100", "executable": executable},
                {"pid": 10, "parent": 1, "started": "100", "executable": executable},
            ],
        )
        for rows in malformed:
            with self.subTest(rows=rows):
                run = m.WindowsRun({"directory": "synthetic-unused"})
                run.owned = {10: "100"}
                run._snapshot = lambda rows=rows: rows
                run._listeners = lambda: []
                self.assertFalse(run.cleanup(1))
                self.assertEqual(run.uncertainty_categories, {"cleanup_exception"})

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
        self.assertIn("cleanup_exception", run.uncertainty_categories)

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
                run._command(["unused"], 1, policy="untracked", role="helper")

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
        run._snapshot = lambda: [{"pid": 11, "parent": 10, "started": "101",
                                  "executable": str((Path.cwd() / "synthetic.exe").absolute())}]
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
        self.assertIn("postcheck_live_process", run.uncertainty_categories)

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
