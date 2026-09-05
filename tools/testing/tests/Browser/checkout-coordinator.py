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


def _attach(run, *, coordinator_directory, session):
    # This is deliberate same-tool coupling. The parity tests pin the supervisor's
    # own binding implementation rather than duplicating its security contract.
    binding = run._config_binding(session)
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
    run.bind_anchor_publisher(publisher)
    publisher._validate_binding()
    return run


def _run_from_config(config):
    try:
        config_copy = copy.deepcopy(config)
        if type(config_copy) is not dict:
            raise CoordinatorRefused("coordinator_config")
        return supervisor_module.WindowsRun(config_copy)
    except CoordinatorRefused:
        raise
    except Exception:
        raise CoordinatorRefused("coordinator_config") from None


def assemble_fresh(*, config, coordinator_directory):
    """Create an unclaimed run with a publisher attached; never calls claim or Popen."""
    _explicit_inputs(config, coordinator_directory)
    run = _run_from_config(config)
    return _attach(run, coordinator_directory=coordinator_directory, session=run.session)


def assemble_recovery(*, config, coordinator_directory, session):
    """Create a recovery candidate and return its persisted raw anchor without recovering."""
    _explicit_inputs(config, coordinator_directory)
    if not isinstance(session, str) or _SESSION.fullmatch(session) is None:
        raise CoordinatorRefused("coordinator_session")
    run = _run_from_config(config)
    run.session = session
    _attach(run, coordinator_directory=coordinator_directory, session=session)
    # Prevent this old-run assembly from being mistaken for a fresh claim. The
    # supervisor recovery path explicitly hydrates and advances it to `recovery`.
    run.lifecycle_phase = "recovery_ready"
    return run, run.anchor_publisher.load()
