# ONCAM Psikotes Design System v1

Status: **candidate specification; not imported by production UI**

Token source: `resources/design-tokens/oncam.tokens.json`

Product: ONCAM — Online Career Mentor

Baseline: `4de3fb4d20302ff95b325b1f158978d535b557c1`

## 1. Scope and authority

This document is the Stage 1 design contract for the participant React/Inertia/Tailwind 4 surface and the staff Filament/Livewire surface. It defines visual foundations, component behavior, responsive acceptance, logo use, and the future implementation map. It does not alter CSS, themes, components, routes, configuration, dependencies, or runtime behavior.

Authoritative brand colors are ONCAM green `#005F41`, gold `#D4AF37`, white `#FFFFFF`, and soft neutral `#F7F7F7`. Gold is an accent and brand-recognition color—not the primary action color, a default text color, or the sole carrier of status.

The existing product font stack is retained: Instrument Sans with system fallbacks. No new brand font, UI framework, component library, theme runtime, or dark-mode package is introduced.

Product progress remains governed by the repository acceptance checklists. At this baseline, the recalculated F1 checklist is 81/100, end-to-end T rows are 0/28, phase exits are 0/9, and release status remains NO-GO. This specification does not close F2 participant instrument UI or F5 psychologist-review acceptance.

## 2. Token architecture

The JSON uses three layers. Every semantic and component value is an alias; raw values exist only in primitives.

| Layer     | Purpose                         | Example                                             | Change rule                              |
| --------- | ------------------------------- | --------------------------------------------------- | ---------------------------------------- |
| Primitive | Stable raw palette and scales   | `primitive.color.brand.green`                       | Change only with brand/system approval   |
| Semantic  | Meaning in a light or dark mode | `semantic.light.color.action.primary.background`    | Change when product meaning changes      |
| Component | State-specific consumption      | `component.light.button.primary.background.default` | Change when a component contract changes |

References use DTCG-style braces, for example `{semantic.light.color.action.primary.background}`. Consumers must resolve aliases, reject missing references and cycles, and preserve the three-layer boundary. Component code should not consume primitives directly.

### Modes

- `semantic.shared` and `component.shared` hold mode-independent typography, dimensions, spacing, radius, borders, motion, and opacity.
- `semantic.light` and `component.light` are the default participant and staff mode.
- `semantic.dark` and `component.dark` are the dark-surface contract; a future implementation may map it to the repository's existing `.dark` strategy.
- A consumer must select one mode as a complete set. Mixing light and dark component values inside one component state is invalid.

### Typography

| Role            | Token                             | Size | Guidance                           |
| --------------- | --------------------------------- | ---: | ---------------------------------- |
| Supporting/meta | `semantic.shared.font.body.sm`    | 14px | Never below 12px; do not use gold  |
| Body/control    | `semantic.shared.font.body.md`    | 16px | Default readable interface copy    |
| Lead            | `semantic.shared.font.body.lg`    | 18px | Introductory or emphasized copy    |
| Heading small   | `semantic.shared.font.heading.sm` | 20px | Card and section headings          |
| Heading medium  | `semantic.shared.font.heading.md` | 24px | Page subsections                   |
| Heading large   | `semantic.shared.font.heading.lg` | 30px | Page title                         |
| Display         | `semantic.shared.font.heading.xl` | 36px | Sparse landing/report moments only |

Body copy uses 1.5 line height; long-form guidance uses 1.625; headings use 1.2. Controls and headings are semibold. All-caps text is reserved for short metadata and must use the wide tracking token. Text must remain usable at 200% browser zoom without clipping or loss.

### Spacing, shape, depth, and motion

- Spacing follows a 4px base scale. Prefer semantic spacing aliases to arbitrary values.
- The default control radius is 8px and panel radius is 10px. Pills are reserved for status, filters, and compact metadata.
- Borders communicate boundaries before shadows. Cards use a visible 1px boundary; overlays may use the overlay shadow.
- Motion is 120–180ms with the standard easing token. Animate specific properties only—never `transition: all`.
- Under `prefers-reduced-motion: reduce`, nonessential transitions use the instant duration. State meaning may not rely on animation.
- Interactive targets are at least 44×44 CSS px even when their visual glyph is smaller.

