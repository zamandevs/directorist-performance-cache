# Upstream Sources and Provenance

Date pinned: 2026-08-19

This plugin does not copy a complete third-party cache engine. Its ownership, early-loader, atomic-write, and fail-open behavior is independently implemented and behavior-tested. The following GPL projects were studied as architectural references:

## WP Super Cache

- Repository: `https://github.com/Automattic/wp-super-cache`
- Reference commit: `cf0027e8ec564dafd7369032ff7b977d3512bda0`
- Version: `3.1.1`
- License: GPL-2.0-or-later
- Reviewed areas: `advanced-cache.php`, phase-1/phase-2 loading, cache path containment, deletion, locking, and preload lifecycle.

## Cache Enabler

- Repository: `https://github.com/keycdn/cache-enabler`
- Reference commit: `5f96592e223bec6c07927a331ef75110fc158c73`
- Version: `1.8.16`
- License: GPL-2.0-or-later
- Reviewed areas: `advanced-cache.php`, generated constants, engine bootstrap, disk iteration, atomic setup, and activation lifecycle.

## LiteSpeed Cache API

- Repository: `https://github.com/litespeedtech/lscache_wp`
- Reference commit: `153d8dc43915c955bc6bc81235dce3befb595611`
- Version: `7.9`
- License: GPL-3.0-or-later
- Reviewed only for public provider interoperability; no LiteSpeed server cache implementation is copied.

Modifications and Directorist-specific behavior are maintained in this repository under GPL-2.0-or-later. Exact copied material, if introduced later, must be listed here by source file and commit before merge.
