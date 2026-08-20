=== Directorist Performance Cache ===
Contributors: zamandevs
Tags: directorist, cache, performance
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional dependency-aware fallback page cache for Directorist.

== Description ==

This companion is used only when no supported page-cache provider owns the WordPress page-cache layer. It refuses foreign or ambiguous ownership and removes only files carrying its exact ownership marker.

Version 0.4.0 adds provider health diagnostics, queue controls, bounded cache inventory, operational error reporting, and an atomic fail-open switch for the early cache. Every bypass, rejection, corruption, and write failure continues to fall through to WordPress.
