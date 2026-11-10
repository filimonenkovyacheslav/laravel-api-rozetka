# Laravel Order Operations Admin

A Laravel-based back-office application for managing e-commerce orders, packing information, and **Nova Poshta** shipping workflows.

This repository is the **public portfolio snapshot** of an independently developed operations system. It shows the earlier order-management and fulfilment modules. The newer **Rozetka Supplier API** integrations and production-oriented automation are maintained in a separate private codebase.

## What the system does

- **Order management:** an admin interface for viewing orders, customers, delivery details, statuses, and order items.
- **Packing data:** a product-dimensions catalogue with weights and multiple packaging places per product.
- **Shipping workflow:** integration with Nova Poshta for creating and managing shipping documents (TTNs), including batch-processing utilities.
- **Address handling:** support for delivery destinations and recipient details needed by the carrier.
- **Documents and exports:** order attachments, download tracking, and spreadsheet export tools.
- **Operational tooling:** Artisan commands and a Laravel service layer separating order data from delivery-provider requests.

## Tech stack

| Area | Technologies |
| --- | --- |
| Backend | PHP, Laravel 8 |
| Admin UI | Laravel Blade, Bootstrap |
| Database | MySQL, Eloquent ORM |
| Integrations | Nova Poshta API, external order data |
| Utilities | Laravel Artisan, migrations, Excel exports |

## Code structure

```text
app/
  Http/Controllers/Admin/       # Order, product and shipping admin workflows
  Models/                       # Orders, products, packaging, files, TTN batches
  Services/NovaPoshta/           # Nova Poshta API client and shipment logic
  Console/Commands/             # Operational commands
resources/views/admin/          # Blade admin screens
database/migrations/            # Database schema
routes/web.php                  # Admin routes
```

## Engineering focus

The project addresses practical fulfilment issues: orders with several items or physical packages, validation of delivery details, storing shipment identifiers, keeping operational records, and reducing repetitive work for managers.

The main design priority is to **keep order processing understandable and controllable** while using service classes for external API interactions.

## About this public snapshot

- `main` represents an **earlier stage** of the application; it is **not** the active development branch.
- The current private implementation includes additional Rozetka Supplier API features, document-status checks, automated TTN creation, guarded reissue operations, and fake-HTTP regression tests. Those later features are **not included in this snapshot**.
- This is a code portfolio, **not** a ready-to-deploy SaaS product or a public hosted demo.
- External credentials and any production configuration must be supplied separately. Never commit real API tokens or `.env` files.

## Author

Developed by [Vyacheslav Filimonenko](https://github.com/filimonenkovyacheslav), Full-Stack Developer — PHP/Laravel, APIs, and operational integrations.

> The Laravel framework is an independent open-source project. Public visibility of this application repository does not by itself grant a license to reuse its application-specific code.
