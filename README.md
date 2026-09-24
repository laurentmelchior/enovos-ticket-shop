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
- An Atelier concert URL is accepted only when its page date exactly matches the date extracted from the PDF.
- AI price verification also checks exact-event listings on `ticketmatic.com` and `apps.ticketmatic.com`; a Ticketmatic price is accepted only when the concert title and date match.
- Featured images are taken from the header metadata or hero area of that date-matched Atelier page. Official `apps.ticketmatic.com` images embedded there are accepted; arbitrary AI-supplied Ticketmatic URLs are not. Generic assets are rejected, every image is verified as downloadable, and the same image is not reused for different concerts in one import.

## AI provider

Choose exactly one provider in **Enovos WooCommerce Addons > Settings > Ticket Shop**:

- OpenAI
- Gemini
- Custom AI

Only the selected provider is called. Custom AI supports Bearer authentication, a configurable API-key header, or no authentication.

If the official Atelier price cannot be verified, the selected AI provider performs a broader exact-event web search across credible organizers, primary ticket sellers, venues, and event listings. A result is displayed as **Suggested – review**, never as verified.

During enrichment, the dedicated `https://www.atelier.lu/ate_show-sitemap.xml` is searched first, with Atelier's generic sitemaps retained as fallbacks. Candidate pages are checked against the authoritative PDF date because Atelier keeps past concerts on the same `/shows/<slug>/` path. The importer reads the scoped hero `<p class="date">` first, then structured event data and HTML time metadata. This prevents dates from recommendation sections from being mistaken for the concert date.

The import check always provides an editable Atelier URL field, prefilled when automatic matching succeeds. Administrators can add or replace the link before import. Only HTTPS links on `atelier.lu` are accepted. A reachable page with a proven date mismatch is rejected; an explicitly entered link is retained when Cloudflare or a network error prevents server-side verification. A date-matched manual page can also replace the product image with its verified header image.

With Gemini selected, a missing artist image triggers a separate grounded web search even when no date-matched Atelier page was found. Gemini 3.6 Flash returns up to three ordered source pages: official artist, label or management, then dedicated reputable press. The plugin tries each page until its social/structured image passes artist matching, download and uniqueness checks. Venue and event-listing pages are excluded. The import check shows the selected image source or the reason no verified image was accepted.

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
- Atelier page access, including Cloudflare blocking, event-date parsing and header-image availability
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

After registration, the standard WooCommerce notice on the My Account page asks the customer to check their inbox and confirm the email address. **WordPress > Users** shows a Customer status column with **Email not confirmed**, **Pending**, **Approved** or **Rejected**. Users created before this workflow and users outside it show no Enovos status.

### Account status shortcode

The post-registration confirmation needs no shortcode; it is the WooCommerce notice. For Impreza/WPBakery layouts that additionally display the Enovos status of a signed-in customer, add the **Enovos Registration Notice** element above the element or text block containing `[woocommerce_my_account]`, or add this shortcode in a WPBakery text block:

```
[enovos_registration_notice]
```

The plugin registers two WordPress shortcodes:

- `[enovos_registration_notice]`
- `[enovos_account_status]` (alias with identical output)

Both accept `type="auto"` (default) or `type="status"`, `class="woocommerce-message"` for the wrapper class, and `debug="1"` for temporary administrator-only diagnostics. They display the current Enovos account status when applicable. Exclude the My Account page from full-page caching so customer-specific state is preserved.

WooCommerce Core shortcodes such as `[woocommerce_cart]`, `[woocommerce_checkout]`, `[woocommerce_my_account]`, `[woocommerce_order_tracking]`, `[products]`, `[product_page]`, `[product_category]`, `[product_categories]`, `[add_to_cart]`, `[add_to_cart_url]`, `[related_products]` and `[shop_messages]` are provided by WooCommerce, not this plugin.

Enter one exact domain per line in the whitelist without `@`. A domain does not include its subdomains: for example, `company.com` does not match `shop.company.com`. Approval notification recipients are configurable; when left empty, the WordPress administration email is used.

The following notifications can be enabled and edited under **WooCommerce > Settings > Emails**:

- Customer email verification
- Customer approval request
- Customer account approved
- Customer account rejected

The login page shows **Did not receive the verification email?** as a simple link next to the standard lost-password option. It opens a separate WooCommerce-style page where customers can request a new verification message. The resend flow always returns the same response, whether or not an account exists, and limits repeated requests.

## Beefree email templates

