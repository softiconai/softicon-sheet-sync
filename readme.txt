=== SoftIcon Sheet Sync for WooCommerce ===
Contributors: softiconai
Tags: woocommerce, google sheets, stock, inventory, bulk edit
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Two-way sync between WooCommerce and Google Sheets. Edit stock, prices and SKUs in a spreadsheet and your store updates instantly.

== Description ==

https://www.youtube.com/watch?v=_3l4LeW_afo

Updating stock or prices one product at a time is slow. SoftIcon Sheet Sync puts every product and variation in a Google Sheet, one row each. Edit the sheet and WooCommerce updates within seconds. Change something in WooCommerce, or sell something, and the sheet updates too.

Everything is free. There is no premium version, no product limit and no locked features.

= Features =

* **Two-way sync** between WooCommerce and Google Sheets.
* **Instant updates from the sheet**: a small Apps Script sends each edit to your store and writes the result in a *Sync status* column, so you see right away if a value was rejected.
* **Automatic updates to the sheet** a few seconds after a product is saved, trashed, deleted or its stock changes after an order.
* **Simple and variable products**: every variation gets its own row.
* **Columns you choose**: name, SKU, regular price, sale price, stock (quantity or In stock / Out of stock / On backorder), status, categories, short description, description, total sales and product URL.
* **Bulk editing** with everything a spreadsheet offers: paste a column, fill down, find and replace.
* **Sheet menu** with *Fetch from WooCommerce* and *Send all rows to WooCommerce*.
* **Formatted sheet**: frozen header, read-only columns greyed out with an edit warning, price formats and a status dropdown.
* **Scheduled full sync** every 10 minutes, 30 minutes, hour or day as a safety net.
* **Checks before saving**: prices must be numbers, a sale price must be lower than the regular price, SKUs must be unique and categories must exist.
* **Activity log** of every sync and every rejected value.
* **Setup wizard** that explains each step and tells you exactly what to fix when Google refuses a request.

= How it works =

The plugin connects through a Google Cloud *service account*, a robot Google user that can only open the sheets you share with it. No Google SDK is bundled; the plugin calls the Google Sheets REST API directly.

Edits made in the sheet reach your store through an Apps Script you paste into the sheet once. Its requests are signed with a secret key, and requests older than five minutes are refused.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to **WooCommerce > Sheet Sync** and follow the wizard:
   1. Create a service account in Google Cloud, turn on the Google Sheets API and upload the JSON key.
   2. Share your Google Sheet with the service account email as an Editor and paste the sheet URL.
   3. Send your products to the sheet.
   4. Paste the Apps Script into the sheet (Extensions > Apps Script) and run `alssInstall` once to turn on instant sync.

Watch the whole setup step by step:

https://www.youtube.com/watch?v=ybi61qsvgq8

== Frequently Asked Questions ==

= Does it cost anything? =

No. The plugin is completely free. A Google Cloud project with the Sheets API is free for this kind of use.

= Can I add new products from the sheet? =

No. Add products in WooCommerce and they appear in the sheet automatically. Rows without an ID are ignored.

= Can I delete products from the sheet? =

No, deleting a row does not delete the product. Trash the product in WooCommerce and its row disappears from the sheet.

= Can I rename or move columns? =

You can move columns, but keep the header names, because the sync finds columns by name. Choose which columns to show in **WooCommerce > Sheet Sync > Settings**.

= Can I add my own columns? =

Yes. Columns with other names are ignored by the sync, and formulas in them are not overwritten by instant sync. A full sync rewrites the rows in WooCommerce order, so keep notes that belong to a product in WooCommerce rather than next to its row.

= Why does Google say the app is not verified? =

When you run the Apps Script for the first time, Google warns that the script is not verified because it is your own script and not published by Google. Click *Advanced*, then *Go to (project name)* to allow it.

= Edits in the sheet do not reach my store =

* Your site must be reachable from the internet. Google cannot reach a local site such as `example.local`.
* Security plugins or firewalls must allow requests to `/wp-json/alss/v1/`.
* Run `alssInstall` again from the Apps Script editor; it reports whether the store could be reached.
* Use **Pull changes from Google Sheets** on the dashboard to apply edits that were missed.

= Can I test instant sync on a local development site? =

Yes. Open a tunnel to the site (for example ngrok with a free static domain, or Cloudflare Tunnel) and add this line to `wp-config.php`:

`define( 'ALSS_PUBLIC_URL', 'https://your-tunnel-address' );`

The Apps Script code shown in the plugin then sends edits through the tunnel. Developers can use the `alss_public_url` filter instead.

== External services ==

This plugin connects to Google services to read and write your Google Sheet. Nothing is sent until you connect a Google account in the setup wizard.

* **Google OAuth 2.0 token endpoint** (`https://oauth2.googleapis.com/token`): exchanges a token request signed with your service account key for a temporary access token. It receives the service account email and a signed token request. It is called when you connect and about once an hour while syncing.
* **Google Sheets API** (`https://sheets.googleapis.com`): reads and writes the rows of the sheet you connect. It receives the product data in the columns you choose (for example ID, name, SKU, prices, stock, status, categories and descriptions). It is called when products change, when you run a sync and on the schedule you set.
* **Google Apps Script** (in your own Google account): the script you paste into the sheet sends edited rows to your site's REST API. That data goes from your Google account to your own website only.

Google's terms of service: https://policies.google.com/terms
Google's privacy policy: https://policies.google.com/privacy
Google APIs terms of service: https://developers.google.com/terms

== Screenshots ==

1. Setup wizard.
2. Dashboard with sync status and actions.
3. Products in Google Sheets with the Sync status column.
4. Settings: choose columns and the scheduled sync.

== Changelog ==

= 1.0.0 =
* First release.
