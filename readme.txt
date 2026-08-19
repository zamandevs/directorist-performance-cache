=== Directorist Performance Cache ===
Contributors: zamandevs
Tags: directorist, cache, performance
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional dependency-aware fallback page cache for Directorist.

== Description ==

This companion is used only when no supported page-cache provider owns the WordPress page-cache layer. It refuses foreign or ambiguous ownership and removes only files carrying its exact ownership marker.

Version 0.2.0 adds conservative anonymous request guards, core-approved response capture, integrity-checked atomic storage, dependency generations, bounded stale handling, and early GET/HEAD serving. Every bypass, rejection, corruption, and write failure falls through to WordPress.
