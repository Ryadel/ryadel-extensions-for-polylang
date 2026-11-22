=== Extensions for Polylang ===
Contributors: neilgaiman
Tags: polylang, rest api, multilingual, translation, addon
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A useful set of extensions for the free version of Polylang: REST API fields, inactive languages, per-language blocks.

== Description ==

Extensions for Polylang adds a few things to the free version of [Polylang](https://wordpress.org/plugins/polylang/). Each feature can be turned off from **Languages > Extensions**.

**REST API**

* `lang` and `translations` fields on the core endpoints of posts, pages, custom post types, categories, tags and custom taxonomies translated with Polylang.
* A `lang` parameter to filter collections and search results by language.
* A single request to create a post together with its translations, with categories and tags given by slug.
* The translation status of the site: how much content each language has, and what is not translated yet.

**Inactive languages**

Hide a language from visitors while you are still translating it. Editors keep working on it as usual.

**Per-language blocks**

Show a block only in some languages, so that a single header, footer or navigation works for every language in block themes.

**Translation coverage**

The **Languages > Extensions** page shows, for each content type, how many posts each language has and how many are still to translate, with a link to the list of those posts.

When Polylang Pro is active, the features it already provides are turned off.

Extensions for Polylang is not affiliated with Polylang or WP SYNTEX.

== Installation ==

1. Install and activate Polylang, and add your languages.
2. Install and activate Extensions for Polylang.
3. Turn features on and off from **Languages > Extensions**.

== Frequently Asked Questions ==

= Does it work with Polylang Pro? =

Yes. Polylang Pro already has the REST API fields and inactive languages, so these two features are turned off and Pro handles them. The translation tools of the REST API and per-language blocks stay available.

= Which REST API endpoints does it add? =

The `lang` and `translations` fields and the `lang` filter are added to the core endpoints, such as `/wp/v2/posts` and `/wp/v2/categories`. Two routes are new:

* `POST /wp-json/extensions-for-polylang/v1/posts` creates a post and its translations in one request. The body has a `translations` object keyed by language slug, with the same fields the core endpoint accepts. If a translation fails, the posts already created are deleted.
* `GET /wp-json/extensions-for-polylang/v1/status` returns the number of posts per language. With `missing=de` it lists the posts that have no German translation yet.

Each request needs the same permissions as the matching core endpoint. The status route needs a user who can edit the posts of others.

= Who can see an inactive language? =

Users who can edit posts see it on the site as usual. Visitors and subscribers get a 404 on its pages, and the language is removed from the language switcher, the hreflang links, browser language detection and the sitemaps. The default language cannot be deactivated.

= What happens to inactive languages if I deactivate the plugin? =

The language becomes visible again. Its inactive state is saved by Polylang, the same way Polylang Pro saves it, so it applies again when you reactivate the plugin.

= How do I show a block only in some languages? =

Select the block in the editor and open the **Languages** panel in the block settings. Pick the languages that should show it. With no language selected, the block is shown in every language.

= What happens to per-language blocks if I turn the feature off? =

The blocks are shown in every language. The languages you picked are kept, so they apply again when you turn the feature back on.

= Why is the plugin not doing anything? =

It needs Polylang 3.9 or later. Without Polylang, or with an older version, it shows a notice to administrators. It also stays idle until Polylang has at least one language.

== Changelog ==

= 1.0.0 =
* First release: REST API fields and tools, inactive languages, per-language blocks, settings page with translation coverage.
