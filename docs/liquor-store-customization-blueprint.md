# NexoPOS — Liquor Store (India) Customization Blueprint

**Status:** Forensic review & implementation plan — no implementation yet.
**Branch:** `claude/liquor-store-pos-blueprint-q3vo4n` (master untouched)
**Scope:** Wholesale/retail liquor store in India. Priorities: inventory, sales, purchases, accounting, reconciliation, stock accuracy.

---

## 1. Current Architecture Review

**Stack.** Laravel 12 (PHP ≥ 8.2), Vue 3 + TailwindCSS (Vite), MySQL by default (`config/database.php`), SQLite used by the test suite. Broadcasting via Reverb/Pusher, API auth via Sanctum, barcode generation via `picqer/php-barcode-generator`, PDF via dompdf, spreadsheets via PhpSpreadsheet, DB snapshots via `spatie/laravel-db-snapshots`.

**Application shape.** NexoPOS is service-oriented: nearly all business logic lives in `app/Services` (`ProductService`, `OrdersService`, `ProcurementService`, `TransactionService`, `ReportService`, `TaxService`, `CurrencyService`, …). Controllers are thin. A generic CRUD engine (`app/Crud`, ~40 resources) generates list tables, forms, and exports. A hook system (`tormjens/eventy`, used as `Hook::filter(...)`) plus a first-class **module system** (`ModulesService`) allow extension without editing core. Settings are a key–value `Options` store. Dashboard widgets are per-user configurable.

**Core domain tables** (all prefixed `nexopos_`, defined in `database/migrations/create|core|update`):

| Domain | Tables |
|---|---|
| Catalog | `products`, `products_categories` (hierarchical via `parent_id`), `products_unit_quantities` (stock + prices per unit), `units`, `units_groups`, `taxes`, `taxes_groups` |
| Inventory ledger | `products_histories` (every movement, with before/after qty), `products_histories_combined` (daily rollup), `products_adjustments` + `products_adjustment_items` (draft→performed batches) |
| Purchasing | `procurements`, `procurements_products`, `providers` (with `amount_due` / `amount_paid`) |
| Sales | `orders`, `orders_products`, `orders_payments`, `orders_taxes`, `orders_coupons`, `orders_instalments`, `orders_refunds`, `orders_products_refunds`, `payments_types` (extensible) |
| Customers | `customers` (with `owed_amount`, `account_amount`), `customers_account_history`, groups, rewards, coupons |
| Accounting | `transactions_accounts` (chart of accounts + sub-accounts), `transactions`, `transactions_histories` (journal), `transactions_actions_rules` (event→account posting rules), day/month balance rollups |
| Cash | `registers`, `registers_history` (open/close/cash-in/cash-out/payments/change/refunds) |
| Security | `roles`, `permissions`, `role_permission`, `permissions_access` (temporary elevated-permission grants with expiry) |

**Inventory engine.** Stock is held per product **per unit** in `products_unit_quantities.quantity`. Every change funnels through `ProductService::stockAdjustment()` → `increase/reduceUnitQuantities()` → `recordStockHistory()` (`app/Services/ProductService.php:1224–1611`). Each movement writes a permanent `ProductHistory` row: operation type, quantity, `before_quantity`, `after_quantity`, unit price, total price, author, links to order/procurement lines. Negative stock is blocked (`preventNegativity`). Operation types cover: `procured, sold, returned, defective, lost, added, removed, incoming/outgoing-transfer, convert-in/out, set` (`app/Models/ProductHistory.php`). Unit conversion between units of a group is supported (`convertUnitQuantities`), enabling case ⇄ bottle. Optional per-procurement-line tracking (`accurate_tracking` + `procurements_products.available_quantity`) gives batch-level depletion. Expiry is supported (`expires`, `on_expiration=prevent_sales`, `expiration_date` per procurement line / unit quantity).

**Purchasing.** `ProcurementService` creates procurements (supplier, invoice reference, invoice date, per-line gross/net purchase price, tax group, quantity, expiry, barcode), stocks them on delivery (`handleProcurement`), maintains provider `amount_due`/`amount_paid`, and posts accounting entries. Payment status is **binary paid/unpaid**.