Open **Enovos WooCommerce Addons > Settings > Email Templates** to optionally replace any registered WooCommerce email with a complete Beefree HTML export. Enable **Use custom HTML templates**, select an email, paste its HTML and save. The setting is off by default, and an empty field always falls back to the original WooCommerce or plugin template.

Custom templates apply to emails configured as HTML. They are sent as the complete document without an additional WooCommerce header or footer. Existing email recipients and attachments are unchanged, including Enovos ticket PDF attachments.

A second Completed order entry, **Completed order – Esch-sur-Alzette (gadgets & others)** (`customer_completed_order_esch_gadgets`), is available in the same selector. WooCommerce still sends the standard `customer_completed_order` email; the plugin swaps in this HTML, subject and preheader only when both conditions hold:

- the customer's `delivery` field equals or contains `Esch-sur-Alzette` (case-insensitive)
- every product line item belongs to the WooCommerce category slug `gadgets` or `others`

If the variant template is empty, or either condition fails, the existing Completed order template is used unchanged. Mixed carts that also include other categories (for example `den-atelier` tickets) keep the standard email.

The **Subject** and **Preheader text** fields are stored separately for each selected email and apply even when custom HTML templates are disabled. Leave **Subject** empty to retain the WooCommerce subject. The preheader is hidden in the message body and shown as preview text by supporting email clients; 40–90 characters are recommended. Both fields support the placeholders listed on the page. In custom HTML, add `{preheader}` immediately after the opening `<body>` tag to control its position; otherwise the plugin inserts it automatically.

The **Email link color** field controls text links in the ready-made `{new_products}` table. Its default follows the WooCommerce email base color. Use `{link_color}` in Beefree HTML to apply the same color to custom links.

The settings page lists every supported placeholder and highlights those relevant to the selected email. Click a placeholder to copy it into Beefree:

- Shop: `{site_title}`, `{site_address}`, `{site_url}`, `{store_address}`, `{store_email}`, `{shop_url}`, `{link_color}`, `{preheader}`
- Customer: `{customer_name}`, `{customer_email}`, `{customer_first_name}`, `{customer_last_name}`
- Orders: `{order_number}`, `{order_date}`, `{order_total}`, `{order_subtotal}`, `{order_status}`, `{payment_method}`, `{order_billing_full_name}`, `{billing_first_name}`, `{billing_last_name}`, `{billing_address}`, `{billing_phone}`, `{shipping_address}`, `{view_order_url}`, `{admin_order_url}`, `{delivery}`, `{delivery_block}`, `{order_items}`
- Accounts: `{login_url}`, `{reset_password_url}`, `{password_reset_url}`, `{unsubscribe_url}`
- Approval: `{verification_url}`, `{customer_domain}`, `{approve_url}`, `{reject_url}`
- New products: `{new_products}`, `{new_products_count}`, `{new_products_date}`, `{#new_products}`, `{/new_products}`, `{#no_new_products}`, `{/no_new_products}`, `{product_name}`, `{product_price}`, `{product_url}`, `{product_image}`, `{product_image_url}`, `{product_sku}`, `{product_description}`, `{product_concert_date}`, `{product_index}`

`{admin_order_url}` uses WooCommerce's order edit URL, so it works with both HPOS and legacy order storage. WordPress authentication and WooCommerce capabilities still protect the destination. For example: `<a href="{admin_order_url}">Order {order_number}</a>`.

`{delivery}` reads the ACF `delivery` field from the order's registered customer. `{delivery_block}` renders the billing first and last name followed by that delivery value in an email-safe HTML block, matching the information used on packing slips. Guest orders have no user ACF field, so `{delivery}` is empty while `{delivery_block}` still shows the billing name.

Order numbers and localized dates use non-breaking spaces. `{reset_password_url}` contains the signed link that WooCommerce new-account and password-reset emails provide. `{password_reset_url}` reuses that link when it exists and otherwise issues a fresh reset key for the recipient, so it also works in emails such as the approval notification. Templates without the token never issue a key.

WooCommerce email variables use `{placeholder}` syntax, not WordPress `[shortcode]` syntax. Placeholders that do not apply to the selected email remain unchanged.

### Daily new products digest

The editor can query recently published, catalog-visible WooCommerce products independently of the selected email. Configure the time window (24 hours by default) and maximum number of products (12 by default) above the template field.

