=== OG Watermark ===
Contributors: orchardgrovemedia
Tags: watermark, images, media, branding, copyright
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pixel-bakes watermarks into your image files — text or logo — with safe, hash-verified pristine backups you can restore at any time.

== Description ==

OG Watermark permanently stamps a text or logo watermark **into your image
files at the pixel level**, so the mark travels with the image wherever it is
hot-linked, downloaded, or scraped. There is no CSS or JavaScript overlay and
no front-end runtime cost — the watermark is baked into the saved files,
including the high-resolution original, to genuinely deter image theft.

Before any file is touched, a pristine copy of the true original is written to
a hardened backup directory and verified with a sha1 hash. That backup becomes
the single, immutable source of truth: every apply, re-apply, or one-click undo
rebuilds from it, so watermarks never stack and a clean restore is always
available. Built for PHP 8.1+ with no build step.

**Features**

* **Text or logo watermarks.** Upload a transparent PNG logo, or render text
  from a template with tokens (`{site}`, `{year}`, `{copy}`, `{url}`) using the
  bundled open-licensed font, with stroke or shadow treatments.
* **9-point placement** with adjustable scale, margin, and opacity, previewed
  live on the settings screen before you commit.
* **Stamps every served size.** The mark is applied to the full/`-scaled`
  file, every enabled intermediate size, and the high-resolution original, so
  there is no clean copy left to scrape.
* **Hash-verified pristine backups.** The true original is copied and sha1-
  verified before the first stamp; all later work sources from that backup, so
  watermarks are never re-applied on top of themselves.
* **One-click restore.** Remove the watermark from any image and put the
  pristine original back at any time.
* **Per-image opt-in and bulk tools.** Flag images from the media library
  (modal, list column, bulk actions) or run a resumable background bulk job
  across your whole library with live progress.
* **Imagick or GD.** Prefers Imagick when present, falls back to a first-class
  GD path — works on the common host where only GD is installed.
* **Safe by design.** Atomic temp-then-rename writes, per-image locking, a
  daily backup-integrity sweep, and a leftover-temp reaper keep your media and
  backups consistent even if a job is interrupted.
* **Multisite-aware** and fully translatable.

== Installation ==

1. Upload the `og-watermark` folder to `/wp-content/plugins/`, or install the
   ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** screen. On multisite, activate
   per site; each site sets up its own backups and maintenance schedule the
   first time it loads.
3. Visit **OG Watermark** in the admin menu to choose a logo or text mark,
   placement, scale, and opacity, using the live preview to fine-tune.
4. Flag individual images from the media library, or open **OG Watermark →
   Bulk Tools** to watermark your existing library in the background.

**Requirements:** PHP 8.1 or newer with either the Imagick or GD extension.
Text watermarks additionally require FreeType (bundled with most GD builds).
If neither image engine is available the plugin stays installed but shows a
notice and disables watermarking until your host enables one.

== Frequently Asked Questions ==

= Are my original images safe? =

Yes. Before the first watermark is ever applied to an image, OG Watermark
copies the true original to a hardened backup directory and records a sha1 hash
of it. That backup is treated as immutable and is the only clean master the
plugin trusts — every re-apply and every one-click undo rebuilds from it. The
on-disk original is stamped too (so there is no clean copy to scrape), but it
is never read back as a clean source once the verified backup exists. Backups
are **never** deleted on uninstall unless you explicitly opt in to that
destructive choice.

= What exactly happens to the high-resolution original? =

It gets watermarked, on purpose. The whole point is that there is no
unwatermarked file anyone can fetch — including the full-size or `-scaled`
original. This is only safe because the separate, hash-verified backup is kept
as the clean master. If you ever remove the watermark, the pristine original is
restored from that backup.

= Does this slow down my site? =

No. The watermark is baked into the image files once, when you apply it. There
is no overlay, filter, or extra request at view time, so visitors load normal
images with zero added runtime cost.

= Do I need Imagick, or does GD work? =

Either works. OG Watermark prefers Imagick when it is installed because it
generally produces higher-quality resizes and handles more formats (including
CMYK JPEGs), but the GD path is first-class and fully supported — many hosts
ship only GD. The plugin probes each engine per image format and uses whichever
can handle the file. Text watermarks need FreeType support; if it is missing,
text mode is disabled and you are told to use a logo PNG instead. If neither
Imagick nor GD is present, watermarking is disabled and a notice explains how
to ask your host to enable an engine.

= Where are the backups stored, and are they exposed on the web? =

By default the pristine backups live **outside** the web-served uploads tree
(under `wp-content`, in a folder named with an unguessable random token), so
they are not reachable by URL. If that location is not writable, the plugin
falls back to a token-named folder inside `uploads` and hardens it with
`index.php`, an Apache `.htaccess` deny rule, and an IIS `web.config`.

On Apache and IIS those rules block direct access automatically. **On nginx
(or any server that ignores `.htaccess`)** those per-directory files do nothing,
so if the backup folder ended up inside `uploads` you should add a server-level
deny rule. The plugin detects this and shows the exact folder name in an admin
notice; a typical nginx rule is:

`location ~* /og-watermark-originals.*/ { deny all; return 403; }`

Better still, keep the default `wp-content` location (above the docroot on many
setups) so the backups are never web-served in the first place.

= Will this double my media storage? =

Roughly, yes — for every image you watermark, a full pristine copy is kept as
the backup. That is the cost of being able to cleanly restore originals.
Before a bulk run, the plugin checks free disk space and refuses to start if
there is not enough room for the backups it would create, so a run cannot fill
your disk midway. If storage is tight, watermark in batches and watch the
**Backup storage** card on the settings screen, which shows the total bytes and
file count your backups are using.

= Can I undo a watermark? =