**Sales.** `OrdersService` is a complete POS engine: walk-in ("default customer") and registered customers, hold orders, layaway/instalments, **split payments**, DB-driven payment types (cash/bank/account by default — UPI/Card/Cheque can be added in settings), per-line and per-order discounts, coupons, rewards, customer credit accounts, full/partial refunds with damaged/good condition flags, voids (stock returned, record kept), receipts/invoices with reprint, Z-report per register session. Wholesale vs retail price per unit quantity is built in. Orders store `total_cogs` and per-line `total_purchase_price` for profit reporting.

**Accounting.** Rule-based posting: `transactions_actions_rules` map events (order paid, unpaid→paid, void, refund, COGS; procurement paid/unpaid/unpaid→paid; customer account credit/debit; register cash in/out) onto a 5-category chart of accounts (Assets 1000 / Liabilities 2000 / Equity 3000 / Revenues 4000 / Expenses 5000 — `app/Providers/AppServiceProvider.php:375`) with debit/credit semantics and an offset-account "reflection" per entry (double-entry style). Expenses are `transactions` (direct, scheduled, recurring). COGS is posted when a sale is paid (`TransactionService::handleCogsFromSale`).

**Reporting** (`routes/web/reports.php` + `ReportService`): sales, sales progress, sold stock, profit (uses per-line purchase price), transactions/expenses, annual report, payment types, customer statement, low stock, combined daily stock history. Plus CRUD-based ledgers: global product history, register history, provider procurements. Dashboard KPIs precomputed into `dashboard_days`/`dashboard_months` by listeners.

**Frontend.** POS screen is a Vue SPA (`resources/ts`), receipts/invoices are Blade + Vue (`resources/views/pages/dashboard/orders/templates`), print via browser. Currency formatting is done twice: PHP `CurrencyService::format()` and TS `resources/ts/filters/currency.ts` (currency.js).

**Quality safety net.** ~71 feature test classes cover orders, procurement, accounting, taxes, registers, CRUD permissions. This is a real asset: changes can be verified.

---

## 2. What Already Satisfies Your Needs

| Requirement | Status |
|---|---|
| Permanent stock movement ledger with before/after, operator, reference | ✅ `products_histories` — exactly your "bank statement" (per row: date, document links, movement type, qty, before → after balance, author) |
| Never silently modify stock | ✅ All paths go through `stockAdjustment()`; even "set quantity" writes an added/removed history row |
| Opening stock | ✅ Via "Stock Adjustment → add" or an initial procurement (both leave ledger entries) |
| Purchases with supplier, invoice no./date, per-line cost, GST group | ✅ Procurements |
| Sales: walk-in + regular customers, discounts, split payments, UPI/Card/Cash/Bank | ✅ POS + configurable payment types |
| Returns / partial returns (sales) | ✅ Refund engine with per-line condition (damaged/good), stock re-entry, refund receipts |
| Damaged / broken bottles / loss | ✅ Adjustment actions `defective`, `lost` (map "breakage" to these) |
| Customer dues / credit | ✅ `owed_amount`, layaway/instalments, customer account, customer statement report |
| Supplier outstanding | ✅ `providers.amount_due` (but see gaps: binary paid/unpaid) |
| COGS / gross profit | ✅ Auto-COGS per unit, `total_cogs` on orders, profit report |
| Cash movement | ✅ Cash registers with full open/close/cash-in/out history + Z-report |
| Expense tracking | ✅ Transactions module (direct/recurring/scheduled) |
| Revenue/cash-flow reports | ✅ Sales, payment-type, transactions, annual reports |
| Barcode | ✅ EAN8/13, Code128/39/11, UPC-A/E; per-unit barcodes; label printing; configurable default type |
| Product variants, units, pack sizes | ✅ Unit groups + conversion (e.g. Case(12) ⇄ Bottle) |
| Batch-ish tracking + expiry | ✅ `accurate_tracking` per procurement line + expiration dates |
| Tax groups (GST = CGST + SGST) | ✅ Multi-rate tax groups, inclusive/exclusive |
| Roles & granular permissions | ✅ ~hundreds of `nexopos.*` permissions, store admin/cashier roles, temporary permission grants |
| Wholesale + retail pricing | ✅ Per-unit `sale_price` / `wholesale_price` / `custom_price` |
| Stock adjustment batches with draft stage | ✅ New `products_adjustments` draft → performed workflow |
| Backups | ◐ `spatie/laravel-db-snapshots` is installed but not automated |

## 3. What Doesn't Satisfy Your Needs