The existing customer ACF field `send_daily_digest` (`field_6a450c952f711`) remains the subscription source. The Child Theme sender must select only users whose field value is enabled. The plugin does not subscribe customers automatically. Administrators can see that stored status in the WordPress users list as a sortable Daily Digest column and can filter the list to subscribed or not-subscribed customers with the filter button above the table. The same list shows the customer's `delivery` field in a read-only Delivery column. The list does not change either field.

Use `{new_products}` for a ready-made product table, `{new_products_count}` for the result count and `{new_products_date}` for the localized current date. Date tokens use non-breaking spaces so the complete date stays on one line. To design each product in Beefree, place HTML between `{#new_products}` and `{/new_products}`. The editor repeats that block for every product and supports `{product_name}`, `{product_price}`, `{product_url}`, `{product_image}`, `{product_image_url}`, `{product_sku}`, `{product_description}`, `{product_concert_date}` and `{product_index}` inside it. `{product_description}` contains the complete WooCommerce product description; the ready-made `{new_products}` table omits it. `{product_price}` keeps the visible WooCommerce price and its suffix but removes screen-reader-only markup, so variable products render `0,00 € – 7,00 €` instead of repeating it as `Price range: 0,00 € through 7,00 €`. Content between `{#no_new_products}` and `{/no_new_products}` is shown only when the query returns no products. Add `{unsubscribe_url}` to the digest email to provide a signed link valid for 30 days. The link opens a public confirmation form; viewing the link alone never changes the subscription.

The following complete HTML document can be pasted into the editor and then restyled in Beefree:

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>New products from {site_title}</title>
</head>
<body style="margin:0; padding:0; background:#f5f5f5;">
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:#f5f5f5;">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:640px; background:#ffffff;">
          <tr>
            <td style="padding:32px; font-family:Arial,sans-serif; color:#222222;">
              <p style="margin:0 0 16px;">Hi {customer_name},</p>
              <p style="margin:0 0 20px;">Here are the new products published in our shop:</p>

              {#new_products}
              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; border-bottom:1px solid #e5e5e5;">
                <tr>
                  <td style="width:100px; padding:16px 16px 16px 0; vertical-align:top;">
                    <a href="{product_url}" style="text-decoration:none;">{product_image}</a>
                  </td>
                  <td style="padding:16px 0; vertical-align:top;">
                    <p style="margin:0 0 8px; font-size:16px; font-weight:bold;">
                      <a href="{product_url}" style="color:{link_color}; text-decoration:none;">{product_name}</a>
                    </p>
                    <p style="margin:0 0 8px;"><strong>Concert date:</strong> {product_concert_date}</p>
                    <p style="margin:0 0 12px;">{product_price}</p>
                    <div style="margin:0 0 12px;">{product_description}</div>
                    <a href="{product_url}" style="display:inline-block; color:{link_color}; text-decoration:none;">View product</a>
                  </td>
                </tr>
              </table>
              {/new_products}

              {#no_new_products}
              <p style="margin:20px 0;">There are no new products in the selected time window.</p>
              {/no_new_products}

              <p style="margin:24px 0 0; font-size:12px; color:#777777;">
                You receive this email because you opted in to our daily new products digest.
                <a href="{unsubscribe_url}" style="color:{link_color};">Unsubscribe from this digest</a>.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
```

## WooCommerce delivery

A package is reserved for the order as soon as the order is created. Ticket PDFs are sent only when the order reaches **Completed** (default):

1. **Attach Me! (preferred when installed):** the reserved ticket PDF is registered in the order Attachments box and embedded only in the Completed customer email.
2. **Native fallback:** the PDF is attached through WooCommerce's `woocommerce_email_attachments` filter to the Completed customer order email.

The delivery status can still be switched to Processing in AI Settings if needed.

Cancelled or failed orders release only packages that have not been delivered. Refunded delivered packages are invalidated and are not resold automatically.

## Protected files

Master PDFs, generated ticket packages and the import debug log are stored under `wp-content/uploads/woocommerce_uploads/enovos-ticket-shop/` with server-deny files. The PDFs are passed to WooCommerce as filesystem email attachments instead of public URLs.

## Releases

Every change ships as its own new version. Never reuse or extend an already released version.

1. Raise the version in `enovos-ticket-shop.php`, both in the plugin header `Version:` and in `ENOVOS_TICKET_SHOP_VERSION`.
2. Add a new `## Version X.Y.Z – YYYY-MM-DD` section at the top of `CHANGELOG.md` describing that change.

Pushing both files to `main` lets the release workflow tag the version and build the update package, so the update checker offers the new version to every site.
