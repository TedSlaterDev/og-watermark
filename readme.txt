=== OG Watermark ===
Contributors: orchardgrovemedia
Tags: watermark, images, media, branding, copyright
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.2.6
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

= 1.2.6 =
Adds Watermark / Remove buttons to the "Attachment details" of the media pop-up — including the panel you get when setting or changing a post's featured image, where WordPress does not otherwise show the plugin's controls.

= 1.2.5 =
Housekeeping: the "Bulk Tools" screen is now an "Apply" tab on the OG Watermark settings page instead of a separate menu item. Old bookmarks and the media-library "Watermark all flagged" action redirect to the new tab automatically.

= 1.2.4 =
More large-site hardening: the queue's duplicate-guard is now per-image (so a busy site can't strand an image as permanently "pending"), a mass thumbnail regenerate no longer floods the queue (it is paced by a self-draining catch-up), and the "Every image in the library" bulk option has been removed. Recommended for large/high-concurrency sites.

= 1.2.3 =
Hardening for high-traffic / high-concurrency sites (from a pre-launch audit): the per-image lock now uses an atomic compare-and-swap on re-claim so two workers can never process the same image at once, its lifetime was raised so a slow stamp can't lapse mid-job, processing errors no longer store raw (path-bearing) messages in postmeta, the capability report is no longer autoloaded, and the optional srcset filter only attaches when actually enabled (zero front-end hooks by default). Recommended for everyone, important for large sites.

= 1.2.2 =
The backup-exposure warning now appears only on the OG Watermark settings screen instead of on every wp-admin page, and the media-library status badges are now colour-coded (green "Watermarked", red "Error", amber "Out of date", etc.) instead of all showing gray.

= 1.2.1 =
Fixes text watermarks failing with an "Error" on many images: the text now shrinks to fit instead of being rejected when it is wider (or taller) than the image, the image you flag is always marked even if it is smaller than the thumbnail "minimum size" setting, and an image that is genuinely too small now reads as a neutral "Too small" instead of a red error. Recommended for anyone using text mode.

= 1.2.0 =
The "backups are in the public web directory" warning is now dismissible (click the X and it stays gone) and self-clearing: the plugin actively checks whether the backup folder is really reachable and hides the notice once you have secured it (e.g. added an nginx deny rule). A "Re-check now" button lets you confirm a fix immediately.

= 1.1.2 =
Completes the 1.1.1 "lock lost" fix for hosts WITHOUT a persistent object cache (e.g. WordKeeper) whose options-table read cache returned a stale miss for the lock — clicking Watermark still showed an error even though thumbnails were created. The per-image lock heartbeat now self-heals on both backends. Strongly recommended if 1.1.1 did not resolve the error.

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

= 1.2.6 =
* New: **Watermark** and **Remove** buttons in the media pop-up's "Attachment details" sidebar — most usefully the panel that opens when you **set or change a post's featured image**, where WordPress does not render the plugin's attachment field, so the controls were previously missing there. The buttons use the same secured per-image AJAX (capability + nonce) as the media-library controls, and are added by extending the media views (guarded so they never duplicate the control WordPress already shows in the media-library modal, and only for image attachments). Works in both the classic and block editors' featured-image pickers.

= 1.2.5 =
* Change: the "Bulk Tools" submenu is now an **"Apply" tab** on the OG Watermark settings screen, so watermark configuration and applying live on one page. The separate Bulk Tools menu item is removed; a bookmark to the old page (and the media-library "Watermark all flagged" bulk action) redirects to the new tab automatically. No change to how bulk runs work — they are still server-side/background and resumable.

= 1.2.4 =
* Fix (concurrency): the background-queue duplicate guard moved from a single shared `ogwm_pending` option to a per-attachment `_ogwm_pending` marker. Under concurrent workers the shared option could lose a write (last-writer-wins) and strand an image as permanently "pending", so it would silently never get (re)watermarked. Per-image markers cannot collide. The old option is cleaned up on upgrade. The enqueue path also now rolls the marker back if the underlying scheduler refuses the job (e.g. a cron-replacement plugin or Action Scheduler under load), closing a second way an image could get stuck "pending".
* New (scale): a mass thumbnail regenerate (e.g. `wp media regenerate --all`) over a large flagged library no longer floods the queue. Immediate reprocess jobs are capped per run; beyond the cap, affected images are marked and drained in bounded batches by a self-rescheduling catch-up, so the backlog is worked off at a controlled rate instead of all at once.
* Fix (UI): a bulk run's progress bar now always reconciles to 100% when it finishes. Previously, if some images already had a queued job when the run started (so their bulk job was deduped), the bar could finish stuck slightly below 100% even though every image was watermarked.
* Change: the **"Every image in the library"** bulk scope has been removed from Bulk Tools and is rejected server-side. Watermarking the whole library at once is a heavy, hard-to-undo operation; flag the images you want (individually or by media-library bulk action) and run the flagged scope instead. (The Bulk Tools page is already restricted to administrators.)
* Security: the queue no longer stores a raw exception message in post meta on a processing failure (it stores a fixed code and logs the detail under WP_DEBUG) — carried over and completed from 1.2.3.

