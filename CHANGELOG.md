# Changelog

## Version 0.7.20 – 2026-09-24
- Showed the customer's ACF Delivery location on every WooCommerce order (admin shipping column, customer order details and default order emails), and stored a snapshot on the order at checkout.

## Version 0.7.19 – 2026-09-24
- Removed the duplicated screen-reader price range from `{product_price}` and the ready-made `{new_products}` table, so variable products no longer show "Price range: … through …" next to the visible amount in emails.

## Version 0.7.18 – 2026-09-23
- Added a dedicated Completed order Beefree template for Esch-sur-Alzette delivery of gadgets and others products; all other completed purchases keep the existing Completed order email.

## Version 0.7.17 – 2026-09-10
- Added the filter submit button the users list does not provide, so the Daily Digest selection can be applied.
- Added a read-only Delivery column to the users list, showing the existing `delivery` customer field.

## Version 0.7.16 – 2026-09-10
- Added a sortable Daily Digest column and Subscribed / Not subscribed filter to the WordPress users list, using the existing `send_daily_digest` customer field.

## Version 0.7.15 – 2026-09-07
- Added `{admin_order_url}` for HPOS-compatible administrator and shop-manager links from order emails.
- Added `{delivery}` and `{delivery_block}` for including the customer's ACF delivery value and billing name in order emails.
- Documented all plugin shortcodes and email placeholders.

## Version 0.7.14 – 2026-09-07
- Added the `{password_reset_url}` placeholder that issues a reset link even in emails without WooCommerce reset data, listed with all other placeholders on the settings page.

## Version 0.7.13 – 2026-09-07
- Kept localized date and order-number email placeholders together with non-breaking spaces.
- Fixed `{reset_password_url}` in new-account and password-reset email templates.

## Version 0.7.12 – 2026-09-07
- Added per-email subject overrides and preheader preview text to the Beefree email template settings.

## Version 0.7.11 – 2026-09-03
- Removed product descriptions from the ready-made `{new_products}` email table.

## Version 0.7.10 – 2026-09-03
- Added a configurable link color for the ready-made new-products email table and the `{link_color}` Beefree placeholder.

## Version 0.7.9 – 2026-09-03
- Added a signed `{unsubscribe_url}` email placeholder and public confirmation form for safely disabling the existing `send_daily_digest` ACF user preference.

## Version 0.7.8 – 2026-09-03
- Added concert dates and full product descriptions to repeatable new-product email blocks, with compact 100-pixel images and shortened descriptions in the ready-made table.
- Added Ticketmatic as an exact-event source in AI ticket-price search prompts.

## Version 0.7.7 – 2026-09-03
- Added configurable recent-product placeholders and repeatable product blocks to the Beefree email template editor.
- Added a ready-to-paste daily new products digest example to the email template documentation.

## Version 0.7.6 – 2026-09-03
- Replaced the WooCommerce registration notice text with the email confirmation instruction instead of adding a second banner on the My Account page.
- Removed the post-registration banner, its notice cookie and its redirect query parameter; the registration notice shortcode and page-builder element now only display the account status.

## Version 0.7.5 – 2026-09-02
- Read current concert URLs from Atelier's dedicated `ate_show-sitemap.xml` before generic sitemap fallbacks.
- Made the Atelier URL editable in the import check and safely retained administrator-entered links when Cloudflare or network errors prevent verification.
- Read the concert date from the scoped hero `p.date` before structured-data fallbacks.
- Accepted downloadable Ticketmatic header images only when extracted from a date-matched Atelier page.
- Let Gemini 3.6 Flash try up to three verified artist, label, management or press source pages for an automatic image fallback.

## Version 0.7.4 – 2026-09-02
- Replaced Atelier's WordPress text search with a cached, bounded lookup of `/shows/` URLs from its sitemap.

## Version 0.7.3 – 2026-09-02
- Added an Impreza/WPBakery-compatible registration notice shortcode and page-builder element for the WooCommerce My Account page.
- Preserved the post-registration notice through a short-lived secure cookie when redirects or caching remove its query parameter.
- Required Atelier concert pages to match the authoritative PDF date before their URL, description, price context or image can be used.
- Read the product image from the header metadata or hero area of the date-matched Atelier page instead of ranking image filenames.
- Added Cloudflare, event-date and header-image diagnostics for Atelier page access to the system check.

