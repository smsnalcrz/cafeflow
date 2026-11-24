# CafeFlow – Full Bug-Hunt Review (Read-Only)

**Generated:** 2026-11-24  
**Scope:** Static review of all PHP/JS/Python/SQL assets. No source files modified.  
**Tests executed:** `php tools/test_waste_suite.php` → 14/14 passed. PHP/JS/Python lint all pass.

---

## 1. Highest-Impact Finding (Primary Issue)

### BUG-1: Sales reports include synthetic (mock) transactions in official totals

- **File:** `api/report.php`
- **Severity:** High (data integrity / financial accuracy)
- **Evidence:**
  - README states: "All official sales reports, accounting audits, and financial statements default strictly to live records (`is_mock = 0`)."
  - `report.php` summary query (`lines ~195-207`) aggregates `COUNT`/`SUM(total_amount)` for **all** `Completed` transactions without any `is_mock` filter:
    ```sql
    COALESCE(SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END), 0) AS total
    ```
  - Only `mock_orders`/`real_orders` counts are split out for display; the monetary totals still include mock rows.
  - The same unfiltered aggregation propagates to top-sellers, daily/hourly breakdowns, and `dataHealth`.
- **Impact:** If an operator runs `core/seed_2025_ml_data.php` (7,521 synthetic transactions), the Sales Report workspace and 7-sheet Excel export will report inflated revenue, order counts, and AOV, corrupting financial audits.
- **Recommended fix (not applied):** Add `AND (is_mock = 0 OR is_mock IS NULL)` to every report aggregation (summary, top items, breakdowns, hourly), or introduce an explicit `include_mock` query flag defaulting to `0`, consistent with README. Keep predictive endpoints unfiltered (they intentionally consume mock training data).

---

## 2. Additional Findings

### BUG-2: Missing legacy Excel fallback file referenced at runtime
- **File:** `api/export_excel.php:144-147`
- **Severity:** Medium
- **Evidence:** On generation failure with `?legacy=1`, code does `require_once __DIR__ . '/../core/legacy_excel_fallback.php';` but that file does not exist in the repository (`Test-Path` → False).
- **Impact:** A 500 fatal error instead of a graceful fallback when legacy export is requested and the Python engine fails.
- **Recommended fix:** Create the fallback file or remove the `require_once` branch and return the JSON error already prepared.

### BUG-3: Interpolated (non-parameterized) integer identifiers
- **Files:** `api/inventory_api.php:187`, `api/waste_api.php:427` (`LIMIT {$limit} OFFSET {$offset}`)
- **Severity:** Low (currently safe due to `as_int` casting, but fragile)
- **Evidence:** `SELECT is_active ... WHERE item_id = $id` and `LIMIT {$limit} OFFSET {$offset}` interpolate integers directly.
- **Impact:** No current injection (values are validated/cast), but the pattern bypasses prepared statements and is a maintenance hazard; a future refactor could introduce injection.
- **Recommended fix:** Use bound parameters for all identifiers, including LIMIT/OFFSET.

### BUG-4: Report default includes synthetic data in top-sellers & breakdowns
- **File:** `api/report.php` (top-selling items, daily/monthly breakdown queries)
- **Severity:** High (same root cause as BUG-1)
- **Evidence:** Top-item and day-breakdown queries filter only by date range, not `is_mock`.
- **Recommended fix:** Same as BUG-1.

### BUG-5: Default seed credentials shipped in source
- **File:** `core/db.php:328-340`
- **Severity:** Medium (operational security)
- **Evidence:** When `users` is empty, seeds `barista/barista123` and `manager/manager123`.
- **Impact:** Deployments that skip credential rotation expose default accounts.
- **Recommended fix:** Keep for local dev but add a deployment checklist / env-gated seeding; optionally force password change on first login.

### BUG-6: Empty DB password default
- **File:** `core/db.php:11`
- **Severity:** Low (dev default, expected for XAMPP)
- **Evidence:** `DB_PASS` defaults to `''`.
- **Recommended fix:** Document production override via `DB_PASS` env var (already supported); add a startup warning if running with empty password in non-dev mode.

### BUG-7: Runtime schema migrations on the request path
- **File:** `core/db.php` (`ensure_schema()`, called from `db()`)
- **Severity:** Medium (performance/reliability)
- **Evidence:** First connection per process runs many `ALTER TABLE`/`CREATE TABLE`/`INSERT` statements; failures are silently caught and logged.
- **Impact:** Added latency on cold starts; partial migrations can leave schema inconsistent without surfacing errors to callers.
- **Recommended fix:** Move migrations to a versioned CLI migration tool run at deploy time; keep `ensure_schema` as a no-op guard or remove.

### BUG-8: Inventory `update` action lacks duplicate-name guard
- **File:** `api/inventory_api.php:109-128`
- **Severity:** Low
- **Evidence:** `add` checks case-insensitive duplicates; `update` relies only on `duplicate_name_guard` (DB 1062), which fires only if a UNIQUE constraint exists. The `inventory.name` column has no UNIQUE constraint per schema, so renames can create duplicates.
- **Recommended fix:** Add the same case-insensitive duplicate check in `update` before executing.

### BUG-9: Manual waste unit mismatch on matched inventory
- **File:** `api/manual_waste.php:121-135`
- **Severity:** Low
- **Evidence:** When a waste entry matches an inventory item, `$unit` is overwritten with the inventory unit but `$amountFloat` is not converted. Entering an amount in a different unit silently records the wrong quantity.
- **Recommended fix:** Either reject mismatched units or convert; at minimum warn the user.

### BUG-10: Static user cache across role changes within a request
- **File:** `core/auth.php:67-88`
- **Severity:** Low
- **Evidence:** `current_user()` caches `$cachedUser` statically for the request. If an account's role/status changes mid-request (e.g., via another API call in the same request), stale data may be used. Unlikely in practice but a correctness hazard.
- **Recommended fix:** Invalidate cache on role/status updates or drop the static cache.

### BUG-11: `__pycache__` present in working tree
- **File:** `core/__pycache__/excel_generator.cpython-314.pyc`
- **Severity:** Trivial (gitignored)
- **Evidence:** Directory exists locally (not tracked).
- **Recommended fix:** Remove locally; already gitignored.

### BUG-12: No GitHub CLI for automated issue publishing
- **Environment:** `gh` not installed
- **Severity:** Tooling
- **Evidence:** `Get-Command gh` returns nothing.
- **Recommended fix:** Install/authenticate `gh` if automated publishing is desired.

---

## 3. Areas Reviewed With No Issues Found

- All 28 PHP files pass `php -l`.
- All 21 JS files pass `node --check`.
- Both Python files pass `py_compile`.
- Waste tracking suite: 14/14 passed.
- SQL queries use prepared statements with bound parameters (except noted interpolations).
- CSRF validation present on all mutating endpoints.
- RBAC enforced on protected endpoints.
- Passwords hashed with `password_hash`/`password_verify`.

---

## 4. Suggested Priority Order

1. BUG-1 / BUG-4 (report totals include synthetic data) – fix before any financial audit.
2. BUG-2 (missing fallback file) – fix before exposing legacy export.
3. BUG-7 (migrations on request path) – address for production hardening.
4. BUG-5, BUG-6 (credential defaults) – deployment checklist.
5. Remaining low-severity items – opportunistic cleanup.

---

*End of bug-hunt review. No source code was modified.*
