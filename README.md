# Enovos WooCommerce Addons

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
- Featured images must match the artist or group named by the concert. Generic venue, header and Atelier placeholder images are rejected, and the same image is not reused for different concerts in one import.

## AI provider

Choose exactly one provider in **Enovos WooCommerce Addons > Settings > Ticket Shop**:

- OpenAI
- Gemini
- Custom AI

Only the selected provider is called. Custom AI supports Bearer authentication, a configurable API-key header, or no authentication.

If the official Atelier price cannot be verified, the selected AI provider performs a broader exact-event web search across credible organizers, primary ticket sellers, venues, and event listings. A result is displayed as **Suggested – review**, never as verified.

With Gemini selected, a missing artist image triggers a separate grounded web search even when no Atelier URL was found. The plugin uses the returned official artist, label, management, organizer or reputable press page as evidence, extracts its social/structured image, and verifies that the image can be downloaded before import. The import check shows the selected image source or the reason no verified image was accepted.

Every structurally valid concert remains available in the import check. Its ticket price is editable, accepts a decimal point or comma, and must be reviewed before import:

- **Verified** – found through the official verification flow
- **Suggested – review** – found through the broader web search
- **Manual price required** – no credible online price was found

An administrator can enter or replace the price manually. A positive reviewed price is required to create a product, but a missing online price does not block the concert. Suggested and manual prices are marked in product metadata. All accepted prices are rounded upward to the next full EUR amount.

## Settings toggles

Under **Enovos WooCommerce Addons > Settings > Ticket Shop** you can also enable/disable:

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
- Enovos ticket status box on WooCommerce orders
- Ticket email resend through existing Attach Me! attachments
- System preflight checks
- Successful ticket-delivery order notes
- Existing Attach Me! visibility audit and repair

## Order support and delivery

The WooCommerce order screen can show a compact **Enovos tickets** box beside Attach Me!. It lists the assigned concert, package number, ticket pages and Enovos inventory status. Attach Me! remains responsible for the attachment list, secure customer downloads and embedding the PDF in the configured customer email.

Customer visibility follows **Ticket delivery status**. With the default `Completed` setting, Attach Me! registers the file on the order immediately but hides it from Order Details and My Account until the order is Completed. With `Processing`, it is visible for both Processing and Completed orders.

For delivered packages, administrators can resend the configured Completed or Processing customer email. The same Attach Me! files are used again: no new package is reserved and stock does not change. Successful original and repeated deliveries can be recorded in the order notes.

The plugin explicitly supports WooCommerce HPOS and also reserves ticket packages when an order is created through the Store API / Block Checkout.

## System check

When enabled, **Settings > Ticket Shop** shows read-only preflight checks for:

- a supported PDF engine
- the exact `VAT 3%` WooCommerce tax class
- the `den-atelier` product category
- credentials for the selected AI provider
- writable protected uploads
- an active and enabled Attach Me! installation

## Feature switches and rollback

The order box, resend action, system check, delivery notes and existing-attachment repair have independent settings and do not create new database tables. Disabling a switch stops its hooks on the next request without deleting existing order or ticket data.

The repair card under **Settings > Ticket Shop** can audit Enovos ticket attachments created before version 0.7.0. It changes only an identified Attach Me! visibility-status array and only when the installed Attach Me! model exposes a supported public metadata setter. If the storage structure is not recognized, it reports the incompatibility and writes nothing. It never uploads or duplicates an attachment.

For complete code removal in a future release:

- Order box + resend: remove `includes/class-enovos-order-tickets.php`, its bootstrap `require_once`, initialization and the two matching settings.
- Delivery notes: remove `includes/class-enovos-delivery-tracking.php`, its bootstrap `require_once`, initialization and setting.
- System check: remove `includes/class-enovos-system-check.php`, its bootstrap `require_once`, render call and setting.
- Existing-attachment repair: remove `includes/class-enovos-attach-me-repair.php`, its bootstrap `require_once`, initialization, render call and setting.
- HPOS + Store API safety: `includes/class-enovos-woocommerce-compatibility.php` is intentionally not switchable because removing Store API reservation can produce orders without assigned ticket packages.

## Ticket inventory

The plugin preserves the imported master PDF in protected storage. During product import, it creates one PDF per sellable unit containing exactly two ticket pages. Packages are tracked as:

- `AVAILABLE`
- `RESERVED`
- `DELIVERED`
- `INVALIDATED`

