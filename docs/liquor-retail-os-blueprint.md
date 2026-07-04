# LiquorOS — An Original Retail Operating System for Indian Liquor Stores
### Implementation Blueprint (architecture only — no code)

**Target platform:** Lovable (React + TypeScript + Tailwind + shadcn/ui) on Supabase (Postgres, Auth, RLS, Edge Functions, Storage, Realtime).
**Studied, then set aside:** NexoPOS — audited hands-on in this workspace (architecture review, adversarial QA, three real defects found and fixed). Its lessons inform §33–35; its implementation is deliberately not reused.

---

## 1. Executive architecture

One sentence: **a ledger-first retail operating system where two append-only ledgers — stock movements and money movements — are the single source of truth, everything else (stock on hand, vendor balances, P&L, analytics) is derived, and every mutation goes through a named, atomic, server-side function.**

```
 Phone / Tablet / Desktop (PWA)
        │  React + shadcn/ui, offline-tolerant POS queue
        ▼
 Supabase RPCs (Postgres functions)  ← the ONLY writers of business data
        │  atomic: validate → write document → write ledger rows → update caches
        ▼
 Postgres
   ├─ Catalog (brands, products, suppliers…)          — mutable, soft-delete
   ├─ Documents (purchases, sales, returns, counts…)  — immutable once posted
   ├─ Ledgers (stock_movements, money_movements,      — append-only, never edited
   │            vendor_ledger, customer_ledger)
   ├─ Caches (store_products.qty_on_hand, balances)   — derived, reconciled nightly
   └─ Rollups (daily sales/product aggregates)        — rebuilt by scheduled jobs
```

Multi-store is structural from day one: every operational row carries `store_id`; Row-Level Security scopes every query to the stores a user belongs to. One store today costs nothing extra; fifty stores later costs no redesign.

## 2. Design philosophy

1. **Ledgers are truth; balances are opinions.** Any number the owner stakes money on (stock, cash, vendor dues) must be re-derivable by summing an append-only ledger. Cached balances exist for speed and are audited against the ledger nightly.
2. **Documents are immutable.** A posted sale, purchase, or adjustment is never edited or deleted. Mistakes are corrected by *new* documents (void, return, correction) that write reversing ledger entries. This is how accountants have worked for 500 years, and it is why the audit trail can never lie.
3. **One writer path.** The client never inserts into ledgers or documents directly. Every posting is a named Postgres function (`post_sale`, `receive_purchase`, `approve_adjustment`…) that runs in one transaction. No partial states, no race-prone read-modify-write in the client.
4. **Integers for money and stock.** Money is stored in **paise** (integers). Stock is stored in **bottles** (integers). No floats anywhere near value. Display formatting (₹1,25,000.00, lakh/crore grouping) is a pure presentation concern.
5. **Explicit over generic.** A `purchases` table and a `sales` table — not a polymorphic `documents` table. A `post_sale` function — not a generic `post_document(type, payload)`. AI builders (and humans) implement explicit designs correctly; generic engines invite subtle breakage.
6. **Boring is a feature.** Every clever idea was challenged with “will the owner understand this number at 11 pm after a 14-hour day?” If not, it was simplified.

## 3. Product philosophy

The user is a shop owner or a counter clerk, standing, often on a phone, with a customer waiting. Therefore: the sale screen is reachable in one tap and operable with one thumb; barcode-first everywhere; every list has instant search; the owner's three questions — *how much did I sell today, how much cash should be in the drawer, what do I owe whom* — are each answerable in ≤ 2 taps. English UI with ₹ and Indian numerals; Hindi labels as a later toggle. The system must degrade gracefully: if internet drops mid-rush, sales keep queuing locally and sync when back (POS only; back-office requires connectivity).

## 4. Module breakdown

