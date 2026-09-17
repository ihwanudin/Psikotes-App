"""Import-only assembly for the checkout supervisor and coordinator anchor store."""

from __future__ import annotations

import copy
import importlib.util
import os
from pathlib import Path
import re


_HERE = Path(__file__).resolve().parent
_SESSION = re.compile(r"checkout-[a-f0-9]{32}")
_DIGEST = re.compile(r"[a-f0-9]{64}")
_MAX_GENERATION = 1024


class CoordinatorRefused(Exception):
    """Fixed coordinator refusal codes; never includes paths or stored values."""


def _load_module(name: str, filename: str):
    try:
        spec = importlib.util.spec_from_file_location(name, _HERE / filename)
        if spec is None or spec.loader is None:
            raise CoordinatorRefused("coordinator_module")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    except CoordinatorRefused:
        raise
    except Exception:
        raise CoordinatorRefused("coordinator_module") from None


supervisor_module = _load_module("checkout_supervisor_coordinated", "checkout-supervisor.py")
anchor_store_module = _load_module("checkout_anchor_store_coordinated", "checkout-anchor-store.py")
lease_module = _load_module("checkout_coordinator_lease_coordinated", "checkout-coordinator-lease.py")


class SupervisorAnchorPublisher:
    """Adapt the provenance document store to the supervisor's raw-anchor protocol."""

    def __init__(self, store, *, run, expected_run, expected_session, expected_config_binding):
        try:
            publish = store.publish
            load = store.load
            if not callable(publish) or getattr(publish, "__self__", None) is not store \
                    or not callable(getattr(publish, "__func__", None)) \
                    or not callable(load) or getattr(load, "__self__", None) is not store \
                    or not callable(getattr(load, "__func__", None)) \
                    or not isinstance(store.run, str) \
                    or not isinstance(store.session, str) or _SESSION.fullmatch(store.session) is None \
                    or not isinstance(store.config_binding, str) \
                    or _DIGEST.fullmatch(store.config_binding) is None \
                    or not isinstance(expected_run, str) \
                    or not isinstance(expected_session, str) \
                    or _SESSION.fullmatch(expected_session) is None \
                    or not isinstance(expected_config_binding, str) \
                    or _DIGEST.fullmatch(expected_config_binding) is None:
                raise CoordinatorRefused("coordinator_store")
            self.store = store
            self._store = store
            self._run = run
            self._expected_run = expected_run
            self._expected_session = expected_session
            self._expected_config_binding = expected_config_binding
            self._store_identity = copy.deepcopy((
                store.directory,
                store.directory_identity,
                store.run,
                store.session,
                store.config_binding,
                store.path,
                store.lock_path,
            ))
            self._publish = publish
            self._publish_function = publish.__func__
            self._load = load
            self._load_function = load.__func__
            self._validate_binding(require_attached=False)
        except CoordinatorRefused:
            raise
        except Exception:
            raise CoordinatorRefused("coordinator_store") from None

    def _validate_binding(self, *, require_attached=True):
        try:
            require_lease = getattr(self._run, "_required_lifecycle_lease", None)
            if require_attached:
                if not callable(require_lease):
                    raise CoordinatorRefused("coordinator_binding")
                require_lease()
            current_publish = self._store.publish
            current_load = self._store.load
            current_run = supervisor_module.WindowsRun._normalized_path(
                str(Path(self._run.run).absolute())
            )
            current_binding = self._run._config_binding(self._expected_session)
            if self.store is not self._store \
                    or (require_attached and self._run.anchor_publisher is not self) \
                    or current_run != self._expected_run \
                    or self._run.session != self._expected_session \
                    or current_binding != self._expected_config_binding \
                    or (
                        self._store.directory,
                        self._store.directory_identity,
                        self._store.run,
                        self._store.session,
                        self._store.config_binding,
                        self._store.path,
                        self._store.lock_path,
                    ) != self._store_identity \
                    or self._store.run != self._expected_run \
                    or self._store.session != self._expected_session \
                    or self._store.config_binding != self._expected_config_binding \
                    or getattr(current_publish, "__self__", None) is not self._store \
                    or getattr(current_publish, "__func__", None) is not self._publish_function \
                    or getattr(current_load, "__self__", None) is not self._store \
                    or getattr(current_load, "__func__", None) is not self._load_function:
                raise CoordinatorRefused("coordinator_binding")
        except CoordinatorRefused:
            raise
        except Exception:
            raise CoordinatorRefused("coordinator_binding") from None

    def __call__(self, anchor):
        self._validate_binding()
        try:
            return self._publish(anchor)
        finally:
            self._validate_binding()

    def load(self):
        try:
            self._validate_binding()
            try:
                document = self._load()
            finally:
                self._validate_binding()
            if not isinstance(document, dict) or set(document) != {
                "version", "run", "session", "configBinding", "anchor"
            } or type(document["version"]) is not int or document["version"] != 1 \
                    or document["run"] != self._expected_run \
                    or document["session"] != self._expected_session \
                    or document["configBinding"] != self._expected_config_binding:
                raise CoordinatorRefused("coordinator_anchor")
            anchor = document["anchor"]
            if not isinstance(anchor, dict) or set(anchor) != {"generation", "digest"} \
                    or type(anchor["generation"]) is not int \
                    or not 1 <= anchor["generation"] <= _MAX_GENERATION \
                    or not isinstance(anchor["digest"], str) \
                    or _DIGEST.fullmatch(anchor["digest"]) is None:
                raise CoordinatorRefused("coordinator_anchor")
            return {"generation": anchor["generation"], "digest": anchor["digest"]}
        except CoordinatorRefused:
            raise
        except Exception:
            raise CoordinatorRefused("coordinator_anchor") from None