## 3. Color and accessibility contract

WCAG 2.2 AA thresholds used here are 4.5:1 for normal text, 3:1 for large text, and 3:1 for meaningful non-text boundaries and focus indicators. The matrix uses sRGB relative luminance and unrounded ratios for pass/fail decisions; displayed ratios are rounded to two decimals.

| Use                            | Foreground | Background |   Ratio | Acceptance                                      |
| ------------------------------ | ---------: | ---------: | ------: | ----------------------------------------------- |
| Primary text/button label      |  `#FFFFFF` |  `#005F41` |  7.74:1 | PASS normal text                                |
| Soft-neutral text/button label |  `#F7F7F7` |  `#005F41` |  7.22:1 | PASS normal text                                |
| Gold accent on green           |  `#D4AF37` |  `#005F41` |  3.68:1 | PASS large text/non-text only; FAIL normal text |
| White on gold                  |  `#FFFFFF` |  `#D4AF37` |  2.10:1 | FAIL; prohibited for text/UI boundary           |
| Deep green on gold             |  `#004530` |  `#D4AF37` |  5.27:1 | PASS normal text                                |
| Primary light text             |  `#0E1713` |  `#FFFFFF` | 18.25:1 | PASS normal text                                |
| Secondary light text           |  `#4B5851` |  `#FFFFFF` |  7.46:1 | PASS normal text                                |
| Muted light text               |  `#637169` |  `#FFFFFF` |  5.13:1 | PASS normal text                                |
| Secondary text on soft neutral |  `#4B5851` |  `#F7F7F7` |  6.97:1 | PASS normal text                                |
| Primary text on soft neutral   |  `#0E1713` |  `#F7F7F7` | 17.04:1 | PASS normal text                                |
| Primary dark text              |  `#F7F7F7` |  `#17231E` | 15.13:1 | PASS normal text                                |
| Secondary dark text            |  `#B7C0BB` |  `#17231E` |  8.70:1 | PASS normal text                                |
| Muted dark text                |  `#87948D` |  `#17231E` |  5.13:1 | PASS normal text                                |
| Light focus ring               |  `#005F41` |  `#FFFFFF` |  7.74:1 | PASS non-text                                   |
| Dark focus ring                |  `#76C7A6` |  `#17231E` |  8.10:1 | PASS non-text                                   |
| Light input boundary           |  `#87948D` |  `#FFFFFF` |  3.16:1 | PASS non-text                                   |
| Dark input boundary            |  `#87948D` |  `#24312B` |  4.29:1 | PASS non-text                                   |
| Light success                  |  `#145C3B` |  `#E8F4ED` |  7.09:1 | PASS normal text                                |
| Light warning                  |  `#6B4600` |  `#FFF4D6` |  7.66:1 | PASS normal text                                |
| Light error                    |  `#8F1D14` |  `#FFF1EF` |  8.11:1 | PASS normal text                                |
| Light info                     |  `#07527A` |  `#E8F4FA` |  7.51:1 | PASS normal text                                |
| Dark success                   |  `#A9E2C4` |  `#123A2A` |  8.63:1 | PASS normal text                                |
| Dark warning                   |  `#F4D37A` |  `#3D2A00` |  9.44:1 | PASS normal text                                |
| Dark error                     |  `#F6B0A8` |  `#4A1712` |  8.22:1 | PASS normal text                                |
| Dark info                      |  `#9BD5F0` |  `#0A3247` |  8.44:1 | PASS normal text                                |
| Dark success boundary          |  `#5EB98B` |  `#17231E` |  6.78:1 | PASS non-text                                   |
| Dark warning boundary          |  `#D4AF37` |  `#17231E` |  7.71:1 | PASS non-text                                   |
| Dark error boundary            |  `#E46B60` |  `#17231E` |  5.07:1 | PASS non-text                                   |
| Dark info boundary             |  `#3FA6D6` |  `#17231E` |  5.89:1 | PASS non-text                                   |

Disabled controls are exempt from WCAG contrast requirements and are therefore **not applicable**, not claimed as passing. They still require an explicit disabled attribute/state, a stable label, and more than opacity alone when confusion is plausible. Decorative logo pixels are not text contrast claims.

