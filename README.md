# SoftIcon Sheet Sync for WooCommerce

Two-way sync between WooCommerce products and a Google Sheet. Edit stock, prices, SKUs, names and descriptions in the sheet and your store updates instantly. Change something in WooCommerce and the sheet updates too.

## Features

- Two-way sync between WooCommerce and Google Sheets
- Instant updates from the sheet through a small Apps Script, with a *Sync status* column per row
- Automatic updates to the sheet a few seconds after a product is saved, trashed, deleted or its stock changes
- Simple and variable products, one row per variation
- Choose the columns: name, SKU, prices, stock, status, categories, descriptions, total sales, product URL
- Scheduled full sync as a safety net, activity log and a setup wizard

## Requirements

- WordPress 6.5+
- PHP 7.4+ with the OpenSSL extension
- WooCommerce 8.0+
- A Google Cloud service account with the Google Sheets API enabled

## Installation

1. Copy this folder to `wp-content/plugins/softicon-sheet-sync-for-woocommerce` and activate the plugin.
2. Go to **WooCommerce > Sheet Sync** and follow the setup wizard.

No build step and no Composer dependencies: the plugin calls the Google Sheets REST API directly.

## External services

The plugin talks to Google's OAuth token endpoint and the Google Sheets API, only after you connect a service account. See the *External services* section of [readme.txt](readme.txt) for what is sent and when.

## License

GPLv3 or later. See <https://www.gnu.org/licenses/gpl-3.0.html>.
