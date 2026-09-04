"""Cooperative synthetic checkout supervisor. Import has no runtime side effects."""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
import time

PORTS = (8126, 443)
ASSETS = ("public/css/checkout-summary-v1.css", "public/brand/oncam-logo-full-color.png")
HARNESS = "tools/testing/tests/Browser/serve-checkout-session.php"
DRIVER = "tools/testing/tests/Browser/checkout-session.browser.mjs"
CLI_SUFFIX = "31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js"
BROWSER_SUFFIX = "ms-playwright/chromium-1234/chrome-win64/chrome.exe"


class Refused(Exception):
    """Only fixed stage codes are returned, never subprocess output."""


def supervise(io, *, mode="smoke", requests=3, budget=180):
    """Sequencing core: the OS adapter owns handles even when launch raises."""
    if mode not in ("smoke", "full") or type(requests) is not int or not 1 <= requests <= 3:
        raise Refused("invalid_options")
    if type(budget) is not int or not 1 <= budget <= 1200:
        raise Refused("invalid_budget")
    deadline = io.clock() + budget
    io.io_deadline = deadline
    stage, claimed, completed, final_clean = "preflight", False, False, False
    cleanup_remaining, cleanup_failed = 15.0, False
    io.postcheck_complete = False
    count = 0
    result = {"state": "invalid", "accepted": False, "requests": 0, "reason": "preflight"}

    def step(name, operation):
        nonlocal stage
        stage = name
        remaining = deadline - io.clock()
        if remaining <= 0:
            raise Refused("budget")
        value = operation(remaining)
        if io.clock() >= deadline:
            raise Refused("budget")
        return value

    def cleanup():
        # One shared allowance, debited even on interruption; no recursion or fresh reserves.
        nonlocal cleanup_remaining, cleanup_failed
        started, succeeded = io.clock(), False
        allowance = cleanup_remaining
        try:
            if allowance <= 0:
                return False
            succeeded = (io.cleanup(allowance) is True and not getattr(io, "uncertain", False)
                         and io.clock() - started <= allowance)
            return succeeded
        finally:
            cleanup_remaining = max(0, cleanup_remaining - max(0, io.clock() - started))
            cleanup_failed = cleanup_failed or not succeeded

    try:
        step("preflight", io.preflight)
        step("occupied_port", io.assert_ports_free)
        step("claim", io.claim)
        claimed = True
        step("precheck", lambda remaining: io.harness("integrity-pre", remaining))
        for role in ("php", "tls", "browser"):
            step("spawn_" + role, lambda remaining, role=role: io.launch(role, remaining))
        step("ownership", io.assert_owned)
        step("start", lambda remaining: io.harness("integrity-start", remaining))
        # No driver navigation can occur above this point. Browser opens offline about:blank.
        if mode == "smoke":
            for _ in range(requests):
                count += 1  # conservative count of request permits, including a failed attempt
                elapsed = step("smoke", io.smoke_request)
                if not 0 <= elapsed <= 5:
                    raise Refused("request_target")
        else:
            step("matrix", io.full_matrix)
            stage = "cleanup"
            if not cleanup():
                raise Refused("cleanup")
            step("stop", lambda remaining: io.harness("integrity-stop", remaining, assertions=True))
            step("business", lambda remaining: io.harness("verify", remaining))
            stage = "final_cleanup"
            if not cleanup():
                raise Refused("cleanup")
            step("postcheck", lambda remaining: io.harness("integrity-post", remaining))
            io.postcheck_complete = True
        completed = True
    except Exception as error:
        code = str(error) if isinstance(error, Refused) else ""
        result["reason"] = ("occupied_port" if code == "occupied_port" else "listener_inspection_failed") \
            if stage == "occupied_port" else stage
    finally:
        # Encloses ALL lifecycle operations, including stop/business/post and BaseException.
        # Interrupts are not caught or converted into successful/ordinary result objects.
        claimed = claimed or io.claimed
        if claimed:
            try:
                try:
                    final_clean = cleanup()
                except Exception:
                    final_clean = False
                if not final_clean and result["reason"] != "final_cleanup":
                    result["reason"] = "cleanup"
            finally:
                # Also runs if final cleanup itself raises KeyboardInterrupt/SystemExit.
                if not completed or not final_clean or cleanup_failed or mode == "smoke":
                    io.invalidate()
    result["requests"] = count
    if completed and final_clean and not cleanup_failed:
        if mode == "smoke":
            result.update(state="incomplete", reason="fresh_run_required")
        else:
            result.update(state="postverified", reason="root_review_required")
    return result