Status is always communicated by at least two cues: color plus icon, text label, border/pattern, or position. Green is both a brand color and success-family color, so success messaging must include an explicit success label/icon. Gold may support warning emphasis but cannot be the only warning cue.

## 4. Logo system

The approved artwork is a vertically stacked mark, ONCAM wordmark, and “ONLINE CAREER MENTOR” descriptor. Preserve the complete lockup. No icon-only, horizontal, or descriptor-free production asset is approved in this stage.

### Asset matrix

| Variant                 | Repository asset                         | Exact source                                                                     | Intended surface                                 | Production status                |
| ----------------------- | ---------------------------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------ | -------------------------------- |
| Full color              | `public/brand/oncam-logo-full-color.png` | `D:/ONCAM/Logo ONCAM/21.904.ONLINE CAREER MENTOR/PNG/1_ONLINE CAREER MENTOR.png` | White or soft-neutral light surface              | Existing, approved               |
| Mono dark               | `public/brand/oncam-logo-mono-dark.png`  | `D:/ONCAM/Logo ONCAM/21.904.ONLINE CAREER MENTOR/PNG/2_ONLINE CAREER MENTOR.png` | Light surface requiring one-color reproduction   | Candidate exact copy             |
| Reversed light          | `public/brand/oncam-logo-reversed.png`   | `D:/ONCAM/Logo ONCAM/21.904.ONLINE CAREER MENTOR/PNG/3_ONLINE CAREER MENTOR.png` | ONCAM green or sufficiently dark neutral surface | Candidate exact copy             |
| Gold-on-green reference | None                                     | `D:/ONCAM/Logo ONCAM/21.904.ONLINE CAREER MENTOR/JPG/3_ONLINE CAREER MENTOR.jpg` | Visual reference only                            | Prohibited as a production asset |

All approved PNGs are 1200×1027 RGBA at 72 dpi and have identical alpha geometry. PNG 1 uses exact `#005F41` and `#D4AF37`; PNG 2 uses `#000000`; PNG 3 uses `#F7F7F7`.

### Placement rules

- Maintain the source aspect ratio: `1200 / 1027`. Set intrinsic `width="1200" height="1027"`; constrain CSS width and keep height auto.
- Minimum digital width is 160 CSS px. Recommended responsive widths are 160px at 320, 176px at 390, 192px at 768, and 220px at 1280. Larger hero/report placement may use 240–320px.
- Define clear space `x` as 8% of the rendered logo width. The logo container must preserve at least `x` on every side. The clear space is external; do not edit or pad the PNG.
- Because the approved lockup is tall, compact navigation may render the product name as accessible text until a separate compact lockup is approved. It must not crop the existing artwork to fabricate one.
- Use full color only on white/soft-neutral surfaces; mono dark on light single-color contexts; reversed light on ONCAM green or dark neutral. Never place full color on gold, photography, patterns, or dark surfaces.
- Do not recolor, stretch, rotate, skew, crop, mask, outline, add a shadow, change element spacing, rearrange the lockup, or recreate it in text.
- A linked logo that is the only home label uses `alt="ONCAM — Online Career Mentor"`. If identical visible adjacent text already names the link, use `alt=""`. A purely decorative duplicate uses `alt=""` and must not become an extra focus target.
- Do not use the filename, “logo,” or visual color as alt text. The accessible link name must describe its destination when context is not obvious.

## 5. Component contracts

The JSON defines shared geometry and light/dark state colors for buttons, inputs, cards, navigation, tables, status badges, and alerts. These states are required in future implementations.

### Buttons

| State         | Primary                                | Secondary/quiet                                             | Behavior                                                  |
| ------------- | -------------------------------------- | ----------------------------------------------------------- | --------------------------------------------------------- |
| Default       | Green background, white text           | Surface background, green or primary text, visible boundary | Native `button` or link semantics                         |
| Hover         | Deliberate darker/lighter alias        | Deliberate surface/border alias                             | Pointer hover only; not a substitute for focus            |
| Active        | Stronger pressed alias                 | Stronger pressed alias                                      | Immediate feedback; no layout shift                       |
| Focus-visible | 3px mode-specific ring with offset     | Same                                                        | Must remain visible against page and control              |
| Disabled      | Disabled aliases and 0.72 opacity      | Same                                                        | Native `disabled` or `aria-disabled` plus guarded action  |
| Loading       | Retain label or accessible status text | Same                                                        | Prevent duplicate submission; do not expose spinner alone |