| Module | Contents |
|---|---|
| **Sell (POS)** | search/scan → cart → split tender (cash/UPI/card/credit) → receipt; reprints; held carts |
| **Catalog** | Brands, Categories, Products (per bottle size), pack configs, price & MRP management, barcode/QR labels |
| **Purchases** | Purchase Orders (optional), Purchase Bills + Goods Receipt, landed-cost allocation, purchase returns (debit notes) |
| **Inventory** | live stock, stock ledger per product, adjustments (breakage/theft/sample/correction), cycle counts & full physical verification, transfers (godown ↔ counter ↔ store) |
| **Money** | Cash Book, Bank/UPI Book, Expenses, Vendor Ledger (khata), Customer Ledger (credit sales), Day Closing, Month Closing |
| **Reports** | Daily Sales Register, Purchase Register, GST summary, P&L, Stock Valuation, registers by any date range |
| **Analytics** | dead/slow/fast movers, ABC/XYZ, ageing, margins, brand & festival trends, supplier performance, shrinkage |
| **Admin** | Stores, Users & roles, Settings, Audit log, Notifications, Backups/export |

## 5. Complete feature list (condensed)

Catalog: brand→product hierarchy; one product per brand+variant+size (Royal Stag 750/375/180 = three products under one brand, linked by a `family` label for reporting); MRP, selling price, GST rate, HSN, alcohol %, excise fields; multiple barcodes per product; label printing.
Purchasing: supplier master with GSTIN/excise license; PO (optional) → bill entry with per-line qty (cases+loose auto-converted), rate, scheme discount, GST; freight/other charges allocated to landed cost; partial/complete GRN; supplier payment recording; debit notes.
Selling: barcode scan; qty steppers; price override with permission + reason; MRP cap warning; split payments; credit sale to registered customer; returns against original invoice (full/partial); daily invoice series per store; 80mm receipt + A4 GST invoice.
Inventory: bottle-atom stock; adjustment reasons (breakage, damage, theft, sample, self-use, correction, excise seizure); draft→approve for write-offs; count sessions (blind entry, variance value, approval); transfers with in-transit state.
Money: cash book with float, denominations at closing; bank/UPI book per account; expense categories; vendor & customer khata with running balance; day close locks the day; month close snapshots P&L + valuation.
Admin: roles owner/manager/cashier; per-action audit log; notification center (low stock, dues, closing missed); nightly export.

## 6. Database blueprint (descriptive — no SQL by design)

Conventions: every table has `id (uuid)`, `created_at`; operational tables add `store_id`; catalog adds `is_active` (soft delete); ledgers are **insert-only** (no update/delete grants at all).

**Identity & tenancy**
- `stores` — name, address, GSTIN, excise license no., invoice prefix, timezone.
- `profiles` — mirrors auth user; name, phone.
- `store_members` — profile↔store with `role` (owner|manager|cashier). A user may belong to many stores.

**Catalog**
- `brands` — name, company.
- `categories` — name (Whisky, Beer…), default GST rate.
- `products` — brand_id, category_id, name, `volume_ml`, alcohol %, `hsn_code`, `gst_rate`, `mrp_paise`, `sell_price_paise`, family label, is_active.
- `product_barcodes` — product_id, barcode (unique). Many per product (state relabels happen).
- `pack_configs` — product_id, label (“Case”), `bottles_per_pack` (12/24/48). **Entry helpers only — never a stock unit.**
- `suppliers` — name, GSTIN, phone, excise license, opening balance.
- `store_products` — store_id + product_id: `qty_on_hand` (cache), `wac_paise` (weighted avg cost cache), reorder level, `last_sold_at`, `last_received_at`.

**Documents (immutable after posting; `status` draft→posted→void where applicable)**
- `purchases` + `purchase_lines` — supplier bill no./date, per-line bottles, unit cost, discount, GST, computed `landed_unit_cost_paise`; header freight/other charges + allocation method.
- `sales` + `sale_lines` + `sale_payments` — invoice no. (per-store FY series), customer_id nullable (walk-in), per-line: bottles, unit price, MRP snapshot, GST rate & HSN **snapshot**, `cogs_paise` snapshot (WAC at moment of sale); payments rows for split tender.
- `sale_returns` / `purchase_returns` + lines — **separate documents referencing the original line**; originals are never mutated.
- `adjustments` + `adjustment_lines` — reason code per line, draft→approved (approver ≠ creator when role requires), value impact.
- `stock_counts` + `stock_count_lines` — snapshot qty, counted qty, variance qty & value, blind-mode flag, approve → posts corrections.
- `transfers` + `transfer_lines` — from_store, to_store, status requested→dispatched→received; dispatch writes OUT, receive writes IN (in-transit is visible, never lost).
- `expenses` — category, amount, paid_from (cash|bank account), note, receipt photo.

