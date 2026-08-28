# Cargo Management System

A multi-tenant cargo & freight-forwarding management platform built for logistics companies that receive goods internationally, consolidate them into shipments, move them by truck, and invoice customers — with a self-service tracking portal for the customers themselves.

Built by **[CURDUN ICT Solution](https://curdunict.com)**.

---

## What it does

The system is organized around one core workflow:

**Reception → Warehouse storage → Container consolidation → Trucking / dispatch → Live tracking → Invoicing → Payment collection**

It's multi-tenant: one deployment serves multiple independent cargo companies (tenants), each with its own branches, staff, customers, and financial data, fully isolated from one another.

### Key features

- **Multi-tenant architecture** — a Super Admin manages the platform and onboards tenant companies; each tenant runs its own operation independently
- **Warehouse & inventory management** — goods reception, stock tracking by origin/location, package lifecycle from pending → received → warehouse → delivered
- **Logistics & fleet** — container management, trucking fleet, driver/loader assignment, live shipment tracking with a public tracking page and QR codes
- **Finance** — invoicing, payment/receipt collection, expense & bill management, bank reconciliation, tax management, customer debt tracking, a loyalty points program
- **Branch operations** — multi-branch support with branch-scoped stock, transfers, and reporting
- **Customer self-service portal** — customers track their own shipments, view/pay invoices, check loyalty points, and open support tickets
- **WhatsApp & email notifications** — automated status updates via WhatsApp (GreenAPI) and email (SMTP)
- **Role-based access control** — Super Admin, Tenant Admin, Branch Manager, Staff (with sub-permission levels), and Customer, each with a purpose-built dashboard and menu
- **Bilingual UI** — English/Somali, built for the Somali logistics market

## Tech stack

- **Backend:** PHP (PDO/MySQL), no framework
- **Database:** MySQL
- **Frontend:** Bootstrap, vanilla JS/jQuery, Chart.js for reporting
- **Email:** PHPMailer (SMTP)
- **Messaging:** GreenAPI (WhatsApp Business)

## Getting started

### Requirements
- PHP 8+, MySQL 5.7+/MariaDB, Composer

### Setup

1. **Clone and install dependencies**
   ```bash
   composer install
   ```

2. **Configure the database** — edit `config/db_connect.php` with your MySQL credentials. It will auto-create the database on first run if it doesn't exist.

3. **Import the schema**
   ```bash
   mysql -u root -p your_database_name < sql/curdun_cargo_system_clean_schema_no_insert.sql
   ```

4. **Set up secrets** — copy the example file and fill in your own credentials:
   ```bash
   cp config/secrets.example.php config/secrets.php
   ```
   Edit `config/secrets.php` with your Gmail SMTP app password (for password-reset emails) and GreenAPI credentials (for WhatsApp notifications). This file is gitignored and never committed.

5. **Serve the app** — point Apache/Nginx (or `php -S localhost:8000`) at the project root.

6. **Create the first account** — visit `register_admin.php` to create the initial Super Admin account, then log in and start onboarding tenants under **Administration → Tenants**.

## Roles at a glance

| Role | Scope |
|---|---|
| Super Admin | Full platform access across all tenants; onboards new companies |
| Tenant Admin | Full access within one company (all branches, finance, users) |
| Branch Manager | Operations, finance, and reporting for one branch |
| Staff | Day-to-day operational tasks (reception, warehouse, stock); permissions expand by sub-role |
| Customer | Self-service portal: track shipments, invoices, payments, loyalty points, support |

## Recent hardening & verification (2026-08-25 → 2026-08-28)

The codebase went through a multi-session end-to-end audit covering Super Admin, Tenant Admin, Branch Manager, Staff, Driver, and Customer workflows. Below is the current verified state — see the commit history for the per-change detail.

### Security
- **CSRF gate** extended across staff, branch_manager, driver, customer, and Super Admin mutation routes. A shared shim in `includes/csrf.php` + `includes/footer.php` attaches `X-CSRF-Token` on every jQuery ajax POST; 20 inline-jQuery Super Admin pages carry a local shim so their AJAX also passes the check.
- **RBAC** — 290-cell probe against 29 Super Admin URLs × 10 identities; only Super Admin gets 200 on mutation routes. Non-SA cross-tenant forge attempts (e.g. non-SA staff sending `tenant_id=9` from a tenant-36 session) are ignored server-side and the row lands in the caller's own tenant.
- **Audit logging** — sensitive setting keys (whatsapp_token, sms_api_key/secret, smtp_password, `*_password`, `*_secret`, `*_token`, api_key) are masked before audit_log rows are written.
- **Approval-only Branch Manager role** — Branch Manager no longer executes trip creation or dispatch; the role approves/rejects only. Origin-only trip advance is enforced (only the origin's Logistics Supervisor can move a trip past its own branch; destination custody requires the destination Warehouse Supervisor).

### Finance engine (verified this audit series)
- **Double-entry ledger** — every invoice creation, receipt, vendor bill, bill payment, and tax settlement posts balanced journal entries (`sum(debit) = sum(credit)`) via `includes/AccountingService.php`.
- **Canonical AR rule** — invoice-derived, ledger-derived, and cached `customers.debt_amount` agree at every step of a fresh customer chain.
- **Payment guards** — customer and vendor payment paths reject overpayment, zero payment, and replay against a paid invoice/bill.
- **Bank reconciliation** — `complete_reconciliation` enforces `abs(statement_balance − book_balance) ≤ 0.005` before marking reconciled, and rejects re-reconciling already-reconciled transactions.
- **Tax lifecycle** — taxable invoice posts `Dr AR / Cr Tax Payable / Cr Revenue`; tax return settlement posts `Dr Tax Payable / Cr Cash`; overpayment and replay rejected.
- **Loyalty** — points awarded once per successful payment (`round(amount / 100 × tenants.loyalty_amount_points, 2)`), idempotent per payment_id, atomic inside the payment transaction.
- **Money precision** — every finance column is `DECIMAL(x, 2)`; no `FLOAT`/`DOUBLE`.
- **`customers.debt_amount` is a derived cache**, maintained authoritatively by the DB trigger `trigger_update_debt` on `AFTER INSERT ON receipts`. The application-side double-decrement defect was removed. A separate diagnostic script `recalculate_debt.php` derives per-customer debt from `SUM(invoices.total_amount − invoices.paid_amount)`; it is **dry-run by default** and should never be scheduled.
- **`chart_of_accounts.balance` is a derived cache** maintained by `AccountingService::postToLedger`. A diagnostic script (kept in `scratchpad/` locally) derives balances from `journal_entries` — do not run its `--apply` mode against a database with incomplete historical journal rows.

### Super Admin tenant selector
- One authoritative helper: `includes/sa_scope.php` exposes `sa_selected_tenant_id_int()` and `sa_resolve_tenant_scope($explicit)`. Auto-loaded via `includes/csrf.php` (for AJAX handlers) and `includes/header.php` (for page render).
- 16 Super Admin pages default their `$tenant_filter` from that helper when no explicit `$_POST['tenant']` is sent.
- Dashboard KPIs cross-verified against SQL under `ALL / Tenant 36 / Tenant 9 / back to ALL`.

### Shipment / trip custody
- **Inter-branch trip arrival**: origin-side actors cannot advance a trip to `delivered` / `completed` on inter-branch trips — that transition belongs to the destination Warehouse Supervisor via `staff/incoming_trips.php`. `trucking_trips.received_by` records the authoritative destination custody actor.
- **Trip approval trail**: `approved_by`, `dispatched_by`, `received_by` columns on `trucking_trips`, all rendered in the Trip Details modal for both Super Admin and Branch Manager.
- **Branch assignment integrity**: assigning a user as a branch's primary manager requires their `role_type = 'branch_manager'` — server-side enforced.

### Documented limitations (not audit-blocking)
- **`BLOCKED-HISTORICAL-DATA`** — historical `receipts` (31 rows) and `payments` (15 rows) were hard-deleted before this audit began, and 40 `journal_entries` rows were also lost. Two customer `debt_amount` values remain in a legacy-corrupted state (Cabdiraxmaan −330, Abdulkadir Hassan 0-vs-invoice-derived-210). These are DRY-RUN documented, not silently corrected.
- **`BLOCKED-TOOL`** — post-JS DOM inspection under an authenticated PHP session was not automated (requires a Chrome DevTools Protocol harness this environment doesn't have).

## Security note

`config/secrets.php` holds real credentials and is excluded via `.gitignore`. Never commit it. Use `config/secrets.example.php` as the template for any new environment.

The commented-out remote credentials at the top of `config/db_connect.php` are historical artefacts from an earlier deployment; rotate them and remove the comment on any real production environment.

## License

Proprietary — © CURDUN ICT Solution. All rights reserved.
