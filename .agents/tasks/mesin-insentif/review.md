# Incentive calculation engine for Katimker per period

Adds the "Hitung Insentif" admin page that computes a weighted incentive (kehadiran + utama + tambahan) per Katimker per month, lets the admin override a per-pegawai plafon on top of the global default, and finalizes a snapshot into `incentive_results` with an audit entry. The calculation reuses existing building blocks — `AttendanceService` for raw attendance %, `RecapService` for approved task minutes, `TargetResolver` for target fallback, `ApproverMappingService::listKatimker()` for the roster — so the new service is thin orchestration plus the weighted formula. Two migrations/models carry plafon overrides and finalized results; both are keyed uniquely by (katimker, tahun, bulan). Authorization and 2FA ride on the established admin route group.

Watch for: nothing blocking. Two minor notes — the plafon/finalize endpoints don't validate the `katimker_sikep_user_id` is an active Katimker (possible, low impact — orphan override rows are ignored by `hitung()`), and total% can exceed 100 if an admin sets component weights summing above 100 (possible, mitigated by the config UI enforcing sum=100). Neither affects the confirmed requirements.

**Verdict**: APPROVED

## High-level view

The formula matches the confirmed policy exactly: each component is clamped to 0–100 (`min(100, menit/target*100)` for utama/tambahan, `max(0, min(100, …))` for kehadiran), then combined as `(kehadiran·bobot_k + utama·bobot_u + tambahan·bobot_t) / 100`, and the rupiah result is `(int) round(plafon · total% / 100)`. No pengurangan-insentif math was added — the engine consumes `AttendanceService`'s already-computed `persen_kehadiran` and does nothing more with attendance.

Target resolution is delegated to the existing `TargetResolver`, preserving the periodik → umum → global order rather than reimplementing it. Null or zero targets are handled with a null-safe `! ($target > 0)` guard that both zeroes the component and raises the `target_*_belum_diset` flag.

Plafon precedence is override-first: a period-scoped `incentive_plafon_overrides` row wins, otherwise the global `nominal_dasar`, otherwise 0. An override of 0 is honored, which is the right call for intentionally zeroing a plafon.

Finalize recomputes via `hitung()` and upserts one `incentive_results` row per Katimker keyed by period, so re-finalizing overwrites in place and row count stays stable; it writes exactly one `incentive.finalize` audit entry with the period and headcount. Simulation never persists except for plafon overrides, matching "tampilkan di layar dan simpan di database ketika final."

Authorization calls `authorize('manage', ApproverMapping::class)` on all three endpoints, and the routes sit in the `role:admin` group under the `two-factor` middleware, consistent with `IncentiveConfigController`. The Vue page follows Sakai/PrimeVue conventions with global components, `AppLayout`, Indonesian labels, and `id-ID` rupiah formatting; the menu entry and the Konfigurasi Insentif banner pointer are both in place.

<details>
<summary>Issues (2)</summary>

1. **Plafon/finalize target not role-validated** — `plafon()` and `finalkan()` accept any integer `katimker_sikep_user_id` without checking it's an active Katimker; orphan override rows are harmless (ignored by `hitung()`) but a `isKatimker()` guard would tighten it. Non-blocking.
2. **Total% unbounded above 100 on bad weights** — if an admin sets component weights summing above 100, `persen_total` can exceed 100; the config page enforces sum=100 so this is latent, not reachable through the UI. Consider clamping total to 100 defensively. Non-blocking.

</details>

<details>
<summary>Details</summary>

### Weighted formula and per-component cap

Each component is clamped before weighting. Kehadiran is `max(0.0, min(100.0, persen_kehadiran))` when available, 0 otherwise. Utama and tambahan are `min(100, menit/target*100)`. The weighted combination divides by 100, so with weights summing to 100 the total is itself bounded 0–100. `test_komponen_dibatasi_100_persen` confirms a 150% attendance input and over-target minutes all land at exactly 100, and `test_formula_berbobot_total_persen` nails the arithmetic (80·40 + 60·50 + 50·10)/100 = 67.

The one gap: `persen_total` is not independently clamped, so weights summing above 100 would push it past 100. The Konfigurasi Insentif page enforces a 100% total before enabling save, so this isn't reachable through normal use. A defensive `min(100, …)` on the total would make the invariant hold regardless of config state.

### Target fallback and null/zero handling

Resolution is delegated to `TargetResolver::efektif()`, which returns nullable `utama_menit`/`tambahan_menit` following periodik → umum → global. The calculator's `! ($targetUtama > 0)` guard is null-safe: `null > 0` is false so the flag is set and the component is zeroed, and the same holds for an explicit 0. `test_target_nol_memberi_flag_dan_persen_nol` exercises both a null and a 0 target; `test_fallback_target_individu_mengalahkan_global` confirms the periodik override beats the global target (100/100 = 100% instead of 100/1000 = 10%).

### Plafon override precedence

Overrides for the period are loaded in a single keyed query and applied as `isset($overrides[$id]) ? (int) $overrides[$id]->nominal : $nominalDasar`. Override beats global default, absence falls back to `nominal_dasar`, and both missing yields 0 — all three paths are covered by `test_plafon_override_mengalahkan_global_default` and `test_tanpa_override_dan_tanpa_global_default_plafon_nol`. Because `isset` keys on presence, a stored override of 0 is honored, which is the correct behavior for deliberately zeroing a plafon and is consistent with the `nominal` `min:0` validation.