**Ledgers (append-only truth)**
- `stock_movements` — store_id, product_id, `qty_delta` (signed bottles), `balance_after`, movement type (purchase|sale|sale_return|purchase_return|adjustment|count_correction|transfer_out|transfer_in|opening), document type + id, unit cost at movement, actor, reason, batch_id nullable, created_at. *The bank statement of every bottle.*
- `money_movements` — store_id, account (CASH | bank_account_id), `amount_paise` signed, type (sale_receipt|purchase_payment|expense|deposit|withdrawal|owner_draw|opening_float|closing_variance), document ref, actor. *Cash book and bank book are filtered views of this one ledger.*
- `vendor_ledger` — supplier_id, signed amount (bill = credit to vendor, payment = debit), document ref, `balance_after`.
- `customer_ledger` — same shape for credit customers.
- `audit_log` — actor, action, entity, before/after JSON for sensitive edits (price change, void, permission change).

**Operations**
- `invoice_sequences` — store_id + series + FY, `next_number` (incremented under row lock — the NexoPOS duplicate-code race, solved structurally).
- `day_closings` — store_id, date, expected cash (derived), counted cash, denominations JSON, variance, closed_by; a posted closing **locks that day** for its store.
- `month_closings` — snapshot of P&L lines + stock valuation at month end.
- `festival_calendar` — date ranges + labels (Holi, Diwali, New Year, dry days) to annotate analytics.
- Rollups: `daily_product_sales`, `daily_store_totals` — rebuilt idempotently by a scheduled job.

## 7. Relationships (the spine)

brand 1→N products; product 1→N barcodes, 1→N pack_configs; store N↔N products via store_products.
Every document header 1→N lines; every posted line 1→N ledger rows.
`stock_movements.document(type,id)` links each bottle movement to exactly one document; `money_movements` and `vendor_ledger` do the same for rupees. Deliberately **not** enforced as polymorphic FK — enforced inside the posting functions, checked by nightly integrity job (orphan scan). This trades a constraint Postgres can't express cleanly for a verifiable invariant, and keeps the schema explicit.

## 8. Server architecture

All writes = **named Postgres functions** exposed as Supabase RPCs, one per business action (≈ 20 total): `create_product`, `receive_purchase`, `post_sale`, `post_sale_return`, `submit_adjustment`, `approve_adjustment`, `start_count / submit_count / approve_count`, `dispatch_transfer / receive_transfer`, `record_supplier_payment`, `record_expense`, `close_day`, `close_month`, `void_sale`…
Each function: (1) asserts role + store membership, (2) asserts day not closed, (3) validates payload, (4) writes document + ledger rows + cache updates **in one transaction**, (5) writes audit row, (6) returns the created document. Reads are plain RLS-guarded selects and views. Edge Functions are reserved for the few things SQL shouldn't do: PDF invoice rendering, scheduled rollups/reconciliation, backup export, notification fan-out.
*Why RPCs over Edge-Function-per-action:* transactions are native, latency is lower, and Lovable generates/edits SQL functions reliably; Edge Functions add cold starts and a second deploy surface. Compared and chosen deliberately.

## 9. Frontend architecture

React SPA (Lovable default) as an installable PWA. Structure: `pages/` per module, `components/` shared primitives (MoneyInput — paise-aware, QtyStepper — case/bottle aware, BarcodeInput, SearchSelect, LedgerTable, StatCard), one hook per RPC (`usePostSale`…), TanStack Query for server state, a single small client store for the cart only. Indian formatting utilities (`formatPaise` → ₹1,25,000.00) in one file, used everywhere. POS holds an offline queue (IndexedDB): sales post optimistically, sync sequentially, conflicts impossible because invoice numbers are assigned server-side at sync time.

