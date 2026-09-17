# F5 psychologist review fixture visual evidence

Captured from the accepted testing-only fixture at integration commit
`e1cdf73` and reviewed before canonical acceptance commit `d1f2ea3`.
These images are QA evidence only: they do not establish production review,
persistence, signing, publishing, T-15, or T-22 completion.

| Artifact | Viewport/state | SHA-256 |
|---|---|---|
| `f5-internal-320.png` | 320 px, internal psychologist evidence | `3e2dc6465a5458852a43f3f3fd501d85050108f3f1bb98bfe05626a1200c7a73` |
| `f5-internal-1280.png` | 1280 px, internal psychologist evidence | `b65a6323344e6a8fb2ffc8dda234f0e5b905d9ee6a50ee697c433b31d1b9d8b2` |
| `f5-v3-1280.png` | 1280 px, V3 stop state | `aaccaa92ef772614f7aabd4201cb1468e34574098ef0b8edce61d031a6330f15` |

The browser gate also checked 390 px and 768 px geometry, computed styles for
the synthetic warning, G7 review state, form controls, table containment,
primary validation control, and V3 stop state, with no page-level overflow,
unexpected external request, console error, or non-Livewire write.