def _explicit_inputs(config, coordinator_directory):
    if type(config) is not dict:
        raise CoordinatorRefused("coordinator_config")
    if not isinstance(coordinator_directory, (str, os.PathLike)):
        raise CoordinatorRefused("coordinator_directory")


def _acl_call(callback, *args, **kwargs):
    try:
        return callback(*args, **kwargs)
    except supervisor_module.Refused:
        raise CoordinatorRefused("coordinator_acl") from None


def _bind_admission(run, *, lifecycle_lease, acl_attestor):
    _acl_call(run.bind_lifecycle_lease, lifecycle_lease)
    _acl_call(run.bind_acl_attestor, acl_attestor)
    return run


def _attach_publisher(run, *, coordinator_directory, session):
    # This is deliberate same-tool coupling. The parity tests pin the supervisor's
    # own binding implementation rather than duplicating its security contract.
    binding = _acl_call(run._config_binding, session)
    store = anchor_store_module.CheckoutAnchorStore(
        coordinator_directory=coordinator_directory,
        run_directory=run.run,
        session=session,
        config_binding=binding,
    )
    publisher = SupervisorAnchorPublisher(
        store,
        run=run,
        expected_run=store.run,
        expected_session=session,
        expected_config_binding=binding,
    )
    _acl_call(run.bind_anchor_publisher, publisher)
    publisher._validate_binding(require_attached=True)
    return run


def _run_from_config(config):
    try:
        config_copy = copy.deepcopy(config)
        if type(config_copy) is not dict:
            raise CoordinatorRefused("coordinator_config")
        run = supervisor_module.WindowsRun(config_copy)
        # Validate the complete candidate contract at this boundary so callers
        # never observe the supervisor's lower-level refusal vocabulary.
        run._config_binding(run.session)
        return run
    except CoordinatorRefused:
        raise
    except Exception:
        raise CoordinatorRefused("coordinator_config") from None


def assemble_fresh(*, config, coordinator_directory):
    """Create an inspection-only bare run; lifecycle operations require the façade."""
    _explicit_inputs(config, coordinator_directory)
    return _run_from_config(config)


def assemble_recovery(*, config, coordinator_directory, session):
    """Bare recovery assembly cannot read an anchor without a held lease."""
    _explicit_inputs(config, coordinator_directory)
    raise CoordinatorRefused("coordinator_lease")


def _candidate_directory(config):
    try:
        if type(config) is not dict or not isinstance(config.get("directory"), (str, os.PathLike)):
            raise CoordinatorRefused("coordinator_config")
        return config["directory"]
    except CoordinatorRefused:
        raise
    except Exception:
        raise CoordinatorRefused("coordinator_config") from None


def _acquire_lease(config, coordinator_directory):
    _explicit_inputs(config, coordinator_directory)
    try:
        return lease_module.CheckoutCoordinatorLease.acquire(
            coordinator_directory=coordinator_directory,
            run_directory=_candidate_directory(config),
        )
    except CoordinatorRefused:
        raise
    except Exception:
        raise CoordinatorRefused("coordinator_lease") from None


