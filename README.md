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
- Ticket pages are grouped deterministically from the PDF text before AI enrichment. The importer reconciles AI results with these page groups and reports page coverage, missing enrichment, and prices requiring review in the import check.
- Event advertisements printed in ticket footers are ignored and cannot become products.
- The product price is the reviewed public price of one ticket, rounded upward to the next full EUR amount.
- The ticket PDF price is never used.
- Products are Simple Products, stock managed, Sold individually, and assigned to product category `den-atelier`.
- Products use the `VAT 3%` tax class.

## AI provider

Choose exactly one provider in **Enovos Tickets > Settings**:

- OpenAI
- Gemini
- Custom AI

Only the selected provider is called. Custom AI supports Bearer authentication, a configurable API-key header, or no authentication.

If the official Atelier price cannot be verified, the selected AI provider performs a broader exact-event web search across credible organizers, primary ticket sellers, venues, and event listings. A result is displayed as **Suggested – review**, never as verified.

Every structurally valid concert remains available in the import check. Its ticket price is editable, accepts a decimal point or comma, and must be reviewed before import:

- **Verified** – found through the official verification flow
- **Suggested – review** – found through the broader web search
- **Manual price required** – no credible online price was found

An administrator can enter or replace the price manually. A positive reviewed price is required to create a product, but a missing online price does not block the concert. Suggested and manual prices are marked in product metadata. All accepted prices are rounded upward to the next full EUR amount.

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

## Customer approval

New WooCommerce customer accounts use a two-step approval flow:

1. Every new customer receives an email verification link that is valid for 48 hours.
2. After verification, exact email domains listed under **Enovos Tickets > Settings > Customer approval** are approved automatically.
3. Verified customers from all other domains remain blocked until a user with the `manage_woocommerce` capability approves them.

Pending accounts cannot sign in or check out. Existing users without an Enovos approval status are unaffected.

Manage waiting accounts under **Enovos Tickets > Pending Customers**. Administrators can review and approve verified customers or resend a verification message to customers who have not confirmed their email. Approval links in administrator emails always require a WordPress login and a separate confirmation click.

Enter one exact domain per line in the whitelist without `@`. A domain does not include its subdomains: for example, `company.com` does not match `shop.company.com`. Approval notification recipients are configurable; when left empty, the WordPress administration email is used.

The following notifications can be enabled and edited under **WooCommerce > Settings > Emails**:

- Customer email verification
- Customer approval request
- Customer account approved

The public resend form always returns the same response, whether or not an account exists, and limits repeated requests.

## WooCommerce delivery

A package is reserved for the order as soon as the order is created. Ticket PDFs are sent only when the order reaches **Completed** (default):

1. **Attach Me! (preferred when installed):** the reserved ticket PDF is registered in the order Attachments box and embedded only in the Completed customer email.
2. **Native fallback:** the PDF is attached through WooCommerce's `woocommerce_email_attachments` filter to the Completed customer order email.

The delivery status can still be switched to Processing in AI Settings if needed.

Cancelled or failed orders release only packages that have not been delivered. Refunded delivered packages are invalidated and are not resold automatically.

## Protected files

Master PDFs, generated ticket packages and the import debug log are stored under `wp-content/uploads/woocommerce_uploads/enovos-ticket-shop/` with server-deny files. The PDFs are passed to WooCommerce as filesystem email attachments instead of public URLs.
