# Comprestack for WordPress

Compress and convert Media Library and WooCommerce product images with the [Comprestack](https://www.comprestack.com) API.

## Install

1. Copy the `comprestack` folder into `wp-content/plugins/` (or upload `comprestack.zip`)
2. Activate **Comprestack**
3. **Settings → Comprestack** → API key → **Test connection**

Or set `COMPRESTACK_API_KEY` in the server environment.

**Requirements:** WordPress 6.0+, PHP 8.1+ (`curl`, `json`). WooCommerce 8+ optional.

## Features

- Auto-optimize on upload
- WebP / AVIF convert (or same-format compress)
- Media Library bulk + attachment re-optimize
- Original backups under `uploads/comprestack-backups/`
- Test connection + quota
- **WooCommerce:** optimize on product save, product metabox, Products bulk action
- Clean uninstall (keeps backup files)

## wordpress.org

1. Build zip: from `sdk/wordpress` run `pwsh ./build-zip.ps1`
2. Create a [WordPress.org plugin account](https://wordpress.org/support/users/register/) if needed
3. Submit: https://wordpress.org/plugins/developers/add/
4. After approval, upload icons/banners from `sdk/wordpress/.wordpress-org/`

Slug suggestion: `comprestack`

## License

MIT © Comprestack
