# Enovos Concert Ticket Shop Importer

WordPress / WooCommerce plugin for importing concert ticket PDFs into products and securely allocating two-ticket PDF packages to customer orders.

## Requirements

- WordPress 6.4 or newer
- PHP 8.0 or newer
- WooCommerce
- PDF engines (first available wins, with automatic fallback): Poppler (`pdfseparate` + `pdfunite`), bundled FPDI/FPDF (pure PHP, no server binaries), then PHP Imagick with PDF support
- A WooCommerce tax class named exactly `VAT 3%`
- ACF is optional; when installed, `date_of_concert` and `more_about_concert` are updated through ACF

## Product rules

- One PDF page is one physical ticket.
- Two physical tickets form one WooCommerce stock unit and one protected PDF package.
- Ticket pages are grouped deterministically from the PDF text before AI enrichment. The importer reconciles AI results with these page groups and reports page coverage, missing enrichment, and blocked prices in the import check.
- Event advertisements printed in ticket footers are ignored and cannot become products.
- The product price is the verified public price of one ticket, rounded upward to the next full EUR amount.
- The ticket PDF price is never used.
- Products are Simple Products, stock managed, Sold individually, and assigned to product category `den-atelier`.
- Products use the `VAT 3%` tax class.

## AI provider

Choose exactly one provider in **Enovos Tickets > Settings**:

- OpenAI
- Gemini
- Custom AI

Only the selected provider is called. Custom AI supports Bearer authentication, a configurable API-key header, or no authentication.

If AI enrichment or public-price verification fails, every deterministically detected concert remains visible in the import check as **Blocked** with a reason. Analysis failures also return to the dashboard and write a summary containing the PDF page count, detected groups, ready events, blocked events, and unassigned pages.

## Settings toggles

Under **Enovos Tickets > Settings** you can also enable/disable:

- Publish products immediately (otherwise drafts)
- Attach Me! sync when the plugin is available
- Native WooCommerce email PDF attachment
- Atelier enrichment during analysis
- Import / Debug Log panel on the dashboard
- Last import result panel
- Writing new debug log entries
- Auto-selecting ready concerts in the import check
- Ticket delivery on Completed or Processing customer email
- Admin list size (inventory rows + log lines)

## Ticket inventory

The plugin preserves the imported master PDF in protected storage. During product import, it creates one PDF per sellable unit containing exactly two ticket pages. Packages are tracked as:

- `AVAILABLE`
- `RESERVED`
- `DELIVERED`
- `INVALIDATED`

The **Ticket Inventory** admin page shows package, source pages, PDF size, product, order and delivery state. Admins can download any package PDF (filename includes the concert name) or delete selected packages (DB row + PDF file). Packages that are `RESERVED` for an open order cannot be deleted until the order is cancelled/failed.

Package PDFs are stored as `{concert-slug}-ticket-package-{nnn}.pdf` under the protected import directory.

Poppler is preferred when installed. On hosts without Poppler (typical managed WordPress), the bundled **FPDI/FPDF** libraries import original pages in pure PHP and keep packages small. Imagick (150 DPI JPEG) is the last-resort fallback and produces larger files.

Dependencies are shipped under `vendor/` (`setasign/fpdf`, `setasign/fpdi`). After cloning without `vendor/`, run `composer install --no-dev`.

## WooCommerce delivery

A package is reserved for the order as soon as the order is created. Ticket PDFs are sent only when the order reaches **Completed** (default):

1. **Attach Me! (preferred when installed):** the reserved ticket PDF is registered in the order Attachments box and embedded only in the Completed customer email.
2. **Native fallback:** the PDF is attached through WooCommerce's `woocommerce_email_attachments` filter to the Completed customer order email.

The delivery status can still be switched to Processing in AI Settings if needed.

Cancelled or failed orders release only packages that have not been delivered. Refunded delivered packages are invalidated and are not resold automatically.

## Protected files

Master PDFs, generated ticket packages and the import debug log are stored under `wp-content/uploads/woocommerce_uploads/enovos-ticket-shop/` with server-deny files. The PDFs are passed to WooCommerce as filesystem email attachments instead of public URLs.
