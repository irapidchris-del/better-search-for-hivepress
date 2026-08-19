=== Better Search for HivePress ===
Contributors: chrisb
Tags: hivepress, search, categories, pricing tiers, listings
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extends the HivePress keyword search to also match pricing tier text and listing category names and descriptions, including sub-categories.

== Description ==

The HivePress keyword search looks at a listing's title, excerpt and content. Anything a seller wrote elsewhere is invisible to it, which is why a search for a service named only in a pricing tier, or for a word that only appears in a category description, comes back empty.

This plugin widens that search without changing anything else about it.

**What it adds**

* **Pricing tier names and descriptions.** Tier text is stored inside the listing in a packed format the database cannot search, so the plugin keeps a plain-text copy alongside each listing and searches that instead. The copy is rewritten whenever the listing is saved, so it is never out of date.
* **Listing category names and descriptions.** Read live from the taxonomy, so renaming a category takes effect immediately with nothing to rebuild.
* **Sub-categories.** A keyword matching a parent category also finds listings filed under any of its children, however deep the tree goes.

**What it deliberately leaves alone**

* Your existing search behaviour. Titles, excerpts and content still match exactly as before.
* Negative search. Searching `-word` still excludes, because only the inclusion half of each keyword group is extended.
* Result counts. A listing sitting in three matching categories is still returned once, never three times.
* The admin listings table. Only front-end searches are widened.

There is nothing to configure. On activation the plugin indexes your existing listings in batches of 100 as you move around wp-admin, then keeps itself up to date from then on.

== Installation ==

1. Download the latest release zip from the GitHub releases page.
2. In wp-admin go to Plugins, Add New, Upload Plugin, and choose the zip.
3. Activate it. There are no settings.

Updates arrive on the Plugins screen automatically, the same as any other plugin, and there is a "Check for updates" link on the plugin's row if you would rather not wait.

== Frequently Asked Questions ==

= Do I need HivePress Marketplace? =

Only for the pricing tier half. Category and sub-category matching works with HivePress on its own. Tier matching also needs "Allow sellers to set pricing tiers" switched on in the HivePress settings, and does nothing until it is.

= Will it slow my search down? =

The category tree is read once per request and cached in memory, and tier text is matched against a plain-text column rather than unpacked at query time, so the added work is a single extra condition per keyword.

= My search results have not changed. =

Give the backfill a moment: it processes 100 listings per admin page load, so a large site takes a few page loads to finish. Editing and saving a listing indexes it immediately.

= Can I translate it? =

Yes. The plugin ships a .pot file, so Loco Translate can pair with it. Save translations to Loco's "System" location so they survive plugin updates.

== Changelog ==

= 1.4.0 =
* Added: automatic updates. New versions now appear on the WordPress Plugins screen like any other plugin, with a "Check for updates" link on the plugin row and the release notes in the "View version details" popup. Previously updating meant downloading a zip by hand.
* Added: the plugin is now translatable, with a .pot file and the text domain headers Loco Translate needs.
* Added: a notice if HivePress is not active, since without it the plugin does nothing and gave no clue why.
* Added: a "Donate" link on the Plugins screen and in the plugin details popup, for anyone who would like to support the work. It appears nowhere else and gates nothing.
* Added: a clean uninstall that removes the searchable copies and the backfill markers when the plugin is deleted. Nothing you configured is lost, because there is nothing to configure - it all rebuilds if you reinstall.
* Changed: the plugin now declares its WordPress and PHP requirements, and HivePress as a required plugin, so WordPress can warn you before activation rather than after.

= 1.3.0 =
* Added: listing category names and descriptions to the keyword search, with parent categories cascading to their sub-categories.
* Fixed: a listing in more than one matching category is returned once rather than duplicated.

= 1 =
* First release: pricing tier names and descriptions added to the keyword search.

== Upgrade Notice ==

= 1.4.0 =
Adds automatic updates, so this is the last time you will need to install this plugin by hand. Nothing about how the search behaves has changed.