= 1.2.3 =
* Fix (concurrency): the per-image lock heartbeat re-claimed a missing lock with a blind write, which — if the lock had genuinely lapsed and another worker acquired it in the gap — could let TWO workers process the same attachment at once (torn/double-processed served files). Re-claim now uses an atomic compare-and-swap (store-iff-absent) on both the object-cache and options backends, so a heartbeat can never overwrite a different owner. The pristine backup was always a hard floor against data loss; this closes the last window for a torn *served* image under heavy concurrency.
* Fix (concurrency): raised the per-image lock lifetime from 300s to 1800s so a single slow stamp of a very large original (with the job time limit lifted) cannot outlive the lock between heartbeats and lapse mid-job. It now matches the stuck-job reaper's ceiling.
* Security: a processing exception is now stored as a fixed reason code (`processing-exception`) instead of the raw throwable message, which routinely embedded absolute server paths and was shown in the media-list status tooltip. The full detail is written to the server error log under WP_DEBUG instead.
* Performance (scale): the capability report option is no longer autoloaded (it is read only by admin/cron), and the optional "hide unstamped srcset candidates" filter now attaches to the front-end responsive-image path ONLY when that toggle is enabled — so a default install adds no per-request work to that hot path.

= 1.2.2 =
* Change: the "backups are in the public web directory" warning now renders only on the OG Watermark settings screen (and only loads its dismiss/re-check script there), instead of on every admin page. The background exposure probe and auto-clear behaviour are unchanged.
* Fix: media-library status badges rendered as a uniform gray pill because the stylesheet defined status-named rules while the markup emits tone-named modifier classes. The badges are now colour-coded again — green "Watermarked", blue "Queued", amber "Out of date", red "Error"/"Too large"/"Backup missing", and gray "Too small"/"Skipped"/"Not flagged".

= 1.2.1 =
* Fix: text watermarks failed with a hard "Error" (status "failed") whenever the resolved text was wider than 90% of the image — which, because the point size scales with image width, happened for any moderately long watermark text at the default/high scale, on images of every size. The engine now **shrinks the text to fit** both the width and height budget (down to a legibility floor) before placing it, exactly as the code's own documentation always promised, so the mark is applied at a smaller size instead of the whole apply failing.
* Fix: the "minimum size" setting (`min_dimension_px`, default 300px) is a THUMBNAIL guard, but it was also skipping the primary image the user explicitly flagged — turning a small full-size image into an "Error". The minimum-size skip is now applied only to generated intermediate sizes; the full image and the high-resolution original you flag are always marked (subject to fitting the mark at all).
* New: a distinct **"Too small"** status (and neutral media-library badge) for an image that genuinely cannot carry the mark even after shrink-to-fit, instead of the red "Error" pill. This is a benign, informational outcome — not a failure.

= 1.2.0 =
* Improve: the backup-exposure admin notice (shown when the pristine-backup folder may be directly web-reachable on a server that ignores the plugin's per-directory deny files, e.g. nginx) is now **dismissible with a persisted dismissal** — clicking the X keeps it hidden across reloads, keyed to the current backup location so it re-appears only if that location changes. A **confirmed** exposure (one the plugin has actually fetched) is deliberately not dismissible, so a proven public exposure can never be one-click-hidden.
* Improve: the notice is now **self-clearing**. Instead of deciding solely from the server-software name, the plugin performs a lightweight loopback check — fetching a harmless marker file at the backup folder's URL — and caches the verdict (refreshed via a background wp-cron event, never on the admin request path). Once you secure the folder (add a server-level deny rule, or move it above the web root), the next check marks it protected and the notice disappears — no version bump required. A **"Re-check now"** button re-measures on demand and removes the notice immediately when the folder is confirmed protected. The check is conservative: it only marks the folder "protected" on a proven block (HTTP 401/403/404) or when it is not under any web-served path, and falls back to the previous server-name heuristic when a probe is inconclusive, so a real exposure is never silently hidden.
* Improve: the notice now shows only to users who can act on it (`manage_options`).
* Note: no new relocation UI in this release — the recommended fix on nginx remains a server-level deny rule. A robust prefix form that cannot be shadowed by an image-extension location block: `location ^~ /wp-content/og-watermark-originals- { deny all; }` (adjust the prefix if your backups resolved under `/wp-content/uploads/`).

= 1.1.2 =
* Fix: completes the 1.1.1 "lock lost" fix for the OTHER lock backend. On hosts with no persistent object cache, the lock lives in the options table; some managed hosts (e.g. WordKeeper, via a "Speed > WP Options" read cache) return a stale "miss" for the freshly written, non-autoloaded lock row, so the heartbeat read it as gone and aborted with "lock lost" even though the watermark was being written. The options-backend heartbeat (and release) now self-heal exactly like the object-cache backend: they re-assert ownership on a stale/own read and fail only when a different live owner — or a genuinely lapsed lock — is positively read. Also hardened the heartbeat so it no longer treats an unchanged-value write (`update_option()` / `wp_cache_set()` returning false) as a lost lock.

= 1.1.1 =
* Fix: on hosts WITH a persistent object cache that evicts entries early, the per-image lock heartbeat could falsely report "lock lost" mid-run — aborting after the first size and showing an error even though some thumbnails were already watermarked. The heartbeat now self-heals on eviction and fails only when a different live owner has genuinely taken over. (1.1.2 extends the same self-heal to the options-table backend.)

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
