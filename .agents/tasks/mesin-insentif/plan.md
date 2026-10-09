# Implementation Plan — Mesin Perhitungan Insentif

Activates the deferred incentive calculation. Adds a results/calculation page ("Hitung Insentif")
where an admin computes, per-Katimker per-period, the weighted incentive (kehadiran + utama +
tambahan), edits a per-pegawai plafon override, and finalizes the result into the database.

Target app: Laravel 13 + Inertia + Vue 3 + PrimeVue (Sakai) at `f:\LARAVEL\remunisasi_v2`.
Work on the mainline branch. Match existing Indonesian naming/comments and Sakai/PrimeVue/Tailwind style.

## Design decisions (grounded in the codebase)

- **Katimker enumeration + nama + no_absen**: reuse `App\Services\ApproverMappingService::listKatimker()`.
  It returns a collection of `['sikep_user_id','nama','username','no_absen','kabid_*']` from the local
  `users` table (role=katimker, aktif=true). `RecapController::admin()` already uses exactly this to build
  cross-Katimker rows, so names come from the local DB — no live SIKEP query is needed to list people.
  Rationale: this is the established pattern; reusing it keeps names consistent and avoids a new SIKEP read path.
- **Target fallback resolution**: reuse the existing `App\Services\TargetResolver::efektif($id,$tahun,$bulan)`.
  It already implements individu_periodik → individu_umum → global exactly as required (verified in
  `IndividualTargetTest`). Do NOT reimplement target resolution in the calculator.
  Rationale: single source of truth; already unit-tested.
- **Attendance component**: use `AttendanceService::nilai($sikepUserId,$noAbsen,$tahun,$bulan)` and read
  `persen_kehadiran` directly (already raw-capped 0–100 by that service). Treat `tersedia=false` as a
  "kehadiran belum tersedia" flag and kehadiran%=0. Do NOT add pengurangan math — AttendanceService already
  computed persen_kehadiran; the calculator just consumes it.
- **DI for testability**: inject `AttendanceService`, `RecapService`, `TargetResolver` into
  `IncentiveCalculatorService` via constructor (container-resolved). Tests swap AttendanceService with
  `$this->mock(AttendanceService::class, ...)` / `app()->instance(...)` so no live SIKEP DB is needed —
  same approach already used for `RoleResolver` across the Feature tests.
- **Period default**: follow `RecapController::periode()` — default `tahun=now()->year`, `bulan=now()->month`,
  accept `?tahun=&bulan=` query params, clamp bulan 1–12. (Period model's open status is informational; the
  results page is period-agnostic and does not require an open Period row.)
- **Authorization**: every endpoint calls `$this->authorize('manage', ApproverMapping::class)` and sits in the
  existing `role:admin` + 2FA admin route group, matching `IncentiveConfigController`.
- **Rounding / currency**: all rupiah are integers. `insentif = (int) round($plafon * $persenTotal / 100)`.
  Percentages stored as `decimal(6,2)`; nominal as `unsignedBigInteger`.
- **Finalize semantics**: finalize upserts `incentive_results` rows by `(katimker_sikep_user_id,tahun,bulan)`
  (re-finalize overwrites) and writes one audit entry. Plafon overrides persist independently of finalize.
- **Scope boundary**: this is one cohesive feature (service + controller + 2 migrations/models + one Vue page +
  nav/banner tweaks + tests), all mutually dependent; it is implemented in dependency order below.

## Formula (per Katimker, per period)

Weights from `IncentiveConfig::global()` (default kehadiran 40 / utama 50 / tambahan 10, sum 100).

- `kehadiran% = tersedia ? persen_kehadiran : 0` (cap 0–100).
- `utama% = (target_utama_menit > 0) ? min(100, utama_menit / target_utama_menit * 100) : 0`
  (flag `target_utama_belum_diset` when target null/0).
- `tambahan% = (target_tambahan_menit > 0) ? min(100, tambahan_menit / target_tambahan_menit * 100) : 0`
  (flag `target_tambahan_belum_diset` when target null/0).
- `total% = (kehadiran% * bobot_kehadiran + utama% * bobot_utama + tambahan% * bobot_tambahan) / 100`.
- `plafon = override (incentive_plafon_overrides for period) ?? IncentiveConfig::global()->nominal_dasar ?? 0`.
- `insentif_rupiah = (int) round(plafon * total% / 100)`.

---

# Implementation Plan

