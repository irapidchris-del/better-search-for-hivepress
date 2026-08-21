=== Extended Search for HivePress ===
Contributors: chrisb
Tags: hivepress, search, attributes, vendors, listings
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extends the HivePress keyword search to also match your custom attributes, vendor profile text, pricing tiers, tags and category descriptions.

== Description ==

Sellers write far more than a title and a description. They fill in your custom fields, they write a profile, they name their pricing options. A visitor searching for one of those words gets nothing back, and the listing that was the perfect match never appears.

HivePress can include a custom attribute in the keyword search, but only if someone remembers to tick "Indexable" on that attribute, and only for listings saved after they ticked it. Anything on the vendor's own profile, and anything inside a pricing tier, cannot be reached that way at all.

This plugin widens the search without changing anything else about it, and without asking you to configure a thing.

**What it adds**

* **Every custom attribute a seller can type into.** The plugin reads your site's own attribute list, so whatever you have called your fields, they are covered. Nothing is named in code and there is no checkbox to remember.
* **The seller's profile.** The built-in vendor description and every text field on the vendor form, including the one you may have renamed to "About Me". Searching a word from a seller's profile now finds their listings.
* **Pricing tier names and descriptions.** Tier text is stored inside the listing in a packed format the database cannot search, so the plugin keeps a plain-text copy alongside each listing and searches that instead.
* **Tags, categories and any drop-down attribute.** Term names and descriptions are read live from the taxonomies, so renaming a term takes effect immediately with nothing to rebuild.
* **Sub-categories.** A keyword matching a parent category also finds listings filed under any of its children, however deep the tree goes.

**What it deliberately leaves alone**

* Your existing search behaviour. Titles, excerpts and content still match exactly as before.
* Negative search. Searching `-word` still excludes, because only the inclusion half of each keyword group is extended.
* Result counts. A listing sitting in three matching categories is still returned once, never three times.
* The admin listings table. Only front-end searches are widened.
* Fields that hold no keywords. Prices, dates, times, coordinates, ratings and file uploads are skipped, as are contact details such as phone numbers and email addresses. Regions are skipped too, because a term like "England" would match every listing on the site.

There is nothing to configure. The plugin indexes your existing listings and sellers in batches of 100 as you move around wp-admin, then keeps itself up to date from then on.

== Installation ==

1. Download the latest release zip from the GitHub releases page.
2. In wp-admin go to Plugins, Add New, Upload Plugin, and choose the zip.
3. Activate it. There are no settings.

Updates arrive on the Plugins screen automatically, the same as any other plugin, and there is a "Check for updates" link on the plugin's row if you would rather not wait. Next to it is a "Rebuild search index" link, for the rare occasion you want the indexing to start again from scratch.

== Frequently Asked Questions ==

= Which of my fields does it search? =

Every attribute a seller can edit whose field type is Text or Textarea, on both the listing form and the vendor form, plus every drop-down, radio and checkbox attribute, which HivePress stores as terms. You do not list them anywhere; the plugin asks HivePress what your site has.

= Do I still need to tick "Indexable" on my attributes? =

No. That checkbox is HivePress's own way of adding an attribute to the keyword search, and it still works, but it only takes effect for listings saved afterwards. This plugin covers the same fields whether or not the box is ticked, and fills in your existing listings for you.

= Why does a seller's profile text match all of their listings? =

Because it describes all of them. A seller who writes "twenty years of bridal work" in their profile is telling you something true of everything they offer, so a search for "bridal" returns their listings.

= Do I need HivePress Marketplace? =

Only for the pricing tier part. Everything else works with HivePress on its own. Tier matching also needs "Allow sellers to set pricing tiers" switched on in the HivePress settings, and does nothing until it is.

= Will it slow my search down? =

Each keyword adds three conditions to the search: two against a plain-text copy stored next to the listing and the seller, and one against a list of matching terms worked out beforehand. That list costs two small term lookups per keyword. Nothing is unpacked at query time and nothing grows with the number of attributes you define.

= My search results have not changed. =

Give the indexing a moment: it processes 100 records per admin page load, so a large site takes a few page loads to finish. Editing and saving a listing or a profile indexes it immediately.

= Can I search phone numbers, emails or addresses too? =

Not by default, because they are contact details rather than search terms. A developer can add them with the `hivepress/v1/extended_search/index_field_types` filter. After changing that list, use the "Rebuild search index" link on the plugin's row on the Plugins screen so the change reaches listings that already exist.

= Can I translate it? =

Yes. The plugin ships a .pot file, so Loco Translate can pair with it. Save translations to Loco's "System" location so they survive plugin updates.

== Changelog ==

= 1.5.3 =
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.

= 1.5.2 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.5.1 =
* Added: a "Rebuild search index" link on the plugin's row on the Plugins screen. Useful after changing which field types are indexed, or any other time you want the plugin to work through everything again from scratch. Searching keeps working while it runs.

= 1.5.0 =
* Added: every custom attribute a seller can type into is now searchable, on both the listing form and the vendor form. The plugin reads your site's own attribute list, so it fits any site without naming a single field in code and without any setting to switch on.
* Added: the seller's profile is now searchable from the listing search. That covers the built-in vendor description and every text field on the vendor form, so a word someone wrote in their "About Me" finds their listings.
* Added: tags and any drop-down, radio or checkbox attribute are now matched by term name and description, alongside categories.
* Changed: the stored copy of each listing now holds all of its searchable text rather than pricing tiers alone, and sellers have a copy of their own. The plugin rebuilds both for your existing records automatically on the next few admin page loads.
* Changed: terms are now looked up per keyword instead of loading every term into memory, so a site with thousands of tags is no longer paying for all of them on every search.
* Fixed: pricing tier text was read through a check that can never pass on a HivePress model, so it always fell back to reading the raw value. The fallback was correct, which is why nothing looked wrong, but the check itself was dead code.

= 1.4.1 =
* Changed: the plugin is now called Extended Search for HivePress. The old name read as a judgement on the HivePress search rather than a description of what this adds to it. Nothing else changed: same settings, same behaviour, same folder, and updates continue to arrive as normal.

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

= 1.5.1 =
Adds a "Rebuild search index" link to the plugin's row on the Plugins screen. Nothing about how the search behaves has changed.

= 1.5.0 =
Your custom attributes and your sellers' profile text are now searchable, with nothing to switch on. Existing listings and profiles are indexed automatically over the next few admin page loads.

= 1.4.1 =
A rename only. The plugin is now called Extended Search for HivePress. Nothing about how the search behaves has changed.

= 1.4.0 =
Adds automatic updates, so this is the last time you will need to install this plugin by hand. Nothing about how the search behaves has changed.