def _assemble_fresh(config, coordinator_directory, lease, acl_attestor, context=None):
    run = _run_from_config(config)
    if context is not None:
        context["run"] = run
    _bind_admission(run, lifecycle_lease=lease, acl_attestor=acl_attestor)
    _acl_call(run.prepare_acl_admission, "anchor", "fresh")
    _attach_publisher(run, coordinator_directory=coordinator_directory, session=run.session)
    _acl_call(run.prepare_acl_admission, "execution", "fresh")
    return run


def _assemble_recovery(config, coordinator_directory, session, lease, acl_attestor,
                       context=None):
    if not isinstance(session, str) or _SESSION.fullmatch(session) is None:
        raise CoordinatorRefused("coordinator_session")
    run = _run_from_config(config)
    if context is not None:
        context["run"] = run
    run.session = session
    _bind_admission(run, lifecycle_lease=lease, acl_attestor=acl_attestor)
    _acl_call(run.prepare_acl_admission, "anchor", "recovery")
    _attach_publisher(run, coordinator_directory=coordinator_directory, session=session)
    anchor = _acl_call(run.load_recovery_anchor)
    _acl_call(run.prepare_acl_admission, "execution", "recovery")
    run.lifecycle_phase = "recovery_ready"
    return run, anchor


def _safe_to_release(run):
    if run is None:
        return True
    if run.claimed is True:
        return run.lifecycle_phase in {"closed", "invalidated"}
    return run.lifecycle_phase in {"new", "recovery_ready"} \
        and not run.handles and not run.roles and not run.owned \
        and not run.recovery_hydrated


def _release_lease(lease, run, primary):
    try:
        terminal_safe = _safe_to_release(run)
        terminal_error = None
    except BaseException as error:
        terminal_safe = False
        terminal_error = error
    try:
        lease.release("lifecycle_finished")
        release_error = None
    except BaseException as error:
        release_error = error
    if primary is not None:
        raise primary
    if terminal_error is not None and isinstance(terminal_error, (KeyboardInterrupt, SystemExit)):
        raise terminal_error
    if release_error is not None:
        if isinstance(release_error, (KeyboardInterrupt, SystemExit)):
            raise release_error
        raise CoordinatorRefused("coordinator_lease_release") from None
    if not terminal_safe:
        raise CoordinatorRefused("coordinator_lease_terminal") from None


def _execute_with_lease(config, coordinator_directory, acl_attestor, operation):
    _explicit_inputs(config, coordinator_directory)
    if acl_attestor is None:
        raise CoordinatorRefused("coordinator_acl")
    try:
        config_snapshot = copy.deepcopy(config)
    except Exception:
        raise CoordinatorRefused("coordinator_config") from None
    try:
        coordinator_snapshot = os.fspath(coordinator_directory)
        if type(coordinator_snapshot) is not str or not coordinator_snapshot \
                or "\0" in coordinator_snapshot:
            raise ValueError("coordinator")
    except Exception:
        raise CoordinatorRefused("coordinator_directory") from None
    lease = _acquire_lease(config_snapshot, coordinator_snapshot)
    context = {"run": None}
    primary = None
    result = None
    try:
        result = operation(lease, context, config_snapshot, coordinator_snapshot)
    except BaseException as error:
        primary = error
    _release_lease(lease, context["run"], primary)
    return result


def supervise_fresh(*, config, coordinator_directory, acl_attestor=None, mode, requests, budget):
    """Run the supervisor only after fresh coordinator assembly and binding."""
    def execute(lease, context, config_snapshot, coordinator_snapshot):
        run = _assemble_fresh(
            config_snapshot, coordinator_snapshot, lease, acl_attestor, context,
        )
        return _acl_call(
            supervisor_module.supervise,
            run, mode=mode, requests=requests, budget=budget,
        )

    return _execute_with_lease(config, coordinator_directory, acl_attestor, execute)


def recover_existing(*, config, coordinator_directory, acl_attestor=None, session, budget):
    """Run recovery only with the exact anchor loaded by coordinator assembly."""
    def execute(lease, context, config_snapshot, coordinator_snapshot):
        run, anchor = _assemble_recovery(
            config_snapshot, coordinator_snapshot, session, lease, acl_attestor, context,
        )
        return _acl_call(
            supervisor_module.recover,
            run, session=session, anchor=anchor, budget=budget,
        )

    return _execute_with_lease(config, coordinator_directory, acl_attestor, execute)