The plafon endpoint stamps `di_set_oleh` from the acting admin's `sikep_user_id` and upserts by (katimker, tahun, bulan). It does not verify the `katimker_sikep_user_id` belongs to an active Katimker. Impact is low: `hitung()` only reads overrides for ids returned by `listKatimker()`, so an override written for a stray id is never surfaced or applied. Adding an `isKatimker()` check would avoid accumulating dead rows, but it isn't a correctness problem.

### Rounding to whole rupiah

`insentif = (int) round($plafon * $persenTotal / 100)`. `test_pembulatan_ke_rupiah_penuh` drives a non-round product (1,000,000 · 33.33%) and asserts both the integer type and the exact 333,300 result.

### Finalize snapshot and audit

```
hitung() ──► rows ──► updateOrCreate(incentive_results) by (katimker,tahun,bulan)
                 └──► AuditLogger::record('incentive.finalize', meta{tahun,bulan,jumlah_pegawai})
```

`finalkan()` recomputes through `hitung()` so the finalized snapshot can't drift from the displayed simulation, upserts each Katimker's row stamping `difinalkan_oleh`/`difinalkan_pada`, and records a single audit entry. The migration stores a full snapshot (percentages, minutes, targets, weights, plafon, insentif), so a finalized result stays interpretable even if config or targets change later. `test_finalkan_upsert_dan_audit` confirms re-finalizing overwrites in place (count stays 1) and that one `incentive.finalize` audit row is written.

### Authorization and 2FA posture

The three actions authorize `manage` on `ApproverMapping::class`, whose policy `before()` grants admins and denies everyone else (`test_non_admin_ditolak` gets a 403). The routes are nested under the `['auth','active-role','two-factor']` stack; `EnsureTwoFactor` forces unconfirmed admins to setup on every non-exempt route, so the endpoints inherit 2FA without their own middleware — the same protection as `IncentiveConfigController`. One thing to note: 2FA is enforced by the shared group middleware, not per-route, so the new routes are only as protected as that group stays configured.

### Vue page and navigation

`HitungInsentif.vue` uses `<script setup>`, `defineOptions({ layout: AppLayout })`, globally-registered PrimeVue components (`DataTable`, `Column`, `InputNumber`, `Select`, `Tag`, `Button`), `useToast`/`useConfirm`, and the `route()` helper — the same shape as `KonfigurasiInsentif.vue`. Rupiah is formatted via `Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR' })` and the editable plafon uses `InputNumber` in IDR currency mode with `maxFractionDigits:0`. The "Belum tersedia" and "Target belum diset" tags are driven by the service flags. Finalize is gated behind a `useConfirm` dialog warning that re-finalization overwrites. The menu entry sits directly after Konfigurasi Insentif, and the Konfigurasi Insentif banner now links to the calculation page.

### Verification evidence

The coder recorded `php artisan test --filter="IncentiveCalculator|IncentiveCalculationController"` → 14 passed (52 assertions), the full suite → 157 passed (591 assertions, 0 failures), and `npm run build` → success with the `HitungInsentif` chunk emitted. Tests run on SQLite in-memory with `AttendanceService` swapped via the container, so no live SIKEP connection is needed. Reading the two test files confirms they cover the formula, per-component cap, target fallback, plafon precedence (all three paths), rounding, null/zero target flags, unavailable attendance, finalize upsert/overwrite, audit entry, and controller authorization. Evidence is sufficient; no re-run performed.

### Not tested

- Weights summing above 100 (the unclamped-total case) — not exercised, but unreachable via the config UI.
- Plafon/finalize with a non-Katimker `katimker_sikep_user_id` — not exercised; orphan rows are inert.

</details>

<details>
<summary>File map</summary>

- `app/Services/IncentiveCalculatorService.php` — new engine: `hitung()` weighted simulation + `finalkan()` upsert/audit.
- `app/Http/Controllers/Admin/IncentiveCalculationController.php` — index/plafon/finalkan with authorize + period resolution.
- `app/Models/IncentivePlafonOverride.php`, `app/Models/IncentiveResult.php` — new models with casts and property docs.
- `database/migrations/2026_10_09_100001_create_incentive_plafon_overrides_table.php` — override table, unique (katimker,tahun,bulan).
- `database/migrations/2026_10_09_100002_create_incentive_results_table.php` — finalized snapshot table, same unique key.
- `resources/js/Pages/Admin/HitungInsentif.vue` — Sakai/PrimeVue results page with editable plafon and finalize confirm.
- `resources/js/Pages/Admin/KonfigurasiInsentif.vue` — banner now links to the calculation page.
- `resources/js/layout/AppMenu.vue` — "Hitung Insentif" nav entry (file also reflowed).
- `routes/web.php` — three admin routes registered in the role:admin + 2FA group.
- `tests/Feature/IncentiveCalculatorTest.php`, `tests/Feature/IncentiveCalculationControllerTest.php` — 14 new tests.

Full diff: `git show f3fac30`.

</details>
