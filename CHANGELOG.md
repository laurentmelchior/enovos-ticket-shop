# Changelog

## Version 0.3.8 – 2026-08-31
- Added deterministic PDF text analysis to identify the real page count and group ticket pages by concert before AI enrichment.
- Reconciled AI results with authoritative ticket groups so missing events remain visible and footer advertisements cannot create phantom events.
- Added tolerant ticket date normalization and complete page-number merging.
- Fixed the OpenAI multipart upload line endings.
- Kept concerts with failed price verification visible as Blocked, with the reason and full PDF page coverage in the import check.
- Analysis failures now return to the dashboard with a notice and a useful summary; the dashboard log falls back to the protected log file.

## Version 0.3.7 – 2026-08-12
- Added bundled FPDI/FPDF (`setasign/fpdi` + `setasign/fpdf`) as a pure-PHP PDF engine so two-ticket packages can be built without Poppler on the server.
- Engine preference is now Poppler → FPDI/FPDF → Imagick, with automatic fallback if an engine fails (e.g. unsupported PDF features in free FPDI).
- Dashboard / README updated for the new FPDI engine status.

## Version 0.3.6 – 2026-08-12
- Redesigned Settings page with card sections, provider-only credential fields, and toggle switches.
- Redesigned Changelog page as version cards instead of a raw textarea.
- Added dashboard toggles: show/hide Import Debug Log, last import result, and auto-select ready concerts.
- Added feature toggles: write debug logging, Attach Me! sync, native email PDF attachment, Atelier enrichment.
- Renamed admin menu item from "AI Settings" to "Settings".
- Admin list size now controls Ticket Inventory rows and debug log line count.

## Version 0.3.5 – 2026-08-12
- Ticket package PDFs are saved with the concert name in the filename (e.g. `artist-name-ticket-package-001.pdf`).
- Attach Me! media copies use the same concert-based naming.
- Ticket Inventory: added a Download CTA per package (secure admin download with concert-named filename).

## Version 0.3.4 – 2026-08-11
- Fixed double stock reduction on orders: reservation no longer calls sync_product_stock (WooCommerce owns the −1).
- Reduced Imagick fallback ticket PDF size (150 DPI + JPEG compression) and log a warning when a package exceeds 2 MB.
- Clarified engine status: Poppler keeps original pages (preferred for small files).
- Ticket Inventory: delete single or selected packages (removes DB row + PDF); RESERVED packages on open orders are blocked.
- Ticket Inventory shows PDF file size per package.

## Version 0.3.3 – 2026-08-11
- Ticket PDFs are delivered only on the Completed customer order email by default.
- Attach Me! embedding is limited to the Completed email (Processing email embedding disabled).
- Default "Ticket delivery status" setting changed from Processing to Completed.

## Version 0.3.2 – 2026-08-11
- Added WooCommerce Attach Me! integration: reserved ticket PDFs are registered in the order Attachments box via WCAM's `upload_attachments` flow.
- Attach Me! email embedding is enabled for Processing and Completed customer emails; native WooCommerce attachments are skipped for those orders to avoid duplicates.
- Native email attachment now runs at priority 999 and also covers both Processing and Completed customer emails when Attach Me! is not used.
- Dashboard shows whether Attach Me! was detected.

## Version 0.3.1 – 2026-08-11
- Fixed plugin bootstrap: class files now live in `includes/` matching the loader paths (plugin would not start from a flat root layout).
- Moved the import/debug log into the protected ticket storage tree (`woocommerce_uploads/enovos-ticket-shop/logs/`) with the same deny-all server files.
- Replaced the invalid `WP_Query` `title` argument in duplicate-product detection with a prepared SQL title + `date_of_concert` lookup.
- Synced WooCommerce stock quantity from AVAILABLE ticket packages after package creation, reservation, release and refund invalidation.

## Version 0.3.0 – 2026-08-11
- Added a single AI Provider selector: OpenAI, Gemini or Custom AI.
- Only the selected AI provider is used for PDF analysis, Atelier enrichment and ticket price verification.
- Added configurable Custom AI authentication: Bearer token, custom API-key header or no authentication.
- Extended AI PDF extraction with exact 1-based PDF page numbers for every detected physical ticket.
- Ticket counts are derived from actual detected PDF pages, never from printed "Ticket X of Y" totals.
- The uploaded master ticket PDF is retained in a protected WooCommerce uploads directory for package generation.
- Added two-ticket PDF package generation: exactly two physical ticket pages form one sellable ticket package.
- Added a PDF engine status check. Poppler (pdfseparate + pdfunite) is preferred to preserve original ticket pages; PHP Imagick at 300 DPI is the fallback.
- Added Ticket Inventory database table and admin page.
- Ticket package states: AVAILABLE, RESERVED, DELIVERED and INVALIDATED.
- Added safe package reservation per WooCommerce order and order item.
- Reserved packages are released for cancelled or failed orders.
- Delivered packages are invalidated after refund and are never automatically resold.
- Added WooCommerce customer email attachment integration for the assigned two-ticket PDF packages.
- Added setting to deliver tickets on Processing or Completed customer email.
- Added order notes for package reservations.
- Added import validation so manual Product quantity cannot exceed the actual number of complete two-ticket PDF packages.
- Added import check columns for detected PDF pages, available packages, image state and import readiness.
- If PDF package generation fails after product creation, the product is forced to Draft and Out of stock.
- Existing product behavior, den-atelier category assignment, VAT 3%, Sold individually, ACF mapping, verified one-ticket pricing, upward price rounding and artist/group featured image remain active.
- Requires WordPress 6.4+ and PHP 8.0+.

## Version 0.2.2
- Verified ticket prices are rounded upward to the next full EUR amount.
- Product quantity can be adjusted manually in the import check.

## Version 0.2.1
- Improved artist/group product featured image handling.

## Version 0.2.0
- Added den-atelier product category assignment and post-analysis import check.

## Version 0.1.9
- Improved ACF more_about_concert link field mapping.

## Version 0.1.8
- Fixed WooCommerce WC_Tax namespace usage.

## Version 0.1.7
- Strengthened mandatory positive ticket price verification.