class WindowsRun:
    """Explicit reviewed paths only. No downloads, shared sessions or default config discovery."""

    def __init__(self, config):
        self.c = config
        self.run = Path(config["directory"])
        self.source = self.run / "source"
        self.env = {}
        self.owned = {}  # PID -> exact creation ticks, including short-lived CLI children.
        self.roles = {}
        self.handles = []
        self.claimed = False
        self.uncertain = False
        self.postcheck_complete = False
        self.session = "checkout-" + secrets.token_hex(16)
        self.owner = None
        self.io_deadline = float("inf")

    clock = staticmethod(time.monotonic)

    @staticmethod
    def _canonical(path):
        if path.is_symlink() or path.resolve() != path.absolute():
            raise Refused("noncanonical")

    def preflight(self, remaining):
        review = self.c.get("asset_delivery_review", {})
        required = (*ASSETS, "resources/views/checkout/summary.blade.php", HARNESS)
        if set(review) != set(required) or any(not isinstance(v, str) or not re.fullmatch(r"[a-f0-9]{64}", v) for v in review.values()):
            raise Refused("asset_delivery_review_required")
        if os.name != "nt" or self.run.parent.resolve() != Path(tempfile.gettempdir()).resolve():
            raise Refused("scope")
        if not re.fullmatch(r"oncam-checkout-[0-9a-f]{32}", self.run.name):
            raise Refused("scope")
        for path in (self.run, self.source):
            self._canonical(path)
            if list(path.glob(".env*")):
                raise Refused("environment")
        if any((self.run / p).exists() for p in ("supervisor.json", "integrity-evidence.json", "integrity-invalid")):
            raise Refused("not_fresh")
        for name in ("browser.sqlite", "baseline.json", "fixtures.json"):
            path = self.run / name
            self._canonical(path)
            if not path.is_file():
                raise Refused("fresh_fixture_required")
        raw = (self.run / "source-manifest.json").read_bytes()
        if hashlib.sha256(raw).hexdigest() != self.c["manifest"]:
            raise Refused("manifest")
        manifest = json.loads(raw)
        # This reviewed asset-delivery contract is deliberately absent from the old copy.
        # Presence alone does not authorize a generic static-file router.
        for name in required:
            path = self.source / name
            self._canonical(path)
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            if digest != manifest.get(name) or digest != review[name]:
                raise Refused("asset_overlay_mismatch")
        if not str(self.c["cli"]).replace("\\", "/").endswith(CLI_SUFFIX):
            raise Refused("cli")
        if not str(self.c["browser"]).replace("\\", "/").endswith(BROWSER_SUFFIX):
            raise Refused("browser")
        # Reviewed executable/config hashes are supplied by root, never auto-refreshed here.
        for key in ("php", "python", "node", "powershell", "cli", "browser", "ini", "browser_config", "cert", "key"):
            path = Path(self.c[key])
            self._canonical(path)
            if key in ("ini", "browser_config", "cert", "key") and path.parent.resolve() != self.run.resolve():
                raise Refused("runtime_file_scope")
            if hashlib.sha256(path.read_bytes()).hexdigest() != self.c["tool_hashes"][key]:
                raise Refused("tool_hash")
        browser = json.loads(Path(self.c["browser_config"]).read_text())["browser"]
        if browser.get("isolated") is not True or browser["launchOptions"].get("headless") is not True:
            raise Refused("browser_config")
        if browser["launchOptions"].get("executablePath") != self.c["browser"]:
            raise Refused("browser_config")
        if browser["contextOptions"].get("offline") is not True or browser["contextOptions"].get("serviceWorkers") != "block":
            raise Refused("browser_config")
        args = browser["launchOptions"].get("args", [])
        resolver = "--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1,MAP oncam.id 127.0.0.1,MAP * ~NOTFOUND"
        if not all(arg in args for arg in (resolver, "--no-proxy-server", "--disable-background-networking")):
            raise Refused("browser_network_guard")
        # Process-local allowlist; do not copy the calling shell's application environment.
        self.env = {key: os.environ[key] for key in ("SystemRoot", "TEMP", "TMP")}
        runtime_home = self.run / "storage/framework/supervisor-home"
        daemon_dir = self.run / "storage/framework/playwright-daemon"
        self.env.update(CI="1", NO_UPDATE_NOTIFIER="1", APP_ENV="testing",
                        LOCALAPPDATA=str(runtime_home), USERPROFILE=str(runtime_home),
                        PWTEST_DAEMON_SESSION_DIR=str(daemon_dir), PWTEST_CLI_GLOBAL_CONFIG=str(runtime_home),
                        ONCAM_CHECKOUT_BROWSER_DIRECTORY=str(self.run), ONCAM_CHECKOUT_BROWSER_PORT="8126",
                        ONCAM_CHECKOUT_BROWSER_MANIFEST_SHA256=self.c["manifest"],
                        ONCAM_CHECKOUT_BROWSER_INTEGRITY_MODE="cooperative-v1")
        self.owner = self._identity(os.getpid())
        self.owner["nonce"] = secrets.token_hex(32)
        self.env["ONCAM_CHECKOUT_BROWSER_OWNER"] = json.dumps(self.owner, separators=(",", ":"))

    def _command(self, args, timeout, *, track=True):
        end = min(self.clock() + timeout, self.io_deadline)
        if self.clock() >= end:
            raise Refused("budget")
        # Real files avoid Windows pipe-read hangs. Output is bounded on capture/poll.
        with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
            process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=output, stderr=errors,
                                       cwd=self.run, env=self.env, creationflags=subprocess.CREATE_NO_WINDOW)
            self.handles.append(process)  # retain even if registration/timeout fails
            try:
                if track:
                    try:
                        self.owned[process.pid] = self._identity(process.pid)["started"]
                    except Exception:
                        self.uncertain = True
                        raise
                while process.poll() is None:
                    if self.clock() >= end or os.fstat(output.fileno()).st_size > 262144 or os.fstat(errors.fileno()).st_size > 4096:
                        raise Refused("command")
                    if track:
                        self._discover()
                    time.sleep(.02)
                if track:
                    self._discover()
                output.seek(0)
                errors.seek(0)
                data = output.read(262145)
                if process.returncode != 0 or len(data) > 262144 or errors.read(4097):
                    raise Refused("command")
                return data.decode("utf-8-sig")
            finally:
                if process.poll() is None:
                    process.kill()  # Popen handle only, not a searched PID.
                    process.wait(timeout=2)

    def _ps(self, script, timeout=3):
        return self._command([self.c["powershell"], "-NoProfile", "-NonInteractive", "-Command", script], timeout, track=False)

    def _identity(self, pid):
        if type(pid) is not int or pid <= 0:
            raise Refused("identity")
        ticks = self._ps(f"$ErrorActionPreference='Stop'; $p=[Diagnostics.Process]::GetProcessById({pid}); try {{$p.StartTime.ToUniversalTime().Ticks.ToString()}} finally {{$p.Dispose()}}").strip()
        if not re.fullmatch(r"[0-9]{1,20}", ticks):
            raise Refused("identity")
        return {"pid": pid, "started": ticks}

    def _snapshot(self):
        script = "$ErrorActionPreference='Stop'; $rows=@(Get-CimInstance Win32_Process | ForEach-Object { $t=$null; try {$p=[Diagnostics.Process]::GetProcessById([int]$_.ProcessId); $t=$p.StartTime.ToUniversalTime().Ticks.ToString(); $p.Dispose()} catch {}; @{pid=[int]$_.ProcessId; parent=[int]$_.ParentProcessId; started=$t} }); ConvertTo-Json -InputObject $rows -Compress"
        return json.loads(self._ps(script))

    def _discover(self):
        rows = self._snapshot()
        current = {r["pid"]: r for r in rows}
        for pid, tick in list(self.owned.items()):
            if pid in current and current[pid]["started"] != tick:
                self.uncertain = True  # PID reuse: never adopt/kill the new owner's children.
        changed = True
        while changed:
            changed = False
            for row in rows:
                parent = row["parent"]
                if row["pid"] in self.owned or parent not in self.owned:
                    continue
                if parent not in current or current[parent]["started"] != self.owned[parent]:
                    # An orphan's numeric PPID alone cannot prove ancestry after PID reuse.
                    self.uncertain = True
                    continue
                if not row["started"] or int(row["started"]) < int(self.owned[parent]):
                    self.uncertain = True
                    continue
                self.owned[row["pid"]] = row["started"]
                changed = True
        return current

    def _listeners(self):
        try:
            # Six seconds is scoped to this cold module-backed inspection only. The
            # global lifecycle deadline remains authoritative and debits the time.
            rows = json.loads(self._ps("$ErrorActionPreference='Stop'; $r=@(Get-NetTCPConnection -ErrorAction Stop | Where-Object {$_.State -eq 'Listen' -and $_.LocalPort -in 8126,443} | ForEach-Object {@{pid=[int]$_.OwningProcess; port=[int]$_.LocalPort; address=$_.LocalAddress}}); ConvertTo-Json -InputObject $r -Compress", timeout=6))
            if not isinstance(rows, list) or any(
                not isinstance(row, dict) or set(row) != {"pid", "port", "address"}
                or type(row["pid"]) is not int or row["pid"] <= 0
                or type(row["port"]) is not int or row["port"] not in PORTS
                or not isinstance(row["address"], str) or not 1 <= len(row["address"]) <= 64
                for row in rows
            ):
                raise Refused("listener_inspection_failed")
            return rows
        except Exception:
            raise Refused("listener_inspection_failed") from None

    def assert_ports_free(self, remaining):
        if self._listeners():
            raise Refused("occupied_port")

    def claim(self, remaining):
        with (self.run / "supervisor.json").open("x") as file:
            self.claimed = True
            json.dump({"mode": "cooperative", "owner": self.owner, "accepted": False}, file)
        self.claimed = True

    def harness(self, mode, remaining, assertions=False):
        self.io_deadline = self.clock() + remaining
        if assertions:
            self.env["ONCAM_CHECKOUT_BROWSER_ASSERTIONS_PASSED"] = "1"
        if self.roles:
            self.env["ONCAM_CHECKOUT_BROWSER_OWNED_PROCESSES"] = json.dumps(self.roles, separators=(",", ":"))
        text = self._command([self.c["php"], "-n", "-c", self.c["ini"], str(self.source / HARNESS), mode], remaining)
        data = json.loads(text)
        expected = {"integrity-pre": "preverified", "integrity-start": "serving", "integrity-stop": "stopped", "integrity-post": "postverified"}
        if mode == "verify":
            if data != {"businessMatchesFixedPlan": True, "lifecycleAuditsChecked": True}:
                raise Refused("business")
        elif data != {"state": expected[mode], "accepted": False}:
            raise Refused("harness")

    def _cli(self, args, remaining):
        return self._command([self.c["node"], self.c["cli"], "-s=" + self.session, *args], remaining,
                             track=args[0] != "close")

    def launch(self, role, remaining):
        if role == "browser":
            text = self._cli(["open", "about:blank", "--config=" + self.c["browser_config"]], remaining)
            match = re.search(r"opened with pid ([0-9]+)\.", text)
            if not match:
                raise Refused("browser_pid")
            identity = self._identity(int(match[1]))
            self._discover()
            if self.owned.get(identity["pid"]) != identity["started"]:
                raise Refused("unowned_browser")
        else:
            args = ([self.c["php"], "-n", "-c", self.c["ini"], "-S", "127.0.0.1:8126", "-t", str(self.run / "storage/public"), str(self.source / HARNESS)] if role == "php" else
                    [self.c["python"], str(self.source / "tools/testing/tests/Browser/https-loopback-proxy.py"), self.c["cert"], self.c["key"], "443", "8126"])
            process = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                       cwd=self.run, env=self.env, creationflags=subprocess.CREATE_NO_WINDOW)
            self.handles.append(process)
            try:
                identity = self._identity(process.pid)
            except Exception:
                self.uncertain = True
                raise
            self.owned[identity["pid"]] = identity["started"]
        self.roles[role] = identity

    def assert_owned(self, remaining):
        current = self._discover()
        if self.uncertain or set(self.roles) != {"php", "tls", "browser"}:
            raise Refused("ownership")
        for identity in self.roles.values():
            if current.get(identity["pid"], {}).get("started") != identity["started"]:
                raise Refused("ownership")
        listeners = self._listeners()
        expected = {(8126, self.roles["php"]["pid"], "127.0.0.1"), (443, self.roles["tls"]["pid"], "127.0.0.1")}
        if {(r["port"], r["pid"], r["address"]) for r in listeners} != expected:
            raise Refused("listeners")

    def _run_code(self, code, remaining):
        # CLI --filename is documented; the temporary code has no credential values.
        path = self.run / ("supervisor-code-" + secrets.token_hex(8) + ".mjs")
        with path.open("x") as file:
            file.write(code)
        text = self._cli(["run-code", "--filename=" + str(path)], remaining)
        if "### Error" in text or "### Result\n" not in text.replace("\r\n", "\n"):
            raise Refused("driver")
        data = text.replace("\r\n", "\n").split("### Result\n", 1)[1]
        return json.JSONDecoder().raw_decode(data.lstrip())[0]

    def smoke_request(self, remaining):
        code = '''async (page) => { const c=page.context(); await c.unrouteAll(); let n=0;
        await c.route('**/*', route => { const r=route.request(); if(r.method()==='GET' && r.url()==='https://psikotes.oncam.id/__browser/login' && ++n===1) return route.continue(); return route.abort(); }); await c.setOffline(false);
        const start=Date.now(); const r=await page.goto('https://psikotes.oncam.id/__browser/login', {timeout:30000,waitUntil:'domcontentloaded'});
        if(!r || r.status()!==200 || n!==1) throw new Error('Smoke refused'); await c.setOffline(true); return {seconds:(Date.now()-start)/1000, requests:n}; }'''
        result = self._run_code(code, remaining)
        if result.get("requests") != 1:
            raise Refused("smoke_count")
        return result["seconds"]

    def full_matrix(self, remaining):
        code = (self.source / DRIVER).read_text()
        code = "async(page)=>{await page.context().route('**/*',r=>r.abort()); await page.context().setOffline(false); return await (" + code + ")(page);}"
        result = self._run_code(code, remaining)
        if result.get("fullBusinessPostcondition") is not True or result.get("credentialMaterialRecorded") is not False or len(result.get("checks", [])) != 11:
            raise Refused("matrix")

    def cleanup(self, budget):
        end = self.clock() + budget
        self.io_deadline = end
        clean = False
        try:
            current = self._discover()
            if self.postcheck_complete and any(current.get(pid, {}).get("started") == tick for pid, tick in self.owned.items()):
                # A writer alive after the full scan invalidates its premise, even if cleanup succeeds.
                self.uncertain = True
            browser = self.roles.get("browser")
            if browser and current.get(browser["pid"], {}).get("started") == browser["started"]:
                try:
                    self._cli(["close"], min(3, end - self.clock()))
                except Exception:
                    pass
            while self.clock() < end:
                current = self._discover()
                live = [(pid, tick) for pid, tick in self.owned.items() if current.get(pid, {}).get("started") == tick]
                if not live:
                    break
                if self.postcheck_complete:
                    self.uncertain = True
                for pid, tick in reversed(live):
                    # Re-check creation time inside the same PS command before stopping it.
                    script = f"$ErrorActionPreference='Stop'; try {{$p=[Diagnostics.Process]::GetProcessById({pid})}} catch {{exit 0}}; try {{if($p.StartTime.ToUniversalTime().Ticks.ToString() -eq '{tick}') {{$p.Kill()}}}} finally {{$p.Dispose()}}"
                    self._ps(script)
                time.sleep(.02)
            current = self._discover()
            clean = not self._listeners() and not any(current.get(pid, {}).get("started") == tick for pid, tick in self.owned.items())
        except Exception:
            clean = False
        finally:
            # Also covers a launch failure before identity registration. Never search by name.
            for handle in self.handles:
                try:
                    if handle.poll() is None:
                        handle.kill()
                        handle.wait(timeout=max(.01, min(2, end - self.clock())))
                except Exception:
                    self.uncertain = True
        return clean and not self.uncertain

    def invalidate(self):
        if self.claimed:
            try:
                with (self.run / "integrity-invalid").open("x") as file:
                    file.write("INVALID\n")
            except FileExistsError:
                pass


if __name__ == "__main__":
    # No accidental launch or implicit config discovery. Runtime authorization is a separate review.
    raise SystemExit("Runtime entrypoint disabled pending supervisor review; import for pure tests only.")
