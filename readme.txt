=== WebiSearch – Search Analytics, Synonyms & Redirects ===
Contributors: webiloper
Tags: search analytics, search log, woocommerce search, synonyms, search redirect
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See exactly what visitors search for, which searches fail, what they click and what they buy — then fix failed searches with synonyms and redirects.

== Description ==

WebiSearch works with any WordPress site and any theme or plugin whose search runs through WP_Query: default WordPress search, WooCommerce, WoodMart, Flatsome, Astra, Elementor, FiboSearch (WP_Query mode), Relevanssi and more. Custom engines can log through `webisearch_log_search()`.

**Analytics**

* Dashboard with period selector (7/30/90/365 days) and comparison with the previous period.
* KPIs: searches, unique searchers, no-result rate, click-through rate, average click position, live-search share, revenue from search (WooCommerce).
* Automatic insights: failing searches, probable typos ("did you mean"), searches with results but no clicks, trending terms.
* Charts: daily trend (with failed searches), top terms, hour of day, sources.
* Failed-search workflow: one-click "Add rule" / "Use suggestion", "Mark as resolved" (comes back only if it fails again).
* Logs page with two views (every search / grouped by term), date, result, source and visitor filters, bulk delete.
* CSV export (UTF-8 with BOM for Excel, respects filters, streamed in batches, safe against formula injection).
* Click tracking (results page and live-search dropdowns) and WooCommerce add-to-cart / paid-order attribution.

**Better search**

* Smart rescue of zero-result searches: clean-up, spelling correction from your catalog, fewer words, "Showing results for …".

* Synonyms and redirects with exact / starts-with / contains-the-word matching, several spellings per rule, hit counter, enable/disable, edit, CSV import/export. Relative and Persian (percent-encoded) URLs and a {query} placeholder are supported.
* Persian/Arabic normalization: ي/ك → ی/ک, tatweel, diacritics, invisible characters; Persian digits and half-spaces are grouped together in reports.
* As-you-type aggregation: the keystrokes of a live search are stored as one search with the final term.
* Search result pages are excluded from page caches (LiteSpeed, WP Rocket, W3TC, WP Super Cache) so every search is logged.

**Privacy & housekeeping**

* IP addresses are never stored (anonymous one-way visitor ID). Optional anonymous mode for logged-in users.
* Exclude roles, IPs/CIDR ranges, terms (wildcards), bots and spam.
* Automatic cleanup, WordPress privacy exporter/eraser and suggested privacy-policy text.
* Weekly HTML e-mail report with a test button; multiple recipients.
* WP dashboard widget, `[webisearch_popular]` shortcode, WP-CLI (`wp webisearch stats|export|cleanup|report`), multisite and WooCommerce HPOS compatible.

== Installation ==

1. Upload the `WebiSearch` folder to `/wp-content/plugins/` (or upload the zip in Plugins → Add New).
2. Activate. Existing logs and rules from version 2 are migrated automatically.
3. Open "Search Logger" in the admin menu.

== Frequently Asked Questions ==

= Does it work with my theme's AJAX search? =
Yes, if the live search uses WP_Query (most do, including WoodMart). Version 2 never logged AJAX searches because `is_admin()` is true inside admin-ajax.php; this is fixed.

= Clicks in my live search dropdown are not counted =
Add the CSS class of the dropdown under Settings → Clicks & conversions → Extra live-search CSS selectors.

= Will it slow down my site? =
No. One small insert per search, a cached rule lookup and a 1.5 KB script without dependencies.

== Changelog ==

= 3.3.4 =
* Fix: live-search keystrokes that change the middle or the start of what was typed ("فلنچ سایز 355" → "فلنچ پلی سایز 355", "اره عمود" → "ره عمود") were logged as separate failed searches; they are now one search, like typing at the end.
* Fix: the spelling fix no longer cuts real words into 2-letter pieces ("دینام" → "دی نام", "انسانی" → "ان سانی"); only real short words such as "آب" may be split off (filter asl_split_short_words).
* Fix: words joined by half-spaces ("پلی‌اتیلن", "شیرخودکار‌یک‌اینچ") were dropped as unknown when a search was shortened.
* Fix: 7-letter words are corrected by one letter only ("استاتور" was turned into "استارتر").
* New: 4-letter words with two letters swapped ("upcv" → "upvc") or a look-alike letter ("فلنچ" → "فلنج") are corrected.
* New: a Persian search typed with the keyboard on English ("uhdr" → "عایق") is understood when every word is in the catalog.

