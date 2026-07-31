# Stockora AI

Stockora AI is a multi-shop point-of-sale and inventory management system built for small and medium retailers. It includes separate shop and platform-admin panels for managing products, sales, stock, customers, suppliers, subscriptions, and reports.

## Features

- Multi-shop accounts with owner, cashier, and manager roles
- Product catalogue, categories, purchases, stock movement, expiry and low-stock alerts
- Retail and wholesale POS, invoices, returns, customer credit, and bulk buyers
- Customers, suppliers, expenses, daily targets, sales analytics, profit reports, and Z-reports
- CSV import/export and a public storefront
- Subscription plans, payments, trials, announcements, and platform administration
- Optional AI Lab, Autopilot insights, Commerce Cloud, and store customisation tools

## Built with

- PHP with PDO
- MySQL or MariaDB
- Bootstrap 5, Bootstrap Icons, Chart.js, and Inter (loaded from CDNs)

No Composer, Node.js, or build process is required.

## Local setup (XAMPP)

1. Place the project in your web-server document root, for example `C:\xampp\htdocs\stockora`.
2. Create a database called `stockora`.
3. Import [`database.sql`](database.sql) into that database.
4. Review database credentials and `BASE_URL` in [`includes/config.php`](includes/config.php).
5. Start Apache and MySQL from XAMPP.
6. Visit `http://localhost/stockora/`.

To add optional sample shops, products, and transactions after importing the schema, run:

```powershell
php seed.php
```

## Demo accounts

The following accounts are created by `database.sql` / `seed.php` for local development:

| Role | Email | Password |
| --- | --- | --- |
| Super admin | `admin@stockora.com` | `admin123` |
| Shop owner | `ahmed@demo.com` | `demo123` |
| Shop owner | `ali@demo.com` | `demo123` |

Change these credentials before using the application in production.

## Project structure

```text
admin/       Platform administrator pages
api/         Application API endpoints
assets/      Styles, JavaScript, images, and uploads
docs/        User and developer documentation
includes/    Configuration, helpers, and shared layouts
shop/        Shop-owner and staff pages
database.sql Database schema and default data
seed.php     Optional demo-data seeder
store.php    Public storefront entry point
```

## Documentation

- [Documentation overview](docs/README.md)
- [User guide (HTML)](docs/USER_GUIDE.html)
- [Developer guide (HTML)](docs/DEVELOPER_GUIDE.html)
- [User guide (PDF)](docs/Stockora_User_Guide.pdf)
- [Developer guide (PDF)](docs/Stockora_Developer_Guide.pdf)

## License

No license is currently included. Add one before distributing the project.
