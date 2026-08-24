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

## Security note

`config/secrets.php` holds real credentials and is excluded via `.gitignore`. Never commit it. Use `config/secrets.example.php` as the template for any new environment.

## License

Proprietary — © CURDUN ICT Solution. All rights reserved.