Primary actions use green—not gold. Destructive actions use error semantics and require an explicit destructive label/icon.

### Inputs and field groups

| State              | Requirement                                                                                                        |
| ------------------ | ------------------------------------------------------------------------------------------------------------------ |
| Default            | Persistent visible label, 44px minimum target, 3:1 boundary, instructions before input                             |
| Hover              | Boundary emphasis without layout shift                                                                             |
| Focus-visible      | Mode focus ring and focused border; programmatic label remains associated                                          |
| Disabled/read-only | Visually distinct and programmatically exposed; do not use placeholder as label                                    |
| Error              | Error border plus icon/message; connect message with `aria-describedby`; move focus only when workflow requires it |
| Success            | Success border plus confirmation text/icon; do not announce routine validation noisily                             |

Checkboxes and radios retain native keyboard behavior. Group labels use `fieldset`/`legend` where appropriate. Instrument response choices must expose the complete option label and selected state to assistive technology.

### Cards and navigation

- Cards use semantic surfaces and boundaries; a clickable card has one unambiguous interactive target and visible focus. Avoid nested competing links.
- Current navigation uses `aria-current="page"` plus shape/weight—not color alone. Mobile navigation must be keyboard-operable, have an accessible name, close predictably, and restore focus to its trigger.
- Logo links follow the logo alt decision tree. Icon-only controls require an accessible name and 44px target.

### Tables

- Use semantic table markup for tabular data, with header associations and a caption or nearby accessible heading.
- The default surface, row, header, selected, hover, and focus states come from table component tokens.
- At narrow widths, preserve the table's meaning inside a labeled horizontal scroll region; never let it create page-level horizontal overflow. Sticky columns must not hide focused content.
- Sorting must expose direction programmatically. Selection and status cannot rely on row color alone.

### Status badges and alerts

- Badge variants are success, warning, error, info, and neutral. Each includes a text label and optional icon. Do not present a colored dot alone.
- Alerts use a tinted surface, readable text, and a 3:1 boundary/icon treatment. Use `role="alert"` only for urgent, newly injected information; routine guidance is a labeled region or status message.
- Success, warning, error, and info are operational semantic families. They may use non-brand primitives to maintain reliable meaning and contrast.

## 6. Responsive acceptance

Every future consuming screen must be checked at exactly 320, 390, 768, and 1280 CSS px, plus 200% zoom. Device-specific snapshots may supplement but not replace these widths.

| Width | Layout contract                                                                      | Acceptance                                                                           |
| ----: | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------ |
|   320 | Single-column; 16px page inset; full-width primary controls where useful; 160px logo | No page-level horizontal scroll; labels and timers do not truncate; 44px targets     |
|   390 | Single-column; 16–20px inset; 176px logo                                             | Long choices wrap without clipping; actions remain reachable with on-screen keyboard |
|   768 | One or two columns only when reading order is preserved; 24px inset; 192px logo      | Keyboard order matches visual order; tables use contained overflow when needed       |
|  1280 | Centered container up to 1280px; readable measure up to 72ch; 220px logo             | Content does not become over-wide; dense staff tables remain scannable               |

Shared acceptance at all widths:

- No clipped focus rings, inaccessible hover-only affordances, overlapping content, or hidden validation messages.
- Logical properties support bidirectional layouts; safe-area insets are respected where fixed mobile actions exist.
- Participant assessment screens prioritize one task/question group, persistent task context, timer/state visibility, and a clear save/continue status without color-only meaning.
- Staff surfaces may be denser but retain the same contrast, keyboard, focus, target-size, and status requirements.
- Content reflows at 400% zoom where WCAG reflow applies. Essential two-dimensional data uses a contained, labeled scroll region.

## 7. React/Inertia/Tailwind 4 handoff