- [ ] 1. Create migration for `incentive_plafon_overrides` following the existing migration style
      (anonymous class, Indonesian doc comment, `unsignedInteger` for sikep ids, `smallInteger` tahun /
      `tinyInteger` bulan, `unsignedBigInteger` nominal rupiah, `unique([...])`).
      Columns: `id`, `katimker_sikep_user_id` (unsignedInteger, index), `tahun` (smallInteger),
      `bulan` (tinyInteger), `nominal` (unsignedBigInteger), `di_set_oleh` (unsignedInteger nullable),
      `timestamps`, `unique(['katimker_sikep_user_id','tahun','bulan'])`.
      Files: `database/migrations/2026_10_09_100001_create_incentive_plafon_overrides_table.php`
      (use the next available timestamp after the latest existing migration).
      Verify: `php artisan migrate:fresh --env=testing` runs without error (or `php artisan test` in step 11,
      which runs RefreshDatabase migrations).

- [ ] 2. Create migration for `incentive_results` in the same style.
      Columns: `id`, `katimker_sikep_user_id` (unsignedInteger, index), `tahun` (smallInteger),
      `bulan` (tinyInteger), `persen_kehadiran` `decimal(6,2)`, `persen_utama` `decimal(6,2)`,
      `persen_tambahan` `decimal(6,2)`, `persen_total` `decimal(6,2)`, `nominal_plafon` (unsignedBigInteger),
      `nominal_insentif` (unsignedBigInteger), `utama_menit` (unsignedInteger), `tambahan_menit`
      (unsignedInteger), `target_utama_menit` (unsignedInteger nullable), `target_tambahan_menit`
      (unsignedInteger nullable), `bobot_kehadiran`/`bobot_utama`/`bobot_tambahan` (unsignedTinyInteger),
      `difinalkan_oleh` (unsignedInteger nullable), `difinalkan_pada` (timestamp nullable), `timestamps`,
      `unique(['katimker_sikep_user_id','tahun','bulan'])`.
      Files: `database/migrations/2026_10_09_100002_create_incentive_results_table.php`.
      Verify: migrations run clean (covered by step 11 test run).

- [ ] 3. Create models `IncentivePlafonOverride` and `IncentiveResult` with `$fillable`, `casts()`, and an
      Indonesian doc comment with `@property` annotations consistent with `IndividualTarget`/`IncentiveConfig`.
      Casts: ids/menit/bobot → integer, tahun/bulan → integer, nominal_* → integer, persen_* → decimal:2 (or
      float), `difinalkan_pada` → datetime. Fillable mirrors the migration columns (minus `id`/timestamps).
      Files: `app/Models/IncentivePlafonOverride.php`, `app/Models/IncentiveResult.php`.
      Verify: `php artisan test --filter=ExampleTest` boots the app with no autoload/class errors (or rely on step 11).

- [ ] 4. Create `App\Services\IncentiveCalculatorService` with constructor DI of `RecapService`,
      `AttendanceService`, `TargetResolver` (container-resolved, so AttendanceService is swappable in tests).
      Method `hitung(int $tahun, int $bulan): \Illuminate\Support\Collection` that:
      (a) lists Katimker via `ApproverMappingService::listKatimker()` (inject it too) to get
      `sikep_user_id`/`nama`/`no_absen`;
      (b) uses `RecapService::forKatimkerList($ids,$tahun,$bulan)` ONCE to avoid N+1 on the app DB (keyed by id);
      (c) loads all plafon overrides for the period in ONE query keyed by katimker id;
      (d) reads `IncentiveConfig::global()` once for bobot + nominal_dasar default;
      (e) per person: calls `AttendanceService::nilai()` (per-person SIKEP call is acceptable) and
      `TargetResolver::efektif()`, then computes kehadiran%/utama%/tambahan%/total% and insentif rupiah per the
      Formula section, with flags `kehadiran_tersedia`, `target_utama_belum_diset`, `target_tambahan_belum_diset`;
      returns rows: `katimker_sikep_user_id, nama, persen_kehadiran, kehadiran_tersedia, utama_menit,
      target_utama_menit, persen_utama, tambahan_menit, target_tambahan_menit, persen_tambahan, persen_total,
      nominal_plafon, nominal_insentif, bobot_kehadiran, bobot_utama, bobot_tambahan`, plus the flags.
      Add `finalkan(int $tahun, int $bulan, ?int $aktorSikepUserId): int` that recomputes via `hitung()`,
      upserts each row into `incentive_results` (`updateOrCreate` by period+katimker, stamping `difinalkan_oleh`
      + `difinalkan_pada`), records ONE audit entry via `AuditLogger::record(..., 'incentive.finalize', ...)`
      with `['tahun','bulan','jumlah_pegawai']`, and returns the row count.
      Files: `app/Services/IncentiveCalculatorService.php`.
      Verify: `php artisan test --filter=IncentiveCalculator` passes (tests added in step 10).