## 10. Lovable implementation strategy

Build in nine self-contained milestones, each shippable and testable before the next:
**M1** Auth + stores + members + roles → **M2** Catalog (brands/categories/products/barcodes/packs) → **M3** Purchases + GRN + vendor ledger → **M4** POS sales + payments + receipt → **M5** Returns (both directions) + voids → **M6** Adjustments + counts + transfers → **M7** Money (cash/bank books, expenses, day closing) → **M8** Reports + GST → **M9** Analytics + rollups + notifications.
Rules for the AI builder: never write to ledger tables from the client; never do money math in JS (display only); every new business action = one new RPC following the §8 template; every table gets RLS before UI; seed script with 20 realistic products (Kingfisher 650, Royal Stag 750/375/180, Old Monk 750…) so every screen is testable immediately.

## 11. Supabase architecture

Postgres with RLS on every table (policy: `store_id in (select store_id from store_members where profile_id = auth.uid())`; catalog shared tables readable by any member, writable by manager+). Ledgers additionally have **no UPDATE/DELETE policies at all** — immutability enforced by the database, not by convention. Storage buckets: `receipts` (expense photos), `exports` (backups), `labels`. Scheduled (pg_cron / scheduled Edge Functions): nightly rollups, nightly ledger-vs-cache reconciliation (flag drift, never silently fix), nightly export to Storage. Realtime channel on `daily_store_totals` for the owner's live dashboard.

## 12. Authentication

Supabase Auth, email or phone. New users join only by invitation from an owner/manager (no open signup). Session on every device; PIN-relock on the POS after idle (cashier convenience without password fatigue). Optional TOTP 2FA for owners.

## 13. Roles

Three roles, deliberately few: **Owner** (everything, all stores they own), **Manager** (everything in their store except user management, price-rule changes, and month closing), **Cashier** (sell, return-with-manager-PIN, view own shift). Approval separations built into RPCs: adjustment approver must differ from submitter; day closing by manager+; void requires manager PIN. A permission matrix table in code (not DB-configurable — fixed rules are auditable rules).

## 14. Security

RLS store isolation; ledger immutability at the grant level; audit_log for every sensitive mutation; soft-delete only in catalog (`is_active=false` — a product with history can never vanish); **no hard delete of any posted document, ever** — void with reason is the only path; price overrides logged with before/after; day-close lock prevents retroactive tampering; backups are exports the owner holds, not just vendor snapshots. Threat model headline: the biggest real-world threat is *insider shrinkage manipulation* — countered by approval separation, blind counts, immutable ledgers, and variance reports the owner reads.

## 15. Inventory engine (the heart)

**The atom is one bottle of one product (brand+variant+size).** Cases and packs exist only at entry/display: receiving “5 cases + 3 loose” of a 12-pack product writes **63 bottles**; the POS can show “5C + 3B” but stores bottles. This single decision eliminates the entire class of case-stock vs bottle-stock divergence observed in unit-split designs (§33).
Every change appends one `stock_movements` row with signed delta and `balance_after`, inside the same transaction that updates `store_products.qty_on_hand`. Negative stock is rejected at posting (with a manager override that itself writes an audit row). Batches: optional per purchase line (batch no./mfg date) for excise traceability; sales deplete FIFO across batches when batch tracking is on, and the movement row records the batch. Expiry applies mainly to beer — a “stock older than X days” view covers it without hard expiry blocking.
Nightly reconciliation recomputes `qty_on_hand` from the ledger; any drift becomes a red alert, never a silent correction.

## 16. Accounting engine