This stage does not perform the following work. A separately owned implementation change should:

1. Transform the JSON aliases into CSS custom properties, preserving the primitive → semantic → component graph.
2. Expose semantic aliases through the existing Tailwind 4 `@theme` setup; components consume semantic/component variables, not raw hex values.
3. Keep the existing Instrument Sans loading and React/Inertia architecture. Do not add a theme library solely for these tokens.
4. Apply light values at the root and dark values through the repository's existing `.dark` convention, with matching `color-scheme` and browser `theme-color` metadata.
5. Build or update accessible primitives for the component contracts above, including `:focus-visible`, native semantics, reduced motion, explicit image dimensions, and exact responsive checks.
6. Migrate raw colors incrementally with visual-regression coverage; do not mechanically replace colors whose meaning has not been classified.

Recommended variable mapping examples:

| JSON token                                       | Future CSS/Tailwind alias | Typical consumer  |
| ------------------------------------------------ | ------------------------- | ----------------- |
| `semantic.light.color.surface.page`              | `--color-surface-page`    | Participant shell |
| `semantic.light.color.text.primary`              | `--color-text-primary`    | Body text         |
| `semantic.light.color.action.primary.background` | `--color-action-primary`  | Primary action    |
| `component.light.input.border.focus`             | `--input-border-focus`    | Field primitive   |
| `component.light.status.warning.*`               | `--status-warning-*`      | Timer/risk badge  |

## 8. Filament/Livewire handoff

A separately owned staff-theme change should map the same semantic contract through Filament's supported theme/color APIs and Livewire component states. It should not style Filament internals by fragile generated selectors.

- Replace the current amber-first panel choice only after a reviewed mapping of ONCAM green as primary and operational status families as success/warning/danger/info.
- Keep Filament's native focus, form, table, modal, and notification semantics unless a tested override is necessary.
- Map surfaces, text, boundaries, primary actions, focus, and status families in both modes; verify dark mode independently.
- Exercise Livewire loading, disabled, validation-error, success-notification, table sorting/filtering, pagination, bulk-action, empty, and failure states.
- Use the 768 and 1280 contracts for staff-density review and the 320/390 checks for emergency/mobile access.

## 9. Acceptance and verification

### Specification acceptance

- [x] Three layers exist and semantic/component values are aliases.
- [x] Light and dark modes cover shared component families.
- [x] ONCAM green remains the primary action color; gold is constrained to accent/decorative use.
- [x] Typography uses the existing product stack.
- [x] Component default, hover, active, focus-visible, disabled, error/success/warning states are specified where applicable.
- [x] WCAG contrast decisions include text, focus, boundaries, status, failed pairs, and not-applicable disabled cases.
- [x] Logo variants, contexts, clear space, minimum/responsive sizes, aspect ratio, alt behavior, and prohibited treatments are specified.
- [x] Responsive criteria explicitly cover 320, 390, 768, and 1280.
- [x] React/Inertia/Tailwind 4 and Filament/Livewire handoffs require no new stack.

### Runtime acceptance

- [ ] Tokens imported into production CSS/theme code — out of scope for Stage 1.
- [ ] Participant and staff visual regression at all required widths — not verifiable before implementation.
- [ ] Keyboard, screen reader, axe, reduced-motion, forced-colors, zoom, and dark-mode browser checks — not verifiable before implementation.
- [ ] F2 participant instrument UI and F5 psychologist-review workflows — blocked/governed by their own acceptance plans.

## 10. Known blockers and decision status

- No approved compact, icon-only, or horizontal ONCAM lockup exists. Use accessible product text in compact placements; do not derive a new logo from the stacked artwork.
- Production token consumption, theme updates, and UI remediation require a separate file-ownership charter.
- F2 HTTP/task-manifest acceptance and F5 persistence workflow acceptance remain outside this candidate.
- No runtime screenshots or mockups are claimed because this stage intentionally changes no production surface.

**Candidate decision:** PASS for design-token/specification completeness once automated graph, contrast, logo-integrity, Markdown, and exact-scope validators pass. Runtime visual/accessibility conformance remains NOT-VERIFIABLE until a separately authorized implementation is exercised in real participant and staff browsers.