- [ ] 5. Create `App\Http\Controllers\Admin\IncentiveCalculationController` with constructor DI of
      `IncentiveCalculatorService` and `AuditLogger`. `index(Request)`: authorize `manage` on
      `ApproverMapping::class`; resolve `[$tahun,$bulan]` from query (default now, clamp bulan 1–12, same logic as
      `RecapController::periode()`); render Inertia `Admin/HitungInsentif` with props `tahun`, `bulan`,
      `baris` (= `$calc->hitung($tahun,$bulan)`), `nominal_dasar` (global default), and `difinalkan` (bool: any
      `incentive_results` row exists for the period). `plafon(Request)`: authorize; validate
      `katimker_sikep_user_id` required integer, `tahun` 2000–2100, `bulan` 1–12, `nominal` required integer >= 0;
      `IncentivePlafonOverride::updateOrCreate` by period+katimker with `di_set_oleh = user->sikep_user_id`;
      `back()->with('success', ...)`. `finalkan(Request)`: authorize; validate `tahun`/`bulan`; call
      `$calc->finalkan($tahun,$bulan,$user->sikep_user_id)`; `back()->with('success', 'Insentif periode ... difinalkan.')`.
      Files: `app/Http/Controllers/Admin/IncentiveCalculationController.php`.
      Verify: covered by controller Feature test in step 11.

- [ ] 6. Register routes in the existing `role:admin`/`admin.` group in `routes/web.php`, alongside the
      `insentif.*` routes (import the new controller at top). Add:
      `GET /admin/hitung-insentif` → `index` name `insentif.hitung.index`;
      `POST /admin/hitung-insentif/plafon` → `plafon` name `insentif.hitung.plafon`;
      `POST /admin/hitung-insentif/finalkan` → `finalkan` name `insentif.hitung.finalkan`.
      Files: `routes/web.php`.
      Verify: `php artisan route:list --name=insentif.hitung` lists all three routes.

- [ ] 7. Create the Inertia page `resources/js/Pages/Admin/HitungInsentif.vue` matching `KonfigurasiInsentif.vue`
      style (`<script setup>`, `defineOptions({ layout: AppLayout })`, PrimeVue auto-imported components, Tailwind
      `card`/grid classes, `useToast`, `@inertiajs/vue3` `router`/`useForm`, `route()` helper, `ConfirmDialog`
      + `useConfirm`). Props: `tahun`, `bulan`, `baris`, `nominal_dasar`, `difinalkan`.
      UI: period selector (bulan Select/Dropdown 1–12 + tahun InputNumber) with a "Hitung ulang" button that does
      `router.get(route('admin.insentif.hitung.index'), { tahun, bulan }, { preserveState:false })`.
      `DataTable` of `baris` with columns: Nama; Kehadiran % (show Tag "Belum tersedia" when `!kehadiran_tersedia`);
      Utama % with `utama_menit/target_utama_menit` subtext and Tag "Target belum diset" when
      `target_utama_belum_diset`; Tambahan % similarly; Total %; Plafon (editable `InputNumber` mode currency IDR
      locale id-ID, prefilled from row `nominal_plafon`) with a per-row save that posts `admin.insentif.hitung.plafon`
      then refreshes; Insentif (rupiah, formatted `id-ID`). A "Finalkan" button guarded by `useConfirm`
      ConfirmDialog that posts `admin.insentif.hitung.finalkan`; success toast + show the period is finalized (badge
      from `difinalkan`). Format rupiah via `new Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR', minimumFractionDigits:0 })`.
      Files: `resources/js/Pages/Admin/HitungInsentif.vue`.
      Verify: `npm run build` compiles with no errors (step 12).

- [ ] 8. Add the nav entry in the Administrasi menu group in `resources/js/layout/AppMenu.vue`, directly after the
      existing `{ label: 'Konfigurasi Insentif', ... route: 'admin.insentif.index' }` item:
      `{ label: 'Hitung Insentif', icon: 'pi pi-fw pi-calculator', route: 'admin.insentif.hitung.index' }`.
      Files: `resources/js/layout/AppMenu.vue`.
      Verify: `npm run build` succeeds (step 12); nav item resolves the named route.

