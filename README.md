# attendee-importer-for-eventkoi
A simple importer for migrating events, attendees, tickets, and WooCommerce orders from The Events Calendar to EventKoi.

## Installation

1. Download [`dist/eventkoi-tickets-importer.zip`](dist/eventkoi-tickets-importer.zip).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, then install and activate it.
3. Open **Tools → EventKoi Ticket Importer** and follow the import wizard.

Requires WordPress 6.0+, PHP 8.0+, The Events Calendar (Event Tickets / Event Tickets Plus), WooCommerce and EventKoi.

## Building the zip

```bash
bin/build-zip.sh
```
