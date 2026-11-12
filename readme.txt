=== Comprestack ===
Contributors: michaelgenesisii
Tags: images, compression, webp, avif, optimize, performance, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress and convert Media Library and WooCommerce product images with the Comprestack API.

== Description ==

Comprestack optimizes WordPress media using the Comprestack image optimization API.

= Features =

* Auto-optimize on media upload
* Convert to WebP or AVIF (or same-format compress)
* Bulk optimize in the Media Library
* Quality presets and max-width resize
* Optional backups of originals
* Test connection and view remaining quota
* WooCommerce: product, gallery, and variation images
* Product edit box + Products bulk action

= External service =

This plugin sends images to `https://api.comprestack.com` for processing. You need a Comprestack account and API key.

* Dashboard / API keys: https://www.comprestack.com/dashboard?section=api
* Documentation: https://www.comprestack.com/docs
* Privacy: https://www.comprestack.com/privacy

API keys stay on your WordPress server (settings or environment). Do not put them in theme JavaScript.

== Installation ==

1. Upload the `comprestack` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload
2. Activate **Comprestack**
3. Open **Settings → Comprestack** and add your API key
4. Click **Test connection**
5. (Optional) Enable WooCommerce product image optimization if WooCommerce is installed

== Frequently Asked Questions ==

= Does this work without an API key? =

No. Create a free or paid key at https://www.comprestack.com/dashboard?section=api

= Does it work with WooCommerce? =

Yes. When enabled, saving a product optimizes its featured, gallery, and variation images. You can also use the product bulk action or the Comprestack box on the product edit screen.

= Are API keys safe? =

Keys are stored in WordPress options (or `COMPRESTACK_API_KEY` in the environment) and used only in server-side requests.

= What happens on uninstall? =

Plugin settings and Comprestack attachment meta are removed. Backup files under `uploads/comprestack-backups/` are kept so you can restore originals if needed.

== Screenshots ==

1. Settings — API key, formats, backups, WooCommerce
2. Test connection and quota
3. Media Library bulk optimize
4. Product edit — optimize product images

== Changelog ==

= 0.1.4 =
* Canonical plugin headers (Description, Version, GPLv2 or later)

= 0.1.3 =
* Use WordPress HTTP API instead of cURL (Plugin Check)
* Use wp_delete_file / WP_Filesystem for file moves
* Tested up to WordPress 7.1; remove hidden package files from zip

= 0.1.2 =
* WooCommerce product, gallery, and variation image hooks
* Product metabox + Products bulk action
* wordpress.org-ready readme and license

= 0.1.1 =
* Test connection + usage/quota on settings
* Optional original backups
* Skip already-optimized in bulk; re-optimize on attachment screen
* Admin CSS polish + uninstall cleanup

= 0.1.0 =
* Initial release: settings, upload hook, bulk + single optimize

== Upgrade Notice ==

= 0.1.2 =
Adds WooCommerce product image optimization and listing-ready documentation.