## Version 0.7.2 – 2026-09-02
- Expanded Ticket Inventory and the ticket PDF upload dashboard to the full WordPress admin content width.
- Added `user_login` compatibility to all Enovos customer emails for the Email Templates plugin.
- Added a Gemini web fallback that finds a verified artist source page when Atelier has no usable image or URL.
- Accepted title-matched social images with neutral CDN filenames and retried blocked image HEAD checks with a bounded GET request.
- Added the artist image source or missing-image reason to the import check.

## Version 0.7.1 – 2026-09-02
- Added a reliable post-registration confirmation banner on the WooCommerce My Account page.
- Added Customer status to WordPress Users with Email not confirmed, Pending, Approved and Rejected states.
- Ticket Inventory now displays the formatted WooCommerce order number supplied by custom order-number plugins.
- Artist images are ranked against each concert title, generic Atelier images are rejected, and one image cannot be reused for different concerts in the same import.

## Version 0.7.0 – 2026-09-02
- Fixed Attach Me! customer visibility so ticket PDFs remain hidden until the configured delivery status.
- Kept Completed tickets visible after a Processing delivery configuration advances to Completed.
- Added an optional, non-duplicating audit and repair tool for Attach Me! visibility metadata created before version 0.7.0.

## Version 0.6.0 – 2026-09-02
- Renamed the visible plugin and admin interface to Enovos WooCommerce Addons without changing the installed slug, settings keys or update path.
- Declared WooCommerce HPOS compatibility and added explicit ticket reservation for Store API / Block Checkout orders.
- Added an optional Enovos ticket-status box to WooCommerce orders beside Attach Me!.
- Added an optional, nonce-protected resend action that reuses the existing Attach Me! files without reserving another package.
- Added optional order notes for successful original and repeated ticket emails.
- Added an optional system check for the PDF engine, VAT 3% tax class, den-atelier category, selected AI credentials, protected uploads and Attach Me!.
- Kept each visible addition independently switchable and documented its complete rollback path.

## Version 0.5.1 – 2026-09-01
- Replaced the verification resend form inside the login form with a Lost Password-style link and a dedicated resend page.

## Version 0.5.0 – 2026-09-01
- Added direct, nonce-protected Approve and Reject actions to WooCommerce Pending Customers.
- Added a persistent rejected status that blocks login and checkout and notifies the customer.
- Added scanner-safe Approve and Reject links to administrator approval emails; decisions still require an authenticated confirmation.
- Added optional Beefree HTML overrides for every registered WooCommerce email.
- Added an Email Templates settings tab with per-email HTML, native and Enovos placeholders, and order-detail tokens.

## Version 0.4.1 – 2026-09-01
- Split Enovos settings into readable Ticket Shop and Customer Approval tabs.
- Preserved settings from the inactive tab when either section is saved.
- Moved Pending Customers from Enovos Tickets to the WooCommerce menu.

## Version 0.4.0 – 2026-09-01
- Added mandatory email verification for newly registered WooCommerce customers.
- Added exact-match domain whitelisting for automatic approval after email verification.
- Added manual administrator approval for verified customers outside the domain whitelist.
- Added a Pending Customers admin page with secure review, approval and verification-resend actions.
- Added editable WooCommerce emails for verification, administrator approval requests and customer approval confirmations.
- Added 48-hour one-time verification links and rate-limited, enumeration-safe resending.

## Version 0.3.10 – 2026-08-31
- Import/Debug Log and the changelog raw view now use black text on a light background.
- Widened the ticket price input in the import check and right-aligned the amount.
- The Enovos Tickets page now uses the full window width for the wide import check table.

## Version 0.3.9 – 2026-08-31
- Added a broader exact-event web search for price suggestions when the official Atelier price cannot be verified.
- Made ticket prices editable in the import check, with support for decimal points and commas.
- Missing prices no longer block structurally valid concerts; administrators can enter and approve a positive price manually.
- Added distinct Verified, Suggested – review, and Manual price required states.
- Manual and approved suggested prices retain their source method in WooCommerce product metadata.
- Updated the plugin author to Enovos Digital Marketing / Bromance INC.

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
