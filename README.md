# Enovos Concert Ticket Shop Importer

WordPress / WooCommerce plugin for importing concert ticket PDFs into products and securely allocating two-ticket PDF packages to customer orders.

## Requirements

- WordPress 6.4 or newer
- PHP 8.0 or newer
- WooCommerce
- Poppler (`pdfseparate` + `pdfunite`) is preferred for lossless page extraction; PHP Imagick with PDF read/write support is supported as a fallback
- A WooCommerce tax class named exactly `VAT 3%`
- ACF is optional; when installed, `date_of_concert` and `more_about_concert` are updated through ACF

## Product rules

- One PDF page is one physical ticket.
- Two physical tickets form one WooCommerce stock unit and one protected PDF package.
- The product price is the verified public price of one ticket, rounded upward to the next full EUR amount.
- The ticket PDF price is never used.
- Products are Simple Products, stock managed, Sold individually, and assigned to product category `den-atelier`.
- Products use the `VAT 3%` tax class.

## AI provider

Choose exactly one provider in **Enovos Tickets > AI Settings**:

- OpenAI
- Gemini
- Custom AI

Only the selected provider is called. Custom AI supports Bearer authentication, a configurable API-key header, or no authentication.

## Ticket inventory

The plugin preserves the imported master PDF in protected storage. During product import, it creates one PDF per sellable unit containing exactly two ticket pages. Packages are tracked as:

- `AVAILABLE`
- `RESERVED`
- `DELIVERED`
- `INVALIDATED`

The **Ticket Inventory** admin page shows package, source pages, product, order and delivery state.

## WooCommerce delivery

A package is reserved for the order. Delivery works in two ways:

1. **Attach Me! (preferred when installed):** the reserved ticket PDF is registered in the order Attachments box and marked for Processing + Completed customer emails inside Attach Me!.
2. **Native fallback:** the PDF is attached through WooCommerce's `woocommerce_email_attachments` filter to the Processing and/or Completed customer order email.

Cancelled or failed orders release only packages that have not been delivered. Refunded delivered packages are invalidated and are not resold automatically.

## Protected files

Master PDFs, generated ticket packages and the import debug log are stored under `wp-content/uploads/woocommerce_uploads/enovos-ticket-shop/` with server-deny files. The PDFs are passed to WooCommerce as filesystem email attachments instead of public URLs.
