# Stockora AI — Multi-Shop POS & Inventory Management System

Stockora AI is a PHP and MySQL web application for managing retail shops from one place. Each shop has its own secure workspace for day-to-day operations, while a platform administrator can manage shops, subscriptions, plans, payments, and system-wide activity.

It is designed for grocery stores, general stores, wholesalers, and other small-to-medium retail businesses.

## What the application provides

### Shop operations

- Point of Sale (POS) for retail and wholesale sales
- Product, category, purchase, stock, and reorder management
- Expiry alerts and stock-value tracking
- Customers, suppliers, bulk buyers, and customer credit/dues
- Sales history, invoices, returns, expenses, and daily targets
- Sales analytics, profit calculator, and Z-report
- CSV product import and CSV exports for products, sales, stock, customers, and buyers

### Store growth tools

- Public storefront (`store.php`)
- Online-order management
- Store setup wizard and storefront customisation
- Theme marketplace with theme import/export
- AI Lab, Autopilot insights, and Commerce Cloud tools for supported plans

### Platform administration

- Shop accounts and account-status management
- Subscription plans, trials, subscriptions, and payments
- Revenue, analytics, top-shop, activity, and platform-health reports
- Announcements, invoice generation, feature usage, and data export

## Technology

| Area | Technology |
| --- | --- |
| Backend | PHP with PDO |
| Database | MySQL 8+ or MariaDB 10.4+ |
| UI | Bootstrap 5, Bootstrap Icons, Chart.js, custom CSS/JavaScript |
| Web server | Apache (XAMPP supported) |
| Currency / timezone defaults | PKR / Asia/Karachi |

The app has no Composer, Node.js, or front-end build-step requirement. Front-end libraries are loaded from CDNs.

## Requirements

- PHP 8.0 or newer with `pdo_mysql` enabled
- MySQL or MariaDB
- Apache with PHP support (XAMPP is recommended for local development)
- Write permission for `assets/uploads/` if shop logos or product images will be uploaded

## Installation (XAMPP / local development)

1. Clone or download this repository into the XAMPP web root:

   ```text
   C:\xampp\htdocs\stockora
   ```

2. Start **Apache** and **MySQL** from the XAMPP Control Panel.

3. Create a database named `stockora` using phpMyAdmin or the MySQL command line.

4. Import [`database.sql`](database.sql) into the `stockora` database.

5. Open [`includes/config.php`](includes/config.php) and update these values for your environment:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_PORT', '3306');
   define('DB_NAME', 'stockora');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('BASE_URL', 'http://localhost/stockora');
   ```

6. Open [http://localhost/stockora/](http://localhost/stockora/) in your browser.

### Optional demo data

After importing the database schema, run the following command from the project directory to load sample shops, products, customers, and sales:

```powershell
php seed.php
```

The seeder skips execution when shop records already exist, so it is safe to run once on a new local database.

## Demo credentials

Use these only for local development. Change every default password before deployment.

| Role | Email | Password | Access |
| --- | --- | --- | --- |
| Super admin | `admin@stockora.com` | `admin123` | Platform admin panel |
| Shop owner | `ahmed@demo.com` | `demo123` | Ahmed General Store |
| Shop owner | `ali@demo.com` | `demo123` | Karachi Super Mart |

## Import and export

The import/export features are implemented as application pages, not as persistent file-storage folders:

- **Import:** [`shop/import.php`](shop/import.php) accepts CSV/TXT product files (maximum 5 MB), updates existing products when a duplicate is found, and records the outcome in the `import_logs` database table.
- **Import template:** [`shop/download_template.php`](shop/download_template.php) provides the CSV template for product imports.
- **Export:** [`shop/export.php`](shop/export.php) generates downloads directly in the browser for products, sales, stock, customers, and buyers, and records the event in the `export_logs` database table.
- **Admin export:** [`admin/shops_export.php`](admin/shops_export.php) exports platform-level shops, payments, and subscription data.

The repository also contains [`imports/`](imports/) and [`exports/`](exports/) folders as tracked placeholders for deployments that require a writable, server-side staging location. The current application streams exports to the browser and does not save uploaded import files permanently.

## Project structure

```text
admin/          Platform administrator pages
api/            API endpoints used by the application
assets/         CSS, JavaScript, branding, product images, and uploads
docs/           User and developer guides
exports/        Tracked placeholder for optional server-side export staging
imports/        Tracked placeholder for optional server-side import staging
includes/       Database configuration, helpers, and shared layouts
shop/           Shop owner and staff pages
database.sql    Database schema and default platform records
seed.php        Optional local demo-data seeder
store.php       Public storefront entry point
```

## Main entry points

| URL / file | Purpose |
| --- | --- |
| `index.php` | Routes signed-in users to their appropriate dashboard |
| `landing.php` | Public landing page |
| `login.php` / `register.php` | Authentication and shop registration |
| `shop/index.php` | Shop dashboard |
| `admin/index.php` | Platform administrator dashboard |
| `store.php` | Public online storefront |

## Deployment notes

- Set a production database user and a strong password in `includes/config.php`.
- Set `BASE_URL` to the live HTTPS URL.
- Turn off PHP error display (`display_errors`) in production.
- Ensure `assets/uploads/` has only the write permissions required by the web-server user.
- Replace all demo credentials and review sample records before going live.
- Back up the database regularly; exported CSV reports are useful for operational backups but do not replace database backups.

## Documentation

- [Documentation overview](docs/README.md)
- [User guide (HTML)](docs/USER_GUIDE.html)
- [Developer guide (HTML)](docs/DEVELOPER_GUIDE.html)
- [User guide (PDF)](docs/Stockora_User_Guide.pdf)
- [Developer guide (PDF)](docs/Stockora_Developer_Guide.pdf)

## License

No license is currently included. Add a license before redistributing the project.
