=== Extensions for Polylang ===
Contributors: neilgaiman
Tags: polylang, rest api, multilingual, translation, addon
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST API fields for languages and translations, inactive languages and per-language blocks for Polylang.

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

When Polylang Pro is active, the features it already provides are turned off.

Extensions for Polylang is not affiliated with Polylang or WP SYNTEX.

== Installation ==

1. Install and activate Polylang, and add your languages.
2. Install and activate Extensions for Polylang.
3. Turn features on and off from **Languages > Extensions**.

== Changelog ==

= 0.1.0 =
* Plugin skeleton. Not released.