Deliberately **not** a general ledger with debit/credit accounts the owner must configure. Instead, the three books an Indian shop already keeps, made rigorous:
- **Cash Book / Bank Book** — `money_movements` filtered by account; opening float, every receipt/payment, deposits, owner draws; expected cash at any moment is a sum, not a mystery.
- **Vendor Khata / Customer Khata** — bills increase dues, payments reduce them; running `balance_after` on every row; statement = the ledger itself.
- **Derived P&L** — Revenue (posted sales net of returns) − COGS (Σ `cogs_paise` snapshots) − Expenses. Inventory valuation = Σ qty × WAC per product.
COGS uses **moving weighted average cost**, updated at goods receipt: `new_wac = (qty·wac + received·landed_unit_cost) / (qty + received)`, and *snapshotted onto each sale line at sale time* — so historical profit never changes when future purchase prices change (the lifetime-average trap, §33). Compared with FIFO costing: FIFO is more precise but needs layer tracking that confuses owners and AI builders alike; WAC is the standard for Indian retail and is auditable in one line.

## 17. Reporting engine

Reports are **views over ledgers and documents** — no report ever counts by a code path different from the money path. Core set: Daily Sales Register (invoice-wise, tender-wise), Purchase Register (bill-wise, supplier-wise), GST Summary (outward by HSN+rate with CGST/SGST split, net of returns *by construction* because returns are documents; inward from purchase lines), Stock Ledger per product (the bank statement), Stock Valuation, Vendor/Customer statements, P&L (day/month/FY), Day-closing variance history. Every report: date-range, store filter, print CSS, CSV export.

## 18. Analytics engine

Nightly rollups make analytics instant at 50-store scale: velocity (units/day, 30/60/90 windows), **dead stock** (`last_sold_at` older than X with qty>0, valued at WAC), fast/slow movers vs category median, **ABC** (cumulative revenue 80/15/5), **XYZ** (demand variability), inventory ageing buckets from receipt dates, margin ranking (price − WAC), brand & category trends, purchase-vs-sales overlay, supplier performance (fill rate, price history per product — “best price you ever got”), shrinkage dashboard (adjustment value by reason, by month), festival annotations from the calendar table (“Diwali week vs normal week”), seasonality by weekday/month. Each insight ends in an action link: dead stock → discount list; fast movers → reorder suggestions.

## 19–27. Workflows (state machines)

**Purchase:** *(optional PO →)* enter supplier bill (scan/pick lines, cases+loose, scheme discounts, GST) → allocate freight → **post GRN**: stock IN + WAC update + vendor khata credit, in one transaction → record payments now or later (partial fine) → dues visible until zero. Failure mid-entry = draft persists; nothing touched stock yet.
**Sale:** scan/search → cart (price editable only with permission; MRP cap warning) → tender split across cash/UPI/card/credit → `post_sale` assigns invoice number under lock, writes lines with GST/HSN/COGS snapshots, stock OUT, money IN, customer khata if credit → receipt prints; offline queue if disconnected.
**Returns:** always against an original invoice/bill line, qty-capped by what remains returnable; creates its own document; stock IN (resellable) or straight to breakage adjustment (damaged); refund via original tender or khata credit; GST reports net out automatically.
**Adjustment:** cashier/manager submits with reason + optional photo → draft → manager/owner (≠ submitter) approves → ledger writes with value impact; rejected drafts remain visible history.
**Transfer:** request → dispatch (stock OUT at source, in-transit) → receive (stock IN, receiver confirms qty; short receipt auto-opens a discrepancy adjustment at the source).
**Supplier:** onboard with GSTIN/excise → bills accumulate khata → payments (cash/bank, reference no.) → statement; supplier price history feeds purchase suggestions.
**Expense:** amount + category + paid-from + photo → money ledger immediately; recurring templates (rent, salaries) prompt monthly.
**Day closing:** at close, system shows expected cash (opening float + cash sales − cash expenses/payments − deposits) → staff counts denominations → variance recorded (not hidden) → day locks; back-dated documents into a closed day are refused (correction docs post today, referencing yesterday).
**Month closing:** after last day-close: P&L snapshot, valuation snapshot, GST summary freeze, khata balances confirmation → immutable `month_closings` row — the number the CA gets.

## 28. Backup strategy

Three layers: Supabase PITR (platform), **nightly logical export** of all tables to Storage as CSV/JSON zip (owner-downloadable — the data is *theirs*), monthly restore drill documented as a checklist. Receipts/photos bucket included. An owner who can hold a USB stick with last night's books trusts the system.