- [ ] 9. Update the info banner in `resources/js/Pages/Admin/KonfigurasiInsentif.vue` so it points users to the
      new calculation page (e.g. keep it `<Message severity="info">` but reword to say the parameters are now used
      by the "Hitung Insentif" page, with a `Link`/`router` navigation to `admin.insentif.hitung.index`). Keep the
      Sakai styling.
      Files: `resources/js/Pages/Admin/KonfigurasiInsentif.vue`.
      Verify: `npm run build` succeeds (step 12).

- [ ] 10. Add focused unit/feature tests for `IncentiveCalculatorService` in
      `tests/Feature/IncentiveCalculatorTest.php` (use `RefreshDatabase`; swap `AttendanceService` via
      `$this->mock(AttendanceService::class, ...)` returning controlled `['tersedia'=>..,'persen_kehadiran'=>..]`;
      seed Katimker as local `User` rows role=katimker aktif with `no_absen`; seed approved `TaskReport` rows via
      factory for utama/tambahan menit; set `IncentiveConfig::global()` bobot/target/nominal_dasar; seed
      `IndividualTarget` rows to exercise fallback). Cover:
      (a) weighted total% formula with known inputs;
      (b) each component capped at 100%;
      (c) target fallback individu_periodik > individu_umum > global (via TargetResolver);
      (d) plafon override beats global default; absence of override uses global default; both missing → 0;
      (e) rounding to whole rupiah (assert integer result of a non-round product);
      (f) null/zero target → component% = 0 and the `target_*_belum_diset` flag set;
      (g) `kehadiran_tersedia=false` → kehadiran%=0;
      (h) `finalkan()` upserts `incentive_results` (re-finalize overwrites, count stable) and writes one
      `audit_logs` row with `aksi='incentive.finalize'`.
      Check `tests/Feature/IncentiveConfigTest.php` and `IndividualTargetTest.php` for the acting-as-admin +
      RoleResolver mock pattern if adding a controller test (`test_admin_membuka_hitung_insentif`,
      `test_non_admin_ditolak`, `test_simpan_plafon_override`, `test_finalkan_menyimpan_hasil`).
      Files: `tests/Feature/IncentiveCalculatorTest.php` (and optionally
      `tests/Feature/IncentiveCalculationControllerTest.php`).
      Verify: `php artisan test --filter=IncentiveCalculator` passes all new assertions.

- [ ] 11. Run the full backend suite and confirm no regressions.
      Files: none (verification step).
      Verify: `php artisan test` — all tests pass; record the final test count (baseline note: ~75 tests passed
      before this task) in the verification note at the end of this file.

- [ ] 12. Build the frontend and confirm it compiles.
      Files: none (verification step).
      Verify: `npm run build` completes with no errors; record the build result in the verification note.

- [ ] 13. Record the verification outcome (final `php artisan test` count + `npm run build` result) at the bottom
      of this plan file under "Verification note".
      Files: `f:\LARAVEL\remunisasi_v2\.agents\tasks\mesin-insentif\plan.md`.
      Verify: the note is present and reflects the actual command output.

## Notes / assumptions

- Names for the results table come from the local `users` table (via `ApproverMappingService::listKatimker()`),
  consistent with `RecapController`; a live SIKEP connection is only needed by `AttendanceService` at compute time
  and is swapped out in tests.
- The results page is a simulation until "Finalkan"; only plafon overrides persist before finalize. This matches
  the user's requirement "tampilkan di layar dan simpan di database ketika final" plus the explicit plafon-override
  persistence requirement.
- Pint formatting: if the project runs `./vendor/bin/pint`, new PHP files should pass it; follow the existing code
  style regardless.

## Verification note

- `php artisan test` → 157 passed (591 assertions), 0 failures. 23 new assertions from the
  two new suites (`IncentiveCalculatorTest` 9 tests, `IncentiveCalculationControllerTest` 5 tests).
- `npm run build` → success (vite v8.1.3, 1107 modules; `HitungInsentif-*.js` chunk emitted, 6.27 kB).
- Run on Windows / PHP sqlite in-memory (RefreshDatabase); AttendanceService swapped via the container
  in tests so no live SIKEP connection is used.