Yes — use **Remove** on any flagged image (or the bulk action) and the pristine
original is restored from the verified backup. The only situation where this is
not possible is if the backup file was deleted or corrupted out-of-band (for
example by another tool); in that case the plugin freezes that image, shows a
clear notice, and your options are to re-upload a clean original or accept the
permanent watermark. It will never silently re-create a "clean" master from an
already-watermarked file.

= Does it work on multisite? =

Yes. Each site in the network manages its own settings, backups, and daily
maintenance schedule, set up automatically the first time the site loads.

== Upgrade Notice ==

= 1.1.1 =
Fixes a "lock lost" error (and partial, aborted watermarking) on hosts with a persistent object cache, where clicking Watermark showed an error even though some thumbnails were created. Recommended for those hosts.

= 1.1.0 =
Fixes the live settings preview (it now actually shows the rendered sample), automatically clears the cached copy of an image after it is watermarked or restored so caching hosts and CDNs stop serving the stale pre-watermark version, and adds a "Re-detect" button to the Engine status card so a newly enabled Imagick/GD extension is picked up without bumping the plugin. Recommended for everyone.

= 1.0.1 =
Compatibility fix for hosts that disable disk_free_space() or fsync() (common on managed hosts) — these previously caused a fatal "The request failed" when watermarking. Strongly recommended. Settings and backups are preserved.

= 1.0.0 =
First stable release. Adds daily backup-integrity checks and leftover-temp
cleanup, a clear notice when no image engine is available, full translation
support, and lifecycle hardening (clean deactivation/uninstall). Safe upgrade
from 0.7.0; your settings and backups are preserved.

== Changelog ==

= 1.1.1 =
* Fix: on hosts with a persistent object cache that evicts entries early, the per-image lock heartbeat could falsely report "lock lost" mid-run — aborting after the first size and showing an error in the UI even though some thumbnails were already watermarked. The heartbeat now self-heals on eviction (re-asserts ownership when no other worker holds the lock) and fails only when a different live owner has genuinely taken over, so a watermark completes every size cleanly. The options-table lock backend (hosts without a persistent object cache) is unchanged.

= 1.1.0 =
* Fix: the live settings preview never displayed because the in-memory `data:` image URL was being stripped by URL sanitization. The preview now renders correctly; the plugin-generated data URL is validated against a strict image-data pattern instead of being run through the URL escaper.
* New: automatic cache purge after a watermark is applied or removed. Because the file path is unchanged, caching plugins and CDNs (SiteGround Optimizer, WP Rocket, LiteSpeed, W3 Total Cache, Cloudflare) otherwise keep serving the stale pre-watermark bytes. The plugin now flushes just that attachment's cached URLs after a successful (re)stamp or restore — targeted, never a site-wide purge — with an `ogwm_auto_purge` filter to disable it and `ogwm_purged_attachment` / `ogwm_purge_urls` actions to wire custom purging.
* New: a "Re-detect" button on the Engine status card, plus an automatic re-probe when the settings screen loads, so enabling or disabling an image extension (e.g. turning on Imagick) is picked up immediately without a plugin version bump.

= 1.0.1 =
* Fix: fatal "Call to undefined function" when watermarking on hosts that disable `disk_free_space()` or `fsync()` via `disable_functions` (common on managed hosts such as WordKeeper) — both are now guarded with `function_exists()` and degrade gracefully. The mandatory full-sha1 backup verification still guarantees a correct, complete backup, so durability is preserved.

= 1.0.0 =
* First stable release.
* Maintenance crons: a daily backup-integrity sweep (cheap existence and size checks always, sha1 verification on a rotating subset so a large library is fully covered over time, lock-aware, chunked) that surfaces a terminal "backup missing" state instead of ever re-deriving a master from a watermarked file.
* Tmp/partial reaper: removes leftover staging files and orphaned `.partial` backups, age-gated and lock-aware so it never touches a job that is mid-write.
* Capability notice: a dismissible heads-up when neither Imagick nor GD can stamp images, with watermarking disabled until an engine is present.
* Lifecycle hardening: deactivation cancels any in-flight bulk run, releases locks, and unschedules all background work; activation schedules maintenance per site on multisite; uninstall clears schedules and (only on explicit opt-in) data, never the backups by default.
* Internationalization: all user-facing strings are translatable under the `og-watermark` text domain, with a bundled `languages/og-watermark.pot` template.
* Finalized documentation.

= 0.7.0 =
* Admin UI (OGM house style): settings page with live preview, per-image opt-in (media modal + list column + bulk actions), Bulk Tools page with progress, secured AJAX (per-object capability + nonce).

= 0.6.1 =
* Fix: big-image crop/rotate could poison or destroy the pristine backup — the editor bridge now reseeds the master only from the verified-clean edited file.

= 0.6.0 =
* WordPress integration: async reprocess on regenerate, crop/rotate editor bridge (edits operate on the clean backup), srcset coherence, Imagick-preference during regenerate.

= 0.5.0 =
* Bulk queue: background processing via Action Scheduler or a self-rescheduling wp-cron chain, one image per job, resumable bulk runs with progress + global lock, browser only observes.

= 0.4.0 =
* M4: idempotent processor — atomic per-image lock, signature short-circuit, rebuild-from-backup, stamp all sizes incl. original, temp-then-rename commit, restore/undo.

= 0.3.0 =
* M3: pristine backup manager (transactional create/verify/restore, mandatory sha1, offload-stub guard, source-of-truth invariant).

= 0.2.0 =
* Rendering engine: Imagick (preferred) and GD drivers with a shared interface; logo and text compositing; 9-point placement, scale, margin and opacity; JPEG flatten / PNG alpha / WebP / AVIF format policy; bundled DejaVu Sans Bold for text mode.

= 0.1.0 =
* Initial scaffold (foundation).
