=== Directorist Performance Cache ===
Contributors: zamandevs
Tags: directorist, cache, performance
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional dependency-aware fallback page cache for Directorist.

== Description ==

This companion is used only when no supported page-cache provider owns the WordPress page-cache layer. It refuses foreign or ambiguous ownership and removes only files carrying its exact ownership marker.

Version 0.1.0 establishes ownership, activation, fail-open loading, and current/older Directorist compatibility contracts. The cache hit/write engine is introduced in a later reviewed phase.
