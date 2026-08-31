# Organization and Integration Onboarding

## Capability matrix

| Capability | Audit status | Evidence / implementation |
|---|---|---|
| Public registration | EXISTING | `/register`, `POST /registrations`, registration tests |
| Referral cabang + first-touch | EXISTING | referral cookie/visit ledger and attribution tests |
| Branch isolation | EXISTING | admin policy, request RLS context, PostgreSQL policies/tests |
| Selection App provisioning | EXISTING | Selection v1 HMAC endpoint, idempotency ledger, launch bridge |
| Entitlement | EXISTING | commercial locked/ready and sponsored ready flows |
| Xendit/manual payment | EXISTING | verified webhook, reconciliation, manual proof/approval tests |
| Launch ticket | EXISTING | one-time Selection bridge and identity ledger checks |
| Final report workflow | MISSING | report/session phases are not present in this repository snapshot; `RecordAssessmentOutcome` is the integration hook, not a substitute for psychologist finalization |
| Notification outbox | EXISTING | participant activation transactional outbox |
| Generic organization/client/source registry | IMPLEMENTED | additive organization columns and three tenant-safe registry/mapping tables |
| Generic provisioning + pull result | IMPLEMENTED | assessment v1 endpoints with strict allow-lists and tenant projection |
| Result callback/reconciliation | IMPLEMENTED | safe event outbox, callback ledger, UNKNOWN-before-retry rule |
| Organization portal operations | IMPLEMENTED | tenant-scoped list/filter, one-time invitation reissue, and allow-listed CSV export |
| Integration registry admin | IMPLEMENTED | super-admin-only client/source forms; secret references only; HTTPS callback guardrails |
| Session start/completion event hooks | IMPLEMENTED | transactional state transitions emit stable v1 outbox events; the test engine can call these hooks |
| Scoring/report engine | MISSING | current endpoint intentionally returns `SESSION_ENGINE_PENDING`; later product phases own these capabilities |

## Controlled onboarding

1. Create or update a `branches` row as the participating organization. Set an immutable `organization_code`, explicit `organization_type`, display name, `ACTIVE` status, and allowed funding modes. Do not rename/drop legacy IDs.
2. Create an `integration_clients` row only when the organization owns an application. Portal-only organizations use `PORTAL_ONLY` and still require no callback.
3. Put the HMAC material in the production secret manager and expose it through `ASSESSMENT_INTEGRATION_CREDENTIALS_JSON`. Store only its key as `credential_reference`.
4. Register every source in `integration_sources` with an explicit `contract_version`; allow only reviewed package codes, funding modes, callback paths, and effective dates. Never create a source from browser payload.
5. Exercise signed requests in staging, verify tenant-negative tests, callback reconciliation, and safe logs. Keep `enabled=false` until the production owner approves activation.

## Examples

- Serbaindo pusat: `organization_type=INTERNAL_CENTER`, source `SELEKSI_SERBAINDO`, funding `INTERNAL` or contracted invoicing.
- Cabang Serbaindo: `organization_type=INTERNAL_BRANCH`; public referral continues to use first-touch `ref_code`, while a branch-owned server gets its own integration client only if needed.
- Beasiswa Jepang: `organization_type=SCHOLARSHIP_OPERATOR`; Selection v1 tetap menjadi compatibility adapter karena paket legacy tersusun dari test types, bukan package registry generik. Endpoint dan participant ID lama tidak diubah; sumber baru memakai boundary generik.
- LPK Sakura: `organization_type=EXTERNAL_LPK`; an integrated LPK uses API plus callback/poll, while an LPK without software uses `PORTAL_ONLY`.
- Direct public: no external integration client; `sourceSystem=DIRECT_PUBLIC` is assigned server-side by the registration flow.

Undangan portal memakai public ULID pada path dan token acak pada URL fragment (`#token=...`). Fragment tidak dikirim dalam HTTP request/access log. Browser menukar token sekali melalui POST ber-CSRF, segera membersihkan URL, lalu menyimpan JWT peserta di `sessionStorage`. Reverse proxy tetap harus menghindari pencatatan request body.

No onboarding step creates a CRM identity or makes a final recruitment decision. Cross-organization linking requires an explicit reconciliation workflow.