The **Ticket Inventory** admin page shows package, source pages, PDF size, product, order and delivery state. Its Order column uses WooCommerce's formatted order number, including numbers supplied by compatible custom order-number plugins, while internal links continue to use the numeric order ID. Admins can download any package PDF (filename includes the concert name) or delete selected packages (DB row + PDF file). Packages that are `RESERVED` for an open order cannot be deleted until the order is cancelled/failed.

Package PDFs are stored as `{concert-slug}-ticket-package-{nnn}.pdf` under the protected import directory.

Poppler is preferred when installed. On hosts without Poppler (typical managed WordPress), the bundled **FPDI/FPDF** libraries import original pages in pure PHP and keep packages small. Imagick (150 DPI JPEG) is the last-resort fallback and produces larger files.

Dependencies are shipped under `vendor/` (`setasign/fpdf`, `setasign/fpdi`). After cloning without `vendor/`, run `composer install --no-dev`.

## Customer approval

New WooCommerce customer accounts use a two-step approval flow:

1. Every new customer receives an email verification link that is valid for 48 hours.
2. After verification, exact email domains listed under **Enovos WooCommerce Addons > Settings > Customer Approval** are approved automatically.
3. Verified customers from all other domains remain blocked until a user with the `manage_woocommerce` capability approves them.

Pending and rejected accounts cannot sign in or check out. Rejected accounts remain in WordPress and receive a rejection notification. Existing users without an Enovos approval status are unaffected.

Manage waiting accounts under **WooCommerce > Pending Customers**. Administrators can directly approve or reject verified customers, or resend a verification message to customers who have not confirmed their email. Administrator emails contain separate Approve and Reject links. Each link requires a WordPress login and a POST confirmation, so email security scanners cannot change an account status.

After registration, the My Account page displays a confirmation banner asking the customer to check their email. **WordPress > Users** shows a Customer status column with **Email not confirmed**, **Pending**, **Approved** or **Rejected**. Users created before this workflow and users outside it show no Enovos status.

Enter one exact domain per line in the whitelist without `@`. A domain does not include its subdomains: for example, `company.com` does not match `shop.company.com`. Approval notification recipients are configurable; when left empty, the WordPress administration email is used.

The following notifications can be enabled and edited under **WooCommerce > Settings > Emails**:

- Customer email verification
- Customer approval request
- Customer account approved
- Customer account rejected

The login page shows **Did not receive the verification email?** as a simple link next to the standard lost-password option. It opens a separate WooCommerce-style page where customers can request a new verification message. The resend flow always returns the same response, whether or not an account exists, and limits repeated requests.

## Beefree email templates

Open **Enovos WooCommerce Addons > Settings > Email Templates** to optionally replace any registered WooCommerce email with a complete Beefree HTML export. Enable **Use custom HTML templates**, select an email, paste its HTML and save. The setting is off by default, and an empty field always falls back to the original WooCommerce or plugin template.

Custom templates apply to emails configured as HTML. They are sent as the complete document without an additional WooCommerce header or footer. Existing email recipients, subjects and attachments are unchanged, including Enovos ticket PDF attachments.

The settings page lists all supported placeholders and highlights those relevant to the selected email. Click a placeholder to copy it into Beefree. Common examples are:

- Shop and customer: `{site_title}`, `{site_url}`, `{store_address}`, `{customer_name}`, `{customer_email}`
- Orders: `{order_number}`, `{order_date}`, `{order_total}`, `{billing_address}`, `{view_order_url}`, `{order_items}`
- Accounts: `{login_url}`, `{reset_password_url}`
- Approval: `{verification_url}`, `{customer_domain}`, `{approve_url}`, `{reject_url}`

WooCommerce email variables use `{placeholder}` syntax, not WordPress `[shortcode]` syntax. Placeholders that do not apply to the selected email remain unchanged.

## WooCommerce delivery

A package is reserved for the order as soon as the order is created. Ticket PDFs are sent only when the order reaches **Completed** (default):

1. **Attach Me! (preferred when installed):** the reserved ticket PDF is registered in the order Attachments box and embedded only in the Completed customer email.
2. **Native fallback:** the PDF is attached through WooCommerce's `woocommerce_email_attachments` filter to the Completed customer order email.

The delivery status can still be switched to Processing in AI Settings if needed.

Cancelled or failed orders release only packages that have not been delivered. Refunded delivered packages are invalidated and are not resold automatically.

## Protected files

Master PDFs, generated ticket packages and the import debug log are stored under `wp-content/uploads/woocommerce_uploads/enovos-ticket-shop/` with server-deny files. The PDFs are passed to WooCommerce as filesystem email attachments instead of public URLs.
