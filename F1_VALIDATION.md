# F1 validation evidence map

Tanggal rekonsiliasi: 14 September 2026.

Baseline dokumentasi: `30cd06e1a7320bcbc659cecc3642fc7771b227cb`.

Status: **F1 PARTIAL; Task 18 OPEN; Task 19 OPEN; release NO-GO**

## Legenda

- `PROVEN`: ada bukti repository/command yang dapat ditautkan ke claim sempit.
- `NOT-RUN`: sengaja tidak dieksekusi pada closeout dokumentasi ini.
- `NOT-VERIFIABLE`: environment/dependency atau authority tidak tersedia.
- `BLOCKED`: prerequisite eksternal/human belum tersedia; gate harus tetap open.

Status bukan sinonim marketing. `PROVEN` pada satu lapisan tidak menutup gate
end-to-end. Checklist F1 dihitung ulang dari `tasks/todo.md`: **81/100** check
tercentang; enam check Task 19 tetap kosong. Matriks F2-F9 tetap 0/9 phase exit
complete dan 0/28 acceptance row complete pada seluruh lapisan end-to-end.

## Evidence matrix

| Boundary | Status | Evidence / reason | Claim limit |
| --- | --- | --- | --- |
| Exact baseline, detached, clean before edit | PROVEN | `git rev-parse HEAD`, `git symbolic-ref -q HEAD`, `git status --short --branch` | Hanya identity/scope checkout ini |
| Compose topology parses | PROVEN | `docker compose config --quiet` exit 0 dengan placeholder ephemeral untuk lima variable wajib | Tidak start/pull/build; tidak membuktikan health/runtime |
| App/queue/integrations-queue/scheduler/migrate/Postgres/Redis topology | PROVEN | Static `compose.yaml` inspection | Tidak membuktikan service live |
| Runtime non-owner and owner-only migration design | PROVEN | Compose connection split, schema/tests, accepted PostgreSQL evidence | Tidak ada migration aktif pada closeout ini |
| Runtime `NOBYPASSRLS` behavior | PROVEN | Accepted disposable PostgreSQL evidence in current history/handoffs | Tidak berarti semua F1 RLS checklist selesai |
| RLS request/job middleware contract | PROVEN | `ApplyRlsContext`, `ApplyRlsContextToJob`, `RlsContextRunner`, route/architecture code inspection | Artisan/test rerun unavailable here |
| Artisan command registry | NOT-VERIFIABLE | `vendor/autoload.php` absent; `php artisan list --raw` not run | Command signatures inspected statically |
| Scheduler listing | NOT-VERIFIABLE | `vendor/autoload.php` absent; `schedule:list` not run | Schedule inspected from `routes/console.php` |
| Health/webhook route listing | NOT-VERIFIABLE | `vendor/autoload.php` absent; `route:list` not run | Routes inspected statically |
| `/health` controller happy/degraded behavior | PROVEN | Existing focused mocked test and static controller/Compose probe | Real dependency-down HTTP path NOT-VERIFIABLE because Redis web middleware may fail first |
| Correlation, structured HTTP metrics, alert/SLO contract | NOT-VERIFIABLE | Observability handoff 2026-09-14 | No backend/threshold/destination/owner selected |
| Manual transfer F1 flow | PROVEN | Accepted synthetic SQLite/PostgreSQL evidence: registration, private proof, approval, entitlement/outbox/login boundary | No live bank transfer or participant data |
| Payment method OFF/history behavior | PROVEN | Feature/contract evidence in F1 checklist | Does not authorize provider activation |
| Xendit adapter/webhook/reconciliation contracts | PROVEN | HTTP fake/contract tests and static code | No sandbox/live provider claim |
| Xendit sandbox invoice→callback→entitlement→notification→login | BLOCKED | Development credential unavailable; checklist explicitly skipped | Method must remain OFF; Task 18 open |
| Live Xendit call or callback | NOT-RUN | Prohibited for documentation closeout | No provider-side evidence |
| Notification outbox retry/dead-letter contract | PROVEN | Static job/service: tries 5; backoff 30/120/600/1800; attempts<5 dispatcher; safe error codes | No live delivery claim |
| Fake notification delivery | PROVEN | Accepted F1 synthetic evidence | Fake only |
| n8n workflow/dedupe definition | PROVEN | Repository workflow, SQL contract, and tests/documentation | No deployed/active workflow claim |
| Live n8n/WAHA delivery | NOT-RUN | No outbound action; no live credential evidence | Must not be represented as verified |
| Local logical dump/restore rehearsal | PROVEN | Accepted F9 handoff: repeated custom dump/atomic restore and corrupt-archive rejection | Not production backup/PITR/RPO/RTO |
| Production backup encryption/off-host retention/PITR | BLOCKED | Destination, custody, schedule, access, RPO/RTO, and production-like proof absent | No production readiness claim |
| Bounded local session/result load baseline | PROVEN | Accepted F9 fixed-workload evidence | No SLO/capacity/Kraepelin/HTTP claim |
| External object storage/Shared Drive | NOT-VERIFIABLE | Config/adapters exist; live bucket/archive policy and smoke evidence absent | Local/fake storage evidence only |
| Identity/payment-proof retention and purge | BLOCKED | Legal/psychologist retention authority absent | No purge scheduling/activation |
| Audit purge command | PROVEN | Accepted inert command boundary | Not registered in scheduler; no live deletion |
| Repository SECRET profile on candidate working tree | PROVEN | Scanner passed 1,423 tracked paths across index and working-tree snapshots | Secret profile only |
| Exact four changed docs: secret/PII pattern scan | PROVEN | Zero private-key/credential-assignment/non-reserved email/Indonesian phone/NIK findings; count-only output | Changed Markdown only; no matched value emitted |
| Full repository PII profile on this checkout | NOT-VERIFIABLE | Remained silent beyond bounded wait and was stopped; no pass inferred | Must be rerun by integration reviewer |
| Pinned Prettier Markdown check | NOT-VERIFIABLE | No local/global Prettier binary; no install attempted | `git diff --check` still passed |
| Full PHP/frontend/browser/security suite on this checkout | NOT-RUN | Documentation-only scope; dependencies unavailable | Historical results are not a fresh pass |