1. **Indian currency formatting** — symbol ₹ is configurable, but both formatters use uniform 3-digit grouping (`number_format` in `app/Services/CurrencyService.php:169-183`; `currency.js` in `resources/ts/filters/currency.ts`). **₹1,25,000 (lakh/crore grouping) is impossible today.**
2. **GST compliance** — no HSN code on products, no GSTIN on store/customers/suppliers, no B2B/B2C invoice distinction, no GST summary reports (GSTR-1/GSTR-3B-oriented), no per-rate tax summary block on the invoice. Searched the whole codebase: `hsn|gstin` → zero hits.
3. **Supplier partial payments** — procurement payment status is strictly `paid|unpaid` (`app/Models/Procurement.php`). No payment history, no partial payment, no due dates, no supplier payment ledger.
4. **Purchase returns** — no supplier-return document. Deleting/editing a procurement line removes stock, but that's an edit, not an auditable return with a debit note.
5. **Landing cost** — procurement has no discount/freight/other-charges fields and no landed-cost allocation into per-unit cost.
6. **Physical stock count / reconciliation** — no counting session (freeze list → enter physical counts → variance → approval → post). The adjustment-draft feature is close, but it has **no approval step**, no system-vs-physical variance capture, and no count-sheet workflow.
7. **Inventory valuation report** — nothing values current stock (qty × COGS/purchase price) globally.
8. **Dead / slow / fast-moving & stock-aging reports** — none (best-products report is the only proxy).
9. **Payables/receivables aging** — no aging buckets; only totals.
10. **Brand** — no brand entity; only hierarchical categories. (Category+subcategory exist via `parent_id`.)
11. **MRP** — no MRP field (only sale/wholesale/custom prices). Liquor retail in India is MRP-regulated.
12. **Dashboard** — widgets are cashier-centric (best cashiers, orders chart); no "today's purchases / collections / outstanding / inventory value / payables / receivables / dead stock" widgets.
13. **Batch/lot numbers** — expiry and per-procurement-line tracking exist, but there is no human-readable batch number field.
14. **Multi-location transfers** — history action types exist for module compatibility, but core has no location/warehouse concept. (You described one store, so this is optional.)

## 4. Missing Features (build list)

- Indian number formatting (backend + frontend), ₹ defaults.
- Brand entity + product Brand/HSN/alcohol-%/volume(ml)/MRP/batch attributes.
- GST profile (store GSTIN, legal name, FSSAI/excise license numbers on invoice), customer/supplier GSTIN.
- GST-compliant invoice template (per-rate CGST/SGST breakdown, HSN column, amount in words).
- GST sales & purchase registers (B2B/B2C summary by rate, exportable).
- Procurement upgrades: partial payments with payment history, due dates, discount/freight/other charges, landed cost allocation, purchase returns (debit note).
- Stock count & reconciliation module: count sessions, snapshot of system qty, physical entry (scan-friendly), variance (gain/loss, qty & value), reason codes, **approval by a second permission**, posting via existing `stockAdjustment()`, full audit trail.
- Inventory valuation, dead/slow/fast-moving, stock aging, payables/receivables aging, brand/category margin reports, day book (cash book).
- Business dashboard (the ~19 KPIs you listed — most data already exists).
- Automated backups + activity log.

## 5. Weaknesses (design-level)

1. **No indexes on business tables.** Grepped every migration: apart from Laravel/Telescope internals and 3 unique columns, there are **no indexes and no foreign keys** on `products_histories`, `orders`, `orders_products`, `procurements_products`, `transactions_histories`, etc. At your target volume (100k+ movements) every ledger view and report becomes a full table scan.
2. **COGS = lifetime average.** `ProductService::computeCogs()` (`ProductService.php:857`) averages **all** `procured`/`convert-in` history rows ever recorded — not a moving weighted average of on-hand stock. After years of price inflation, COGS lags reality; it also rescans the entire history per recompute.
3. **Accounting silently skips entries** when a rule/account is missing — it only sends a dashboard notification (`TransactionService.php:740-756`, `1069-1079`). Books can be structurally incomplete without an error.
4. **Hard deletes.** `OrdersService::deleteOrder()` (`OrdersService.php:2323`) permanently deletes orders + payments (stock is returned, but the financial document disappears; ledger rows keep dangling `order_id`s). Procurements and products are also hard-deletable. Invoice history is not immutable.
5. **Provider balances are cached fields** (`amount_due`) maintained imperatively — drift is possible if any path bypasses `ProviderService`; there is no supplier ledger to reconcile against.
6. **Dual-write reporting** (dashboard_days rollups via listeners) can drift from source data; there is a "recompute" utility, but drift detection is manual.
7. **Two sources of currency math/format** (PHP + JS) must be kept in sync.
8. **Adjustment approval gap** — the same operator can draft and execute an adjustment; no segregation of duties for stock write-offs (shrinkage can be hidden by the person causing it).