## 29. Scalability roadmap

Phase 1 (1 store): everything above.
Phase 2 (2–10): add stores + members — the schema already carries `store_id`; transfers light up; consolidated owner dashboard across stores.
Phase 3 (10–50): warehouse = a store flagged non-selling; central purchasing (one bill fanning GRNs); franchise = owner-scoped store groups; partitioning `stock_movements` by store/month if row counts demand (Postgres handles tens of millions with the planned indexes before this is needed).
Phase 4: online ordering/delivery as a new sales channel writing through the *same* `post_sale` — no parallel inventory path, ever.

## 30. Future AI features

Reorder suggestions (velocity + lead time + festival calendar); dead-stock discount advisor; anomaly watch (variance spikes, unusual voids, price-override clusters — insider-theft signals); natural-language owner queries (“is bade botal ka stock kitna hai?”) over the rollups; demand forecasting per family for festival buying; supplier-bill photo → line extraction (OCR) into a draft purchase.

## 31. Performance optimizations

Indexes shipped with the schema (learned the hard way — §33): ledgers by (store, product, created_at) / (store, account, created_at); documents by (store, created_at) and invoice no.; `last_sold_at` for dead stock. Rollups keep dashboards O(days), not O(sales). POS product search against a slim indexed view. Pagination everywhere; no unbounded exports (server-generated CSV). Money as integers avoids numeric parsing costs and rounding bug hunts. Target: sale posting < 300 ms, dashboard < 1 s at 1M movements.

## 32. Potential design mistakes to avoid

Stock in multiple units (the divergence machine) · editing posted lines for returns · floats for money · client-side ledger writes · generic document/EAV tables · DB-configurable accounting rules that silently skip when unconfigured · cached balances without reconciliation jobs · hard deletes anywhere near documents · invoice numbers from read-then-increment · burying variance instead of recording it · building offline-first for *back office* (only POS needs it) · per-store schema forks.

## 33. Lessons learned from studying NexoPOS (first-hand)

Worth keeping as *ideas*: append-only stock history with before/after balances; draft→approve for write-offs; per-day invoice sequencing; barcode-first POS; granular permissions; split payments; the daily rollup concept.
Failure modes found by actually auditing and breaking it: per-unit stock rows let case stock and bottle stock drift apart; refunds **mutated** posted sale lines, which silently corrupted GST reporting (I fixed this bug in the fork); a `rate` column existed but was never populated — dead columns lie to report writers; invoice-counter read-then-increment raced under concurrency; accounting entries were *skipped with a notification* when rules were unconfigured; business tables shipped with **zero indexes**; orders and payments were hard-deletable; cached vendor balances had no reconciliation path; float columns carried quantities.

## 34. What NOT to copy from NexoPOS

The unit-group stock split; the polymorphic CRUD engine (forms/tables generated from config — powerful for a framework vendor, hostile to AI implementation and debugging); options-table-driven accounting rules; the event/hook spaghetti as the primary extension mechanism; dual PHP+JS money formatting engines; mutable order lines; the “module marketplace” abstraction layer for a single-business system.

## 35. Why this architecture is superior for an Indian liquor store

Because every structural decision maps to a real failure mode of the incumbent or a real habit of the Indian trade: **bottle-atom stock** makes drift impossible rather than detectable; **immutable documents + reversing entries** make GST and audits correct by construction, not by careful filtering; **khata-style three-book accounting** matches how the owner already thinks (rokad, bank, udhaar) instead of demanding a chart of accounts; **paise integers and snapshots (MRP, GST, HSN, COGS)** make every historical number reproducible forever; **RPC-only writes with role checks and day locks** turn insider manipulation from “easy edit” into “leaves a trail or is refused”; **store_id + RLS from day one** means the 50-store future is a data change, not a rewrite; and **explicit tables + explicit functions** are exactly the shape an AI builder implements safely, module by module, without a redesign. It is not a restaurant POS wearing a liquor label — it is the shop's own ledgers, finally incorruptible.
