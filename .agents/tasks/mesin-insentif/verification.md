# Verification — Mesin Perhitungan Insentif

## Commands run

- `php artisan test --filter="IncentiveCalculator|IncentiveCalculationController"`
  → 14 passed (52 assertions).
- `php artisan test` (full suite)
  → **157 passed (591 assertions)**, 0 failures. Baseline before this task was ~75 tests; the suite
  has grown over prior tasks and this task adds 14 tests / 23+ assertions.
- `npm run build`
  → **success** (vite v8.1.3, 1107 modules transformed). New page chunk `HitungInsentif-*.js` (6.27 kB)
  emitted; no errors.

## Environment

- SQLite in-memory via `RefreshDatabase`.
- `AttendanceService` is resolved from the container and swapped with a Mockery fake in both new test
  suites, so no live SIKEP database is required for the calculation tests.

## New tests

`tests/Feature/IncentiveCalculatorTest.php` covers:
- weighted total% formula with known inputs;
- each component capped at 100%;
- target fallback (individu periodik > individu umum > global) via `TargetResolver`;
- plafon override beats global default; no override uses global default; both missing → 0;
- rounding to whole rupiah (non-round product → integer);
- null/zero target → component% = 0 and `target_*_belum_diset` flag set;
- `kehadiran_tersedia = false` → kehadiran% = 0;
- `finalkan()` upserts `incentive_results` (re-finalize overwrites, count stable) + one
  `audit_logs` row with `aksi = incentive.finalize`.

`tests/Feature/IncentiveCalculationControllerTest.php` covers:
- admin opens `Admin/HitungInsentif` (Inertia props present);
- non-admin forbidden;
- plafon override saved (with `di_set_oleh`);
- plafon rejects negative nominal;
- finalize writes `incentive_results`.