## 6. Potential Bugs Found (verified in code)

| # | Bug | Location | Impact |
|---|---|---|---|
| B1 | **Adjustment reasons never reach the stock ledger.** `recordStockHistory()` writes `$history->description = $description ?? ''` but has **no `$description` parameter**, and callers never pass it; the `adjust_reason` captured in the UI is stored on the adjustment item only, while every `products_histories.description` stays empty. | `app/Services/ProductService.php:1601` (cf. `stockAdjustment()` which accepts `description` in `$data` and drops it) | Your "Reason" column in the ledger will always be blank. Must fix. |
| B2 | **`executeAdjustmentDraft()` is not transactional.** Items are applied in a loop; if item N fails (e.g. would go negative), items 1…N−1 are already applied and the batch stays `draft` — re-executing double-applies them. | `app/Services/ProductService.php:2547-2569` | Stock corruption on partial failure. Wrap in `DB::transaction()` + mark performed atomically. |
| B3 | **Order-code race.** `generateOrderCode()` reads the day counter, then increments non-atomically → two concurrent sales can get the same invoice code. | `app/Services/OrdersService.php:1538-1561` | Duplicate invoice numbers (GST problem). Use atomic `UPDATE ... RETURNING`/`increment()` then read. |
| B4 | **COGS drift** (see Weakness 2) — arguably a bug for any store with changing purchase prices. | `ProductService.php:857` | Profit overstated/understated. |
| B5 | **Silent accounting skips** (see Weakness 3). | `TransactionService.php` | Incomplete books without hard failure. |
| B6 | `updateAdjustmentDraft()` deletes and re-creates items without a transaction (crash mid-way loses the draft's items). Controller does guard `status === draft`, so performed batches are safe. | `ProductService.php:2523-2541` | Minor data-loss window. |
| B7 | Float columns (`float(18)`) still used for quantities in `products_histories` / `procurements_products` while money was migrated to `decimal(18,5)` (2026-05-18 migration). Fractional rounding of quantities is possible. | migrations | Low for bottle counts (integers), but worth normalizing to `decimal`. |

## 7. Liquor Industry Improvements (India)

Product model additions (all **additive** columns/tables — nothing existing changes):

- `nexopos_brands` table (+ `brand_id` on products); brand-wise reports become first-class.
- On `nexopos_products`: `hsn_code` (string, default 2208 range for spirits/beer/wine), `alcohol_percentage` (decimal), `volume_ml` (decimal), `batch_number` (string, optional), `mrp` (decimal, per unit quantity is better → add `mrp` to `products_unit_quantities`).
- Pack/case handling needs **no schema change**: model as Unit Group "Liquor 750ml" with base unit *Bottle* (value 1) and *Case* (value 12/24…). Per-unit barcodes and prices already exist. Case ⇄ bottle break-down uses the existing convert feature with full ledger entries.
- Category tree for liquor: Whisky/Beer/Wine/Rum/Vodka → subcategories via existing `parent_id`.
- MRP enforcement option at POS (warn/block when price > MRP).
- Supplier fields: GSTIN, state code (needed to decide CGST+SGST vs IGST on purchases), excise license no.
- Store settings: GSTIN, FSSAI/excise license, "composition scheme" flag (affects invoice wording).
- Optional: state-excise daily stock report format (varies by state — parameterize; you can confirm your state later; **not** in the first milestones).

## 8. Accounting Improvements

1. **Seed a complete Indian chart** on setup: Cash, Bank, UPI clearing, Inventory, GST Input/Output (CGST/SGST/IGST sub-accounts), Sales, Purchase, COGS, Expenses, Payables, Receivables — plus **auto-configure all transaction action rules** so nothing is ever "skipped".
2. **Fail loudly**: convert silent skips into blocking errors (or queued retry) once rules are seeded.
3. **Supplier payment ledger**: new `procurement_payments` table (date, amount, payment type, reference, author) → payment_status becomes derived (`unpaid/partial/paid`), provider `amount_due` recomputed from ledger. Payables aging report.
4. **Receivables aging** from unpaid/partial orders + instalments (data already exists).
5. **Weighted-average COGS fix**: maintain a running weighted average on each procurement receipt (`new_avg = (on_hand×avg + qty×cost) / (on_hand+qty)`) stored on `products_unit_quantities.cogs` — O(1) instead of full-history scan, correct with price changes. Keep `auto_cogs` toggle.
6. **Day book / cash book** report from `transactions_histories` + register history.
7. P&L summary (Revenue − COGS − Expenses) per period on the dashboard (data exists; presentation missing).

## 9. Inventory Improvements

1. **Fix B1/B2** (reasons into ledger; transactional execution).
2. **Stock count & reconciliation module** (your top priority):
   - `stock_counts` (status: `open → counting → pending_approval → approved|rejected`, author, approver, notes) + `stock_count_items` (product, unit, **system_qty snapshot**, physical_qty, variance qty & value, reason code).
   - Scan-friendly counting screen (barcode → increment count), category/brand filters for cycle counts.
   - Variance report (gain/loss, qty and ₹ value) before approval.
   - **Approval requires a separate permission** (`nexopos.approve.stock-counts`) — segregation of duties; the existing `permissions_access` elevation system fits perfectly.
   - On approval: post each variance through the existing `stockAdjustment()` (added/removed with reason + reference to the count) — the ledger stays the single source of truth. Nothing bypasses history.
3. **Reason codes** enum for adjustments: damaged, breakage, sample, internal consumption, theft/shrinkage, counting correction, expiry — reportable shrinkage analytics.
4. **Opening stock UX**: a guided "opening stock" import (CSV: barcode, qty, unit cost) that posts `added` adjustments tagged `opening-stock` and seeds COGS.
5. Batch number surfaced on procurement lines (column exists conceptually via `accurate_tracking`; add `batch_number` string).
6. Low-stock reorder suggestions already exist (`getLowStockSuggestions`) — surface them on the dashboard.

## 10. Reporting Improvements

Add (in order of value):
1. **Inventory Valuation** — on-hand qty × COGS (and × MRP for retail value), by product/category/brand; total inventory value KPI.
2. **Stock Ledger view** — per-product statement (the data is 100% there in `products_histories`; needs a filterable UI: date, doc, type, in, out, running balance, operator, reason).
3. **Dead / Slow / Fast-moving** — last-sale-date + velocity buckets (30/60/90 days).
4. **Stock Aging** — age of on-hand stock from procurement dates (`accurate_tracking` lines make this precise).
5. **GST Reports** — outward supplies by rate (B2B/B2C), inward supplies with input tax; CSV/Excel export for the accountant.
6. **Supplier report** — purchase history, payments, outstanding, aging.
7. **Shrinkage report** — adjustments by reason code, monthly trend, ₹ value.
8. **Brand & category margin** reports.
9. Keep existing reports; they're sound once indexes exist.

## 11. UI/UX Improvements

1. POS: default to walk-in customer, barcode-first flow (already good), quick-quantity keys, one-tap UPI/Cash tender buttons, MRP display on tiles.
2. Business dashboard (replace cashier-centric widgets by default): Today's Sales/Purchases/Profit/Expenses/Collections, Outstanding (recv/pay), Low/Out-of-stock counts, Inventory value, Cash in hand, Top brands, Fast movers, Recent purchases/sales. The widget framework already supports per-user layouts — we add widgets, not a new framework.
3. Procurement entry: barcode-scan line entry, duplicate-invoice-number warning per supplier.
4. Counting screen optimized for a phone (cycle counts on the floor).
5. Hindi/regional labels optional later (i18n system already exists with `lang/` JSONs).
6. Keyboard shortcuts already exist on POS; document them for staff.

## 12. Security Improvements

1. **Immutability policy**: disable `nexopos.delete.orders`/procurement delete for operational roles; prefer void/return flows (already present). Consider soft-deletes on orders/procurements if deletion must exist.
2. **Approval workflow** for stock write-offs (above) — the #1 anti-shrinkage control.
3. **Activity log** (who changed product price / cost / customer credit): lightweight observer-based audit table for sensitive models (products, prices, customers, providers, settings).
4. Automated **daily DB backups** (snapshots package is already installed; add scheduled job + retention).
5. Ensure **Telescope is disabled in production** (it's in `require`, gate it via env).
6. Register discrepancy tracking on close (over/short) — exists via register history; add a report.
7. Rate-limit auth routes; enforce strong passwords for cashier accounts (config exists).

## 13. Performance Improvements

1. **Indexes (highest ROI, zero risk):**
   - `products_histories (product_id, unit_id, created_at)`, `(operation_type)`, `(order_id)`, `(procurement_id)`
   - `orders (created_at)`, `(customer_id)`, `(payment_status)`, `(code)`
   - `orders_products (order_id)`, `(product_id, created_at)`
   - `orders_payments (order_id)`; `procurements_products (procurement_id)`, `(product_id)`
   - `transactions_histories (transaction_account_id, trigger_date)`, `(order_id)`, `(procurement_id)`
   - `products_unit_quantities (product_id, unit_id)` unique
   - `customers_account_history (customer_id)`, `registers_history (register_id, created_at)`
2. **O(1) weighted-average COGS** (kills the per-sale full-history scan).
3. Cache dashboard KPIs (rollup tables already exist; they just need indexed queries).
4. Chunked/queued report exports (PhpSpreadsheet on 100k rows will exhaust memory otherwise).
5. Keep MySQL 8.x. The codebase's raw SQL is MySQL-flavored; PostgreSQL is not an officially supported target — switching adds risk with zero business benefit here.
6. With indexes, MySQL comfortably handles tens of millions of history rows; no archiving needed for years.

## 14–15. Implementation Roadmap & Order

Each phase is independently shippable, verified before the next starts. Small commits per feature.

| Phase | Content | Risk | Effort |
|---|---|---|---|
| **0. Foundation** | Branch hygiene; test baseline run; **index migration**; fix bugs B1, B2, B3 (+ regression tests) | Very low | S |
| **1. India defaults** | ₹ symbol/ISO defaults; **Indian digit grouping** in `CurrencyService::format()` + `nsCurrency` TS filter (new option `ns_currency_numbering = international|indian`); date format dd/mm/yyyy | Low (display-only, both formatters behind option) | S |
| **2. Liquor catalog** | Brands table + CRUD; product fields (HSN, alcohol %, volume, MRP, batch); ProductCrud form sections; unit-group presets (Case/Bottle); category seed for liquor | Low (additive columns, nullable) | M |
| **3. GST layer** | Store/customer/supplier GSTIN fields; GST tax-group seeding (0/5/12/18/28 with CGST+SGST splits, IGST); invoice template: HSN column, per-rate tax summary, GSTIN block, amount-in-words; GST sales/purchase register reports + export | Medium (invoice template touches order printing — feature-flagged template) | M–L |
| **4. Purchasing upgrade** | `procurement_payments` table + partial payments UI; derived payment status (`partial`); due dates; discount/freight/other charges + landed-cost allocation into line cost; purchase returns (debit note) posting through `stockAdjustment(removed)` + accounting | Medium (touches procurement totals; guarded by tests) | L |
| **5. Reconciliation** | Stock count sessions with snapshot, variance, reason codes, approval permission, posting via existing engine; shrinkage report | Low–Medium (pure addition; posts through existing audited path) | L |
| **6. Reports** | Valuation, stock ledger UI, dead/slow/fast, aging, supplier ledger, receivable/payable aging, brand/category margin, day book | Low (read-only) | M |
| **7. Dashboard** | New business widgets + default layout for admin role | Low | M |
| **8. Accounting hardening** | Seed full chart + rules on install; fail-loud posting; weighted-avg COGS (behind `auto_cogs`); P&L summary | Medium (COGS change affects profit numbers — communicate cutover) | M |
| **9. Security & ops** | Delete-permission lockdown, activity log, scheduled backups, Telescope gating, register over/short report | Low | S–M |

Suggested order = table order. Phases 0–2 are quick wins delivering daily value immediately; 3–5 are the compliance/control core; 6–8 turn data into decisions.

## 16. Risk Assessment

- **Highest risk area:** anything touching order totals/tax math (Phase 3 invoice, Phase 8 COGS). Mitigation: never modify existing calculation methods' signatures; add new code paths behind options; run the accounting/order test suites (`tests/Feature/Accounting*`, `CreateOrder*`) after every commit; add tests for new math.
- **Procurement totals** (Phase 4): landed-cost allocation changes line `purchase_price` used by COGS. Mitigation: allocation is opt-in per procurement; existing procurements untouched.
- **Schema risk:** all changes are additive (new tables/nullable columns/indexes). Index creation on a **fresh/small DB is instant**; on a populated production DB, run during off-hours.
- **Upgrade risk vs upstream NexoPOS:** we are forking behavior in a branch. Where possible, changes go through the existing Hook/filter and module system to minimize merge pain with upstream updates. Core edits limited to: currency formatter, bug fixes, index migration.
- **Operational risk:** COGS method change shifts reported profit at cutover — snapshot valuation before/after and document the delta.

## 17. Migration Strategy

1. All schema changes as new migrations in `database/migrations/create|update` following the repo's `Schema::hasTable/hasColumn` guard convention (idempotent, re-runnable).
2. Fresh install path: seeders for liquor categories, GST tax groups, Indian chart of accounts + rules, payment types (Cash/UPI/Card/Bank/Credit), ₹ options.
3. Existing-data path (if you already run a store on it): options auto-set on migrate; opening stock via the CSV import posting `added` adjustments; provider balances recomputed from ledger.
4. Rollback: every migration implements `down()`; DB snapshot taken automatically before running the batch (package already installed).
5. No data transformation of existing rows anywhere — old records remain byte-identical.

## 18. Could Changes Break Existing Functionality?

- Indexes, new tables, new nullable columns, new reports, new widgets: **no** — invisible to existing code.
- Currency formatting: only when `ns_currency_numbering = indian` is selected; default behavior preserved; both formatters covered by unit tests.
- Invoice template: new GST template added alongside existing ones (selection via existing template hooks/options) — old receipts unchanged.
- Bug fixes B1–B3 change behavior *intentionally* (reasons recorded, atomic adjustments, unique codes); each gets a regression test.
- Procurement payment-status derivation must keep emitting the same paid/unpaid transitions for the accounting listeners — covered by `AccountingProcurementTest`.
- COGS change is opt-in at cutover.

## 19. Preserve or Improve the Database Design?

**Preserve, and extend additively.** The schema is well-normalized where it matters (per-unit stock, immutable history rows with before/after, journal-style accounting) and ~71 test classes assume it. Redesigning it would burn weeks for no functional gain. Improvements worth making inside that constraint: indexes (critical), decimal quantities on history tables, new child tables (`brands`, `procurement_payments`, `stock_counts`), and treating cached balance fields (`amount_due`) as derived values recomputed from ledgers. Foreign keys: recommended only on the **new** tables; retrofitting FKs onto legacy tables risks failing on existing orphan rows and breaks the module ecosystem's assumptions.

## 20. Final Recommendation

NexoPOS is a **strong foundation for this business — keep it, don't replace it.** Its inventory core already implements your non-negotiables: an append-only stock ledger with before/after balances and operator attribution, negative-stock protection, unit/case conversion, batch-level tracking with expiry, a genuine (if under-configured) double-entry accounting layer, and a complete POS with split payments, credit customers, refunds and cash-register control. The gaps are concentrated, well-bounded, and additive: Indian formatting & GST compliance, supplier partial payments & landed cost, a counting/reconciliation workflow with approvals, valuation/velocity/aging reports, a business dashboard, indexes, and four concrete bugs (B1–B3 + COGS method).

Execute the 10-phase roadmap in order; after Phase 2 the store can operate daily; after Phase 5 you have enterprise-grade stock control; after Phase 8 the books are trustworthy end-to-end. Total scope is deliberate, incremental, and never bypasses the existing audited stock/accounting engines — which is exactly why it won't break them.

---

*Review performed on branch `claude/liquor-store-pos-blueprint-q3vo4n` at commit `43d843d`. Key evidence: `app/Services/ProductService.php` (stock engine, adjustments, COGS), `app/Services/OrdersService.php` (sales, refunds, deletion), `app/Services/ProcurementService.php`, `app/Services/TransactionService.php` (rule-based accounting), `app/Services/ReportService.php`, `app/Services/CurrencyService.php`, `resources/ts/filters/currency.ts`, `database/migrations/create/*`, `routes/web/reports.php`.*
