=== shawesome robots.txt – Per-Site Rules for Single Sites and Multisite ===
Contributors: shawesome
Tags: robots.txt, multisite, seo, network, crawler
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Network: true

Its own robots.txt for a single site or for every site of a multisite network – edited in the admin or deployed as a file.

== Description ==

WordPress generates a virtual robots.txt with the same rules for every site. shawesome robots.txt lets you replace it – on a single site, or separately for every site of a multisite network, e.g. to block a staging domain or to point each site to its own sitemap.

**Edit in the admin**

On a single site, the editor is under *Settings → robots.txt*. In a network, *Network Admin → Settings → robots.txt* lists all sites, and every site has its own editor. The content is stored in the site's database options – it survives domain changes and is removed together with the site. Leave it empty to keep WordPress' default robots.txt.

**Or deploy a file**

A plain text file in `/wp-content/shawesome-robots-txt/` overrides the database content, e.g. for Git-based deployments. The file name is derived from the site's domain, with dots replaced by hyphens:

* `www.example.com` → `www-example-com.txt`
* `shop.example.com` → `shop-example-com.txt`

As an unambiguous alternative, the domain with dots is accepted as well (`www.example.com.txt`); the hyphenated name takes precedence. While a file exists, the editor shows its content read-only and offers to copy it into the database.

**Which sites get their own robots.txt**

Crawlers only request robots.txt from the root of a host. A site gets its own robots.txt if its address has no path:

* a single site at the root of its domain
* every site of a network with subdomains or domains
* in a network with subdirectories: the main site and sites with their own domain

Sites with a path (`example.com/blog/`) are served by the robots.txt of their host; the overview says so.

**Works with Yoast SEO and All in One SEO**

Yoast SEO and All in One SEO change the robots.txt output. In the settings you choose how your content is combined with them:

* **Base** (default) – the content replaces the WordPress default; Yoast SEO appends its sitemap, All in One SEO rebuilds the rules (comments and Sitemap lines are dropped). Duplicate Sitemap lines are removed.
* **Exact** – the content is delivered as is, after all SEO plugins have run.

**Physical robots.txt**

If a `robots.txt` file exists in the web root, the web server delivers it and WordPress is never asked. The settings page shows its content and – if WordPress may write to the web root – offers to rename it to `_old-robots.txt`, optionally after copying its content into the editor. The file is never deleted.

**Search engine visibility**

Sites set to "Discourage search engines from indexing this site" keep the WordPress default instead of the custom robots.txt, so a staging site does not deliver the rules of production – even if the database was copied from there. This can be switched off.

**Notes**

* A UTF-8 BOM is stripped and line endings are normalized.
* In a network, the plugin is network-activated and the settings apply to all sites.

**For developers**

* Constant `SHAWESOME_ROBOTS_TXT_DIR` or filter `shawesome_robots_txt_dir` – change the directory for override files.
* Filter `shawesome_robots_txt_candidate_files` – change the candidate file paths for a domain.
* Filter `shawesome_robots_txt_priority` – change the priority of the `robots_txt` filter.
* Content is stored in the site option `shawesome_robots_txt_content` (not autoloaded).
* All callbacks are named functions and can be removed with `remove_filter()`.

== Installation ==

1. Upload the folder `shawesome-robots-txt` to `/wp-content/plugins/` or install the plugin via *Plugins → Add New*.
2. Activate the plugin – in a network, network-activate it.
3. Edit the robots.txt under *Settings → robots.txt* (single site) or *Network Admin → Settings → robots.txt* (network) – or place a text file per domain in `/wp-content/shawesome-robots-txt/`, e.g. `www-example-com.txt`.
4. Check the result with the "Open live robots.txt" link or at `https://www.example.com/robots.txt`.

== Frequently Asked Questions ==

= My robots.txt is not delivered. =

Check on the settings page that there is no physical `robots.txt` in the web root and that the site is not set to "Discourage search engines". In a network, the overview shows the source for every site. For files, check that they are readable by the web server.

= I changed the content in the editor, but the old one is still delivered. =

A file in `/wp-content/shawesome-robots-txt/` overrides the database content. Delete the file – or copy it into the database first with the button in the editor.

= Yoast SEO or All in One SEO changes my robots.txt. =

Select the mode "Exact" in the settings. The content is then delivered after all SEO plugins have run.

= Does it work if WordPress is installed in a subdirectory? =

If the address of the site contains a path (`example.com/blog/`), crawlers request the robots.txt from `example.com/robots.txt`, which WordPress does not deliver. The plugin then does nothing and says so. If only the WordPress files are in a subdirectory and the address is the root of the domain, it works.

= What happens on uninstall? =

The settings and the content stored in the database are deleted. Files in `/wp-content/shawesome-robots-txt/` and a renamed `_old-robots.txt` are kept.

== Changelog ==

= 1.0.0 =
* Initial release.