= 3.3.3 =
* Tweak: the currency on the revenue tile is shown smaller under the amount instead of being cut off.

= 3.3.2 =
* Fix: "Revenue from search" included orders that were later cancelled, failed or refunded (common with cash on delivery). Revenue is now taken back when an order leaves a counted status, partial refunds are subtracted, and existing data is re-checked once after the update.
* New: setting "Count revenue when the order is": Completed only (default) or Processing + completed.
* Fix: the revenue tile no longer overflows; large amounts are shown as "۲٫۷ میلیارد تومان" with the exact amount on hover.

= 3.3.1 =
* Fix: some server firewalls (LiteSpeed/ModSecurity) blocked the request form with a 403 because it posted the page URL. The URL is now rebuilt on the server.

= 3.3.0 =
* New: product requests. When a search finds nothing, visitors see a "not available – leave your number and our experts will call you" box (replaces WooCommerce's "No products were found"). Also offered as a one-line box above close matches.
* New: WebiSearch → Product requests: statuses (new / contacted / done / canceled), notes, filter by product or phone, "most requested products", CSV export, and an e-mail for each new request.
* New: request count shown next to each term in the failed-searches review; "found on the site now" hint when a requested product becomes available.
* New: [webisearch_request_form] shortcode and asl_request_created action (for SMS / CRM).

= 3.2.1 =
* FIX: A missing madda is added before searching when the catalog clearly uses آ ("تانکر اب" → "تانکر آب").
* FIX: 1–2 letter words that are not catalog words ("اب") match only as whole words instead of inside "قابل", "ابعاد"...
* NEW: "Search in" setting: title + descriptions (default), title + short description, or title only.

= 3.2.0 =
* NEW: Spelling-insensitive matching: a search also matches titles typed with Arabic ي/ك, Persian or Latin digits, half-space or space (only WordPress' own search clauses are widened).
* NEW: Failed searches review: every search that ever found nothing is re-tested nightly (or on demand) against current products and grouped into "not in your catalog", "close matches", "fixed automatically" and "found now". Fixed ones are resolved automatically; decisions (we don't have it / ignore) and CSV export per group.
* IMPROVED: Smarter rescue: transposed letters ("اتلین"), 4-letter typos, no nonsense word splits, the product-type word must exist in the catalog before shortening, and one very common word on its own is never used (e.g. "کلید" with 1,700 results).

= 3.1.0 =
* NEW: Smart rescue for searches with no results (only those): splits numbers glued to words, removes text pasted from product pages, corrects words against a vocabulary built from your own titles (missing آ, typos, words written together), then retries with fewer words. Shows "Showing results for …", works in live search and on paginated results, and is cached.
* NEW: "Rescued automatically" dashboard tile, log filter and CSV column.
* NEW: "Test a search" tool on the rules page shows every step your site takes for a term.
* NEW: Help block on empty WooCommerce result pages (popular searches + your own contact text) and the [webisearch_no_results] shortcode.

= 3.0.1 =
* FIX: On sites with a Persian date plugin (WP-Parsidate, Persian WooCommerce...), wp_date() returns Jalali years, so the dashboard looked for searches in the year 1405 and showed zeros, and new searches were stored with Jalali dates. All machine dates now use DateTime directly; rows already written with Jalali dates are repaired automatically.

= 3.0.0 =
* FIX: "Export all as CSV" did nothing (the export function was an empty stub). Rewritten with BOM, filters and batching.
* FIX: AJAX/live searches were never logged (is_admin() is true in admin-ajax.php).
* FIX: Redirect URLs with Persian slugs were broken by sanitize_text_field() (it strips %xx).
* FIX: Live search results were logged as "0 results" when the query used no_found_rows.
* FIX: Every keystroke, page reload and results page 2, 3… was logged as a new search.
* FIX: Request parameters and settings values were printed without escaping (hardened against XSS).
* FIX: ORDER BY was built from request data without a whitelist.
* FIX: Date ranges and cleanup mixed UTC and site time (3.5 hours off in Iran).
* FIX: Logs list showed a checkbox column without checkboxes; rule-list sorting did nothing; duplicate rules failed silently; no admin notices.
* FIX: Cache invalidation deleted a transient that never existed; uninstall left data behind; translations were not loaded explicitly.
* FIX: PHP 8.1+ deprecation when settings were saved for the first time; log ID column could overflow (mediumint).
* NEW: Everything listed in the description above.

= 2.0.0 =
* Synonyms & redirects, automatic cleanup, weekly e-mail, dashboard widget.

= 1.0.0 =
* Initial release.
