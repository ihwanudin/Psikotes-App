# Security binary content gate handoff — 2026-09-13

## Verdict

**READY** for Tech Lead review and cherry-pick of `310b8a1`.

The secret and PII repository profiles now process the tracked PNG and ICO
assets without changing those assets, adding broad path allowlists, or weakening
the fail-closed behavior for unrecognized binary formats. Both fresh repository
profiles pass against the Git index and working-tree snapshots.

## Lane identity and scope

- Task: security/release gate repair for tracked binary content.
- Branch/worktree: `codex/security-binary-content-gate` at
  `C:/Users/ThinkPad/.codex/worktrees/c4e5/Psikotes`.
- Requested baseline: `codex/f2-wave1-integration` at
  `83f7c54f4ed853c394692b95852895c86f89eb2a`.
- Result commit: `310b8a1` (`fix: scan PNG and ICO content safely`).
- During the lane, the integration branch advanced independently to `8e83be4`.
  Its changed files are one migration, its PostgreSQL test, and
  `tasks/parallel-work.md`; there is no overlap with this lane's owned files.
- Owned files:
  - `tools/security/image-content.mjs`
  - `tools/security/repository-content-scan.mjs`
  - `tools/security/repository-content-scan.test.mjs`
  - this handoff report
- Explicitly untouched: production assets, scan policy exceptions, migrations,
  routes, shared API/DTO/ADR files, lockfiles, payment data, feature behavior,
  and canonical acceptance checklists.

## Threat model and repair

Git index blobs and their tracked working-tree counterparts remain untrusted.
File extensions are not authority: PNG and ICO classification uses exact magic
bytes. Accepted PNGs require bounded dimensions, known chunk types, reserved-bit
rules, CRC integrity, canonical critical ordering, non-interlaced raster data,
bounded aggregate metadata inflation, exact raster output length, complete zlib
input consumption, and valid per-row filter bytes. Text in `tEXt`, `zTXt`, and
`iTXt` is decoded and scanned. Unsupported ancillary carriers are rejected.

Accepted ICOs require an exact icon directory, contiguous and non-overlapping
frames, bounded offsets and sizes, and either a recursively validated PNG frame
or a strict uncompressed 40-byte BITMAPINFOHEADER DIB with exact XOR/mask size.
Printable DIB bytes remain a secret/PII scan surface. Other binary formats,
unknown PNG chunks, malformed CRCs, decompression overflow, trailing content,
and unsupported image structures continue to fail closed.

Once binary traversal exposed 33 pre-existing PII-profile findings, source audit
confirmed they were 13 repository-owned synthetic phone values plus two
incomplete code literals. The repair recognizes only those exact reserved
fixture values and the narrow syntax where a quoted numeric prefix is immediately
concatenated. There is no PII policy exception or path-wide exemption; adversarial
tests retain detection for realistic repeated and ascending numbers.

## RED evidence

Before repair, both commands exited 1 at the same first binary:

```text
node tools/security/repository-content-scan.mjs --kind=secret
Tracked file has unsupported encoding: public/apple-touch-icon.png

node tools/security/repository-content-scan.mjs --kind=pii
Tracked file has unsupported encoding: public/apple-touch-icon.png
```

The initial image-focused test run failed 3/3. A later review test also proved
that a valid-CRC invalid IDAT stream and aggregate multi-chunk metadata expansion
were accepted before the bounded raster/inflate hardening; both are now rejected.

## Verification evidence

Exact commands and final results:

```text
node --check tools/security/image-content.mjs
node --check tools/security/repository-content-scan.mjs
node --check tools/security/repository-content-scan.test.mjs
# all exit 0

npx prettier --no-config --check --single-quote --print-width 80 --tab-width 4 \
  tools/security/image-content.mjs \
  tools/security/repository-content-scan.mjs \
  tools/security/repository-content-scan.test.mjs
# All matched files use Prettier code style

git diff --cached --check
# exit 0, no output

npm run security:scan:test
# 47 tests, 47 pass, 0 fail

npm run security:scan:pii
# PII scan passed (1382 tracked paths; index and working-tree snapshots)

npm run security:scan:secret
# SECRET scan passed (1382 tracked paths; index and working-tree snapshots)
```

The configured Prettier and ESLint entry points could not load because this
worktree's dependency installation lacks `prettier-plugin-tailwindcss` and
`@eslint/js`. No dependency install, manifest, or lockfile change was made.
Equivalent syntax checks and a config-free Prettier check passed; the focused
scanner suite and both repository profiles are authoritative for this lane.

## False-negative and residual-risk analysis

- The gate detects plaintext secret/PII in supported PNG text metadata,
  including compressed metadata, and printable bytes in uncompressed ICO DIB
  frames. It does not claim to detect semantic steganography encoded as pixel
  colors. PNG raster streams are structurally validated but not interpreted as
  OCR or steganographic payloads.
- PNG ancillary types outside the deliberately narrow supported set fail closed.
  Interlaced PNG and alternate ICO DIB headers/compression also fail closed until
  a bounded parser and adversarial fixtures are added.
- An actual phone equal to one of the exact reserved synthetic fixture values
  would be treated as synthetic. Those values are intentionally reserved for
  repository fixtures; no prefix/range wildcard was added.
- No code-level blocker remains. The only environment residual is the incomplete
  local frontend dependency installation noted above.

## Integration instruction

Review and cherry-pick `310b8a1` onto the current integration head. The patch has
no file overlap with `83f7c54..8e83be4`; rerun the three final gates after
integration because the target branch advanced while this lane was active.
