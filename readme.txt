=== Directorist Performance Cache ===
Contributors: zamandevs
Tags: directorist, cache, performance
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional dependency-aware fallback page cache for Directorist.

== Description ==

This companion is used only when no supported page-cache provider owns the WordPress page-cache layer. It refuses foreign or ambiguous ownership and removes only files carrying its exact ownership marker.

Version 0.3.0 adds bounded asynchronous warming, immediate loopback successors, WP-Cron recovery, retry and circuit-breaker controls, bounded expired-entry cleanup, and WP-CLI operations. Every bypass, rejection, corruption, and write failure continues to fall through to WordPress.