## Safe checks executed for this closeout

```text
git status --short --branch                         PROVEN (clean, detached before edit)
git rev-parse HEAD                                  PROVEN (exact baseline above)
git symbolic-ref -q HEAD                            PROVEN (nonzero: detached)
docker compose config --quiet                       PROVEN (exit 0; ephemeral placeholders)
php artisan list --raw                              NOT-VERIFIABLE (vendor absent)
php artisan schedule:list                           NOT-VERIFIABLE (vendor absent)
php artisan route:list --path=health                NOT-VERIFIABLE (vendor absent)
php artisan route:list --path=webhooks              NOT-VERIFIABLE (vendor absent)
repository content scan --kind=secret               PROVEN (1,423 paths)
exact changed-doc secret/PII count-only scan         PROVEN (0 / 0 findings)
repository content scan --kind=pii                  NOT-VERIFIABLE (bounded wait)
prettier --check four Markdown files                NOT-VERIFIABLE (binary absent)
git diff --check                                    PROVEN
```

No `.env` was created. No dependency was installed. No Docker service was
started/pulled/built. No database, port, provider, notification, migration,
scheduler, queue worker, purge, deployment, or external call was used.

## Required gate before F1/Task 19 closure

1. Review and accept this exact four-file documentation candidate.
2. On a clean checkout with locked dependencies present, rerun Artisan list,
   schedule/route checks, Markdown formatting, secret/PII scan, and the required
   F1 suites without unsupported skips.
3. Provision development-only Xendit credentials through approved custody,
   keep the method OFF until sandbox E2E passes, and record provider-side cleanup.
4. Prove the full synthetic Xendit chain and security/browser gate.
5. Obtain legal/psychologist decisions for identity/payment-proof/consent
   retention and purge; separately approve monitoring/backup owners and targets.

Until those items are reviewed, do not check Task 19, do not check remaining
Task 18 gates, do not activate Xendit/n8n/WAHA/purge/deployment, and do not claim
F1 complete.
