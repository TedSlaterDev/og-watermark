> **Status:** SPEC produced by an 8-agent design + adversarial-review pass on 2026-06-29 (4 subsystem designers → 3 adversarial reviewers → synthesis). **All owner decisions resolved.** Decision #2 (2026-06-29): the on-disk high-res original **is** stamped; the immutable, hash-verified backup is the sole clean master. A few low-stakes defaults remain confirmable — see *Open questions*.

# OG Watermark — Specification

## Overview & goals

OG Watermark (by Orchard Grove Media LLC) bakes a watermark **into image files at the pixel level** using Imagick (preferred) or GD (fallback). The mark is permanent in the saved files — there is no CSS/JS overlay and no front-end runtime cost. The plugin exists to deter image theft, including theft of the high-resolution original.

**Goals**
1. Permanent, pixel-baked watermark (logo PNG or rendered text) on every served image size.
2. Per-image opt-in (flagging) as the canonical trigger — never the upload event.
3. A pristine, immutable backup of the true original as the single source of truth, so watermarks can **never stack** and any apply/restyle/undo is reproducible.
4. Work everywhere: single site + multisite, Imagick or GD, custom upload dirs, big-image `-scaled` originals, EXIF orientation, all common formats.
5. OGM house-style admin UI; i18n-ready; no build step; version bumped on every change.

**Non-goals (v1):** animated-GIF per-frame stamping; SVG rasterization; per-attachment style overrides; network-level (super-admin) global settings; offsite/compressed backups.

---

## Confirmed decisions

These are locked. Where the reviews surfaced a safety conflict, the resolved call is stated and the rationale recorded.

1. **Trigger = flagging, not upload.** Opt-in happens after WP has generated sizes. Single flag → AJAX apply; bulk flag → server-side queue. No `add_attachment`/auto-stamp on upload.
2. **Watermark all served sizes AND the on-disk high-res original** = the full/`-scaled` attached file + every enabled registered intermediate size + the true `original_image` file (only big images >2560px keep a separate `original_image`; for smaller images the full *is* the original, so it is already covered). **Owner decision (2026-06-29): stamp the high-res too**, for genuine anti-theft on every fetchable file. This is safe **only because** the immutable, hash-verified backup (Decision #3) is the sole clean master. The load-bearing safety property: the on-disk `original_image` is read as a clean source **exactly once, on first apply, while still pristine** (to seed the backup, gated on `_ogwm_status===none` + no existing valid backup); thereafter every (re)generation sources from `_ogwm_backup_rel` and **never** from `wp_get_original_image_path()`. The on-disk original may be watermarked, but it is never trusted as a clean source once the backup exists.
3. **Pristine backup is the single source of truth.** Every apply/restyle/regenerate/undo derives from the immutable backup. The backup is created and full-content-hash-verified **before** any served file is touched. No backup = no watermarking, ever.
4. **Watermark type:** uploaded logo PNG (alpha-composited) OR text (bundled TTF, tokens, stroke/shadow). Selectable in settings.
5. **Settings:** position (9-point grid), margin (% of shorter edge), scale (logo width % of image width with min/max px clamps), opacity, per-size enable/disable, skip-below-minimum dimension.
6. **Engine:** prefer Imagick, fall back to GD, capability-probed per format. Compositing works the **raw handle** (WP_Image_Editor cannot overlay/draw); resize/encode reuses WP_Image_Editor.
7. **Idempotency** via a per-attachment signature (settings + resolved-asset content hashes + plugin version) in post meta; mismatch triggers reprocess **from backup**.
8. **Conventions:** OGM green `#5b8c3e` UI; Author "Orchard Grove Media LLC"; text domain `og-watermark`; no build step; version bumped every change.

**Unified naming (as built in M1):** classes are **PSR-4 namespaced** under `OrchardGrove\OgWatermark\` (mapped to `src/` by a hand-rolled `Autoloader`); **global constants** use the `OGWM_` prefix (`OGWM_VERSION`, `OGWM_FILE`, `OGWM_DIR`, `OGWM_URL`, `OGWM_BASENAME`); options are `ogwm_*`, post meta `_ogwm_*`, hooks/filters `ogwm/*` or `ogwm_*`; text domain `og-watermark`. There is **no `OGWM_` class prefix** — where this SPEC still writes a class as `OGWM_Foo`, read it as its namespaced equivalent `OrchardGrove\OgWatermark\…\Foo`. The `OGM_WM_`/`ogm_wm_`/`_ogm_wm_` variants are dropped entirely.

---

## Architecture & file structure

Plain PHP, **no build step at runtime**. Classes are **PSR-4 namespaced** under `src/` (`OrchardGrove\OgWatermark\…`) and loaded by a hand-rolled `src/Autoloader.php`; **Composer is dev-only** (PHPUnit 10.5 + Brain Monkey + PHPCS/WPCS). `declare( strict_types=1 )` per file; WordPress Coding Standards formatting. PHP 8.1+, WP 6.0+. Mirrors the heirloom-seo house layout.

Files marked ✅ exist as of **M1**; others are scheduled for the noted milestone.

```
og-watermark/
├── og-watermark.php              # ✅ M1 — header, OGWM_ constants, Autoloader bootstrap, hooks
├── uninstall.php                 # ✅ M1 — gated; options + _ogwm_* meta; backups only on explicit double opt-in
├── composer.json                 # ✅ M1 — dev tooling (Composer dev-only; suggests ext-imagick/ext-gd)
├── phpunit.xml.dist              # ✅ M1
├── phpcs.xml.dist                # ✅ M1 — WordPress ruleset, FileName excluded for PSR-4
├── readme.txt / .gitignore / .distignore   # ✅ M1
├── .github/workflows/release.yml # ✅ M1 — rsync --exclude-from=.distignore → zip
├── assets/                       # M7 (fonts in M2)
│   ├── css/admin.css             #   .ogwm-* OGM house style (from editorial-qa CSS)
│   ├── js/{admin-settings,media,bulk}.js
│   └── fonts/<BundledSans>.ttf (+ LICENSE)   # M2 — OFL face for text mode
├── src/
│   ├── Autoloader.php            # ✅ M1 — PSR-4 (OrchardGrove\OgWatermark\ → src/)
│   ├── Plugin.php                # ✅ M1 — singleton boot, activate/deactivate, version sentinel
│   ├── Settings/
│   │   ├── Options.php           # ✅ M1 — typed dot-path accessor + grouped defaults (deep-merged)
│   │   └── Sanitizer.php         # ✅ M1 — register_setting callback (whitelist/clamp/validate)
│   ├── Support/
│   │   ├── Capabilities.php      # ✅ M1 — Imagick/GD + per-format + FreeType probe (cached)
│   │   ├── Meta.php              # ✅ M1 — _ogwm_* meta-key + status constants
│   │   └── Signature.php         # ✅ M1 — canonical idempotency hash (pixel-affecting settings only)
│   ├── Backup/
│   │   ├── Storage.php           # ✅ M1 — protected dir resolution/hardening + within() path guard
│   │   └── Manager.php           # M3 — pristine backup create/verify/restore; storage stats
│   ├── Engine/                   # M2 — Watermarker interface + Imagick/Gd drivers + Placement/TextSpec math
│   ├── Pipeline/Processor.php    # M4 — process_attachment(): THE single idempotent seam
│   ├── Queue/Queue.php           # M6 — Action Scheduler / wp-cron chain; global lock; bulk_state
│   ├── Integration/              # M5 — wp_generate_attachment_metadata listener, editor bridge (load_image_to_edit_path), srcset
│   ├── Admin/                    # M7 — Settings + Bulk pages, attachment field/column/bulk-action, AJAX
│   └── Cron/                     # M8 — integrity check + tmp/partial reaper
├── tests/                        # ✅ M1 — PHPUnit 10.5 + Brain Monkey (no DB); grows per milestone
└── languages/og-watermark.pot    # M8
```

**Single orchestration seam:** every caller (single flag, bulk job, regenerate listener, settings-change reprocess, WP-CLI) routes through:

```php
OrchardGrove\OgWatermark\Pipeline\Processor::process_attachment( int $id, string $context ): true|WP_Error
// $context ∈ {flag, bulk, regenerate, settings-change, cli}
```

---

## Rendering engine (Imagick/GD)

A `Watermarker` interface with two drivers, chosen once per request by `OGWM_Capabilities` and cached. Drivers receive **driver-agnostic absolute pixel geometry** computed once by the orchestrator (unit-testable without GD/Imagick).

```php
namespace OrchardGrove\OgWatermark\Engine;
interface Watermarker {
  public function load(string $path): bool;            // throws Engine\ImageException on failure
  public function dimensions(): array;                  // [w,h]
  public function paint_logo(string $logo_path, Placement $p): void;
  public function paint_text(TextSpec $t, Placement $p): void;
  public function save(string $dest, string $mime, int $quality): bool;
  public function free(): void;
}
```

**Capability detection (probe support, not just extension presence):**
- Driver: `extension_loaded('imagick') && class_exists('Imagick')` → imagick; else `function_exists('gd_info')` → gd; else none.
- Imagick formats: `array_map('strtoupper',(new Imagick())->queryFormats())`; per-format encode is gated on this, NOT on the extension being loaded.
- GD: `gd_info()` keys (`JPEG/PNG/WebP/AVIF Support`, `GIF Read/Create`, `FreeType Support`) plus `function_exists('imagecreatefromwebp'|'imagecreatefromavif')` (AVIF = PHP 8.1+).
- If the preferred driver can't encode a target format, fall back to the other driver **for that file**; if neither can, skip+log. **The GD path is first-class** (Imagick is commonly absent).

**Placement / scale / margin / 9-point grid (one place):**
```
target_w = clamp(round(imgW * scale_pct/100), min_px, max_px)
target_h = round(logo_native_h * target_w / logo_native_w)
margin   = round(min(imgW,imgH) * margin_pct/100)         // % of SHORTER edge
grid[position] → (x,y); clamp x∈[0,imgW-target_w], y∈[0,imgH-target_h]
SKIP this size if min(imgW,imgH) < min_dimension_px OR target_w > imgW*0.9
```

**Logo compositing**
- *Imagick:* `resizeImage(LANCZOS)`; opacity via `evaluateImage(EVALUATE_MULTIPLY, opacity, CHANNEL_ALPHA)` (NOT deprecated `setImageOpacity`); `compositeImage(COMPOSITE_OVER)`.
- *GD:* **never `imagecopymerge`** for alpha logos (black-box bug). Resample into a truecolor canvas with `imagealphablending(false)+imagesavealpha(true)`, fold global opacity into per-pixel alpha (`na = 127 - round((127-a)*opacity)`), then `imagecopy` onto the base with blending **on**. (This corrects the stray `imagecopymerge` mention in the backup draft — the engine class is the single source of truth for compositing.)

**Text rendering**
- Tokens resolved before measuring: `{site}`→`get_bloginfo('name')`, `{year}`→`wp_date('Y')`, `{copy}`→`©`, `{url}`→host of `home_url()`.
- Auto-size: `pt = clamp(imgW * text_scale_pct, min_pt, max_pt)`, then shrink-to-fit via `imagettfbbox`/`queryFontMetrics`.
- *GD:* 8-direction offset stroke pass, then fill (baseline Y = top + text height).
- *Imagick:* native `ImagickDraw::setStrokeWidth`.
- Shadow option: translucent dark pass offset `round(pt*0.06)` before fill.
- Bundled OFL TTF only; if FreeType/font unavailable, text mode is disabled with an admin notice (no silent fallback to a tiny bitmap font that misrepresents the result).

**Orientation — single owner = WordPress core.** Sizes are regenerated from the backup via `wp_create_image_subsizes()`/WP_Image_Editor, which normalizes EXIF orientation. The engine composites onto already-oriented pixels and **must NOT** call `Imagick::autoOrient()` / `imagerotate` itself (removes the double-rotation hazard). The engine asserts pre-oriented input.

**Format policy**
| Format | Action |
|---|---|
| JPEG | Watermark; flatten alpha onto configurable bg (default white) before encode |
| PNG | Watermark; `imagesavealpha(true)` on base, preserve alpha |
| WebP | Watermark if driver encodes WebP, else skip+log |
| AVIF | Watermark if driver encodes AVIF, else skip+log |
| GIF | Animated (`getNumberImages()>1` / multi GCE bytes) → SKIP; static → stamp frame 1 |
| SVG | SKIP (vector) |
| other/non-image | SKIP, never touch |

**Output fidelity:** JPEG quality via `apply_filters('jpeg_quality', $opt, 'ogwm')` (default 82); PNG lossless; one quality value reused across lossy formats. **Honor `$meta['sizes'][x]['mime-type']` and the existing size filename/extension** — never change a size's filename/format (would stale `$meta` and break srcset). If the host converts output to WebP (core 6.x / a plugin), follow the recorded per-size mime, not the original's format. CMYK JPEG → `transformImageColorspace(sRGB)` before compositing (Imagick); GD can't read CMYK reliably → route to Imagick or skip+log.

**Memory & cleanup:** pre-flight `bytes = w*h*4*1.8` vs `min(wp_convert_hr_to_bytes(memory_limit)*0.6 - memory_get_usage(true), hard_cap)`; the pre-flight runs even when `memory_limit = -1`. Imagick: `setResourceLimit(MEMORY/DISK)`; always `clear()+destroy()` every Imagick/Draw/Pixel. GD: `imagedestroy()` guarded by `function_exists` (no-op on 8.5), plus `unset()` and `gc_collect_cycles()` **between attachments** in a worker batch. All writes go to a temp file then atomic `rename()`; `ignore_user_abort(true)` during a job.

---

## Backup & source-of-truth model

**What to back up:** the TRUE original via `wp_get_original_image_path($id, true)` (resolves `original_image` for big images, else the attached file). **Critical resolver rule (Decision #2):** because the on-disk original is itself stamped after first apply, `wp_get_original_image_path()` is read **only on first apply** — gated on `_ogwm_status===none` AND no valid existing backup, i.e. while the file is still pristine. Once a backup exists, all regeneration reads `_ogwm_backup_rel` and the resolver is never used as a clean source again. Never back up the `-scaled` derivative as the master.

**Backup location.** Default **`WP_CONTENT_DIR/og-watermark-originals/`** (outside the web-served uploads tree) when writable; fall back to `wp_get_upload_dir()['basedir'].'/og-watermark-originals/'` only when content-dir is not writable. (Resolves the open question toward the safer default — backups are the crown-jewel asset and must not be web-fetchable.) Tree mirrors year/month derived from the live file's path:
```
<base>/og-watermark-originals/<YYYY>/<MM>/<attachmentID>-<sanitize_file_name(basename)>.<ext>
```
Attachment-ID prefix guarantees no collisions. When the fallback (in-uploads) location is used, also add a random token suffix and harden the dir.

**Directory hardening** (created on activation + self-healed on `admin_init`): `index.php`, `.htaccess` (`Deny from all` + Apache 2.4 `<RequireAll>Require all denied</RequireAll>` in `<IfModule>` guards + `Options -Indexes`), `web.config`. Detect server software; on nginx/IIS show a persistent admin notice with the exact deny snippet and reflect an "unprotected backup" state until acknowledged.

**Pre-flight validation before backup** (closes the offload/stub fan-out hole): resolved original must be a genuine local image — `realpath()` exists, `getimagesize()` succeeds, dimensions match `_wp_attachment_metadata` width/height within tolerance, bytes above a floor, and projected backup fits a `disk_free_space()` safety margin. Any ambiguity → abort as `skipped_offloaded`/`failed`, never proceed.

**Backup creation (transactional, mandatory full verification):**
1. Copy `original → <backup>.partial`.
2. `fsync` file; `rename()` to final; `fsync` the containing directory.
3. **Mandatory** `sha1` (full-content) match against source — not optional; byte-size alone is insufficient. Assert tmp and final are on the **same filesystem** (compare `dirname` device IDs); if not, use verified copy-with-rollback.
4. On any failure: delete partial/final backup, leave served files untouched, set `_ogwm_status=failed` + `_ogwm_last_error`, abort.
5. Store `_ogwm_backup_rel` (relative path), `_ogwm_backup_hash`, `_ogwm_backup_bytes`.

**Hard invariant:** `_ogwm_backup_hash` may only ever be set on a file the plugin has not composited. The backup is immutable after creation.

**Backup refresh on user crop/rotate — gated, not fingerprinted.** The only intentional backup-refresh path. Triggered **only** by WP's editor save seam (detect the attached-file basename now matching `-e<13-digit-timestamp>`, via `wp_save_image_file`/`wp_save_image_editor_file`), AND only when `self::$processing === false` AND the per-attachment lock is free. **Never** use filesize/mtime as the trigger (it conflates the plugin's own writes with user edits and can promote a watermarked file to the master). **Decision #2 wrinkle (load-bearing):** since the on-disk original is now watermarked, the plugin **feeds WP's image editor the clean backup as its source** via the `load_image_to_edit_path` filter whenever a flagged attachment is edited — so the user crops/rotates pristine pixels, WP writes clean `-e<timestamp>` files, the backup is refreshed from that clean edited original (same mandatory-hash gate), and all sizes are then re-stamped. Without this interception a crop would operate on watermarked pixels and could promote a watermarked file into the backup — forbidden. Also coexist with WP's "Restore original image" action (re-derive backup from the restored clean original).

**Storage transparency:** "Backup storage" sidebar card (total bytes via meta-sum with directory-size fallback, count, root path), cached in a 12h transient, recomputed on apply/restore.

---

## Data model (post meta + options keys)

**Options**
| Key | Autoload | Purpose |
|---|---|---|
| `ogwm_settings` | yes | Whole settings array (canonical) |
| `ogwm_version` | yes | Migration / version-change sentinel |
| `ogwm_capabilities` | yes | Cached engine-capability probe (recomputed on version change) |
| `ogwm_backup_base` | no | Resolved absolute backup base dir, decided once for a stable location |
| `ogwm_backup_token` | no | Unguessable random suffix appended to the backup dir name |
| `ogwm_bulk_state` | no | Bulk progress counters (done/total/failed/started_gmt) |
| `ogwm_bulk_queue` | no | Pending queue (flagged scope = ID list; "all" scope = cursor + criteria, paged, never the full ID array) |
| `ogwm_activity_log` | no | Rolling capped log (optional) |

**Post meta (all `_ogwm_` → protected from custom-fields UI)**
| Key | Purpose |
|---|---|
| `_ogwm_enabled` | `'1'` — flagged (the opt-in trigger) |
| `_ogwm_status` | `none｜queued｜watermarked｜failed｜restored｜skipped_offloaded｜backup_missing｜too_large` |
| `_ogwm_signature` | Canonical idempotency hash of the last applied stamp |
| `_ogwm_backup_rel` | Backup path relative to its base (portable across host moves) |
| `_ogwm_backup_hash` | sha1 of pristine backup at creation (integrity gate) |
| `_ogwm_backup_bytes` | Backup size (integrity sanity + storage reporting) |
| `_ogwm_sizes_done` | Map of size-slugs actually stamped (audit + resume); includes `full` and (for big images) `original` pseudo-slugs |
| `_ogwm_applied_gmt` | Last successful apply time |
| `_ogwm_last_error` | Last failure message (Media column) |

**Canonical signature (single formula, used everywhere):**
```
_ogwm_signature = substr( sha1(
    serialize( normalized_global_settings )      // includes type, position, margin, scale, opacity, sizes, min-dim, jpeg-bg
  . '|' . sha1_file( resolved_logo_file )        // CONTENT hash, not just attachment ID (catches "Replace Media")
  . '|' . sha1_file( resolved_font_file )        // catches bundled-asset changes
  . '|' . OGWM_VERSION
), 0, 16 );
```
Per-attachment style overrides are **out of scope for v1** (keeps the signature single-valued and avoids the cross-module ping-pong). Bump `OGWM_VERSION` whenever bundled assets change.

---

## Hook table

| Hook | Priority/Args | Purpose |
|---|---|---|
| `plugins_loaded` | — | Boot wiring |
| `init` | — | `load_plugin_textdomain('og-watermark', false, …/languages')` (WP 6.7+ safe timing) |
| `admin_menu` | — | `add_menu_page('OG Watermark', manage_options, 'ogwm', dashicons-format-image)` + submenus Settings, Bulk Tools |
| `admin_init` | — | `register_setting('ogwm_settings_group','ogwm_settings', sanitize)`; cached capability probe; dir self-heal |
| `admin_enqueue_scripts` | — | Gated to `ogwm*` pages (+`wp_enqueue_media()`), `upload.php`, `post.php`/`post-new.php` attachment contexts |
| `attachment_fields_to_edit` | 10,2 | Per-image checkbox + status badge + Apply/Remove button (modal + edit screen) |
| `attachment_fields_to_save` | 10,2 | Persist `_ogwm_enabled`; no-JS fallback sets `queued` |
| `manage_media_columns` / `manage_media_custom_column` | / 10,2 | "Watermark" column badge + inline button (list mode only) |
| `pre_get_posts` | — | Meta-orderby wiring for the sortable column on `upload.php` (REQUIRED for sortable to work) |
| `bulk_actions-upload` / `handle_bulk_actions-upload` | / 10,3 | Add Apply/Remove bulk actions; handler **only flags + seeds queue + redirects** to Bulk Tools (never composites inline) |
| `wp_generate_attachment_metadata` | **9999,3** | **Async only:** `if (self::$processing) return $meta; elseif (_ogwm_enabled && !pending-job) schedule one ogwm_process_one job;` always return `$meta` unchanged |
| `wp_save_image_file` / `wp_save_image_editor_file` | — | Crop/rotate backup-refresh seam (gated on `!self::$processing` + free lock) |
| `wp_calculate_image_srcset` | 10 | Only when `srcset_hide_unstamped` is ON and an attachment is mid-processing: drop still-unstamped variants. Zero overhead for fully-stamped attachments |
| `wp_image_editors` | `PHP_INT_MAX` | Prefer Imagick for the clean-resize step (override SiteGround/WordKeeper GD-forcing); removed after job |
| `ogwm_process_one` | (AS/cron action) | `do_action` → `process_attachment($id,'bulk'|'regenerate')` |
| `plugin_action_links_*` / `plugin_row_meta` | — | Settings + Support links |
| `wp_ajax_ogwm_apply_single`/`remove_single`/`preview`/`bulk_progress` | — | See Admin UX |
| `register_activation_hook` / `register_deactivation_hook` | — | Lifecycle (below) |
| daily `ogwm_integrity_check`, `ogwm_tmp_reaper` | (cron/AS) | Backup integrity + tmp cleanup (below) |

---

## Processing flow (apply / reprocess / undo)

**One authoritative pipeline (temp-build-then-swap). This replaces the contradictory "restore-all-in-place-first" variant — served files are touched LAST, only on success.**

`process_attachment($id, $context)`:
1. Bail if not `wp_attachment_is_image($id)`; for regenerate/settings paths bail if not `_ogwm_enabled`.
2. **Acquire atomic per-attachment lock.** Use `wp_cache_add("ogwm_lock_$id", $token)` (atomic on object caches) or `add_option`-based lock (atomic DB unique-key) — **not** get-then-set transients. Store a unique owner token; release only on token match (compare-and-delete). **Heartbeat-refresh** the lock during long jobs so a multi-minute big-image job can't have its lock expire under a concurrent writer. Also set static `self::$processing`.
3. Compute current signature; if it equals `_ogwm_signature` **and** all `_ogwm_sizes_done` files exist → no-op return true (signature check happens **before** any expensive decode).
4. **Ensure backup** (pre-flight validation + transactional create + mandatory hash; section above). If absent and can't be created → abort `failed`. Existing backup is verified `sha1 == _ogwm_backup_hash`; mismatch → abort `backup_missing` (never re-derive from a served file).
5. **Regenerate clean sizes from the backup into a temp working area.** First restore the backup to the canonical on-disk original location, then run `wp_create_image_subsizes()` against that **in-place** path (never against a path inside the backup store — that would repoint `original_image`/`-scaled` into the backup tree). Core handles big-image `-scaled` creation and EXIF orientation. NOTE: `wp_create_image_subsizes()` does **not** fire the `wp_generate_attachment_metadata` filter (core's graph is the reverse: `wp_generate_attachment_metadata()` *calls* `wp_create_image_subsizes()`), so our own regenerate never trips the M5 listener. `self::$processing` is purely defensive against a **foreign** `wp_generate_attachment_metadata` caller (Regenerate Thumbnails, `wp media regenerate`) re-entering `Processor::process` while we are mid-run; the M5 listener reads `Processor::isProcessing()` and bails at the top of its own callback.
6. **Stage all outputs:** composite the mark onto each enabled size + the `-scaled`/full file **+ the on-disk `original_image`** (for big images; Decision #2) — all via the raw handle, writing each to a per-job unique temp file (`<name>.ogwm-<jobtoken>.tmp`). Pre-flight memory per file; a size over budget is recorded `too_large` for that slug.
7. **Commit phase (renames last, nothing heavy between them):** atomic `rename()` each staged temp → final served path. Track each in `_ogwm_sizes_done` as it lands so a crashed/resumed run finishes only missing renames.
8. Clear `self::$processing`; **write `_ogwm_signature` only after the last required size committed.** Set `_ogwm_status`, `_ogwm_applied_gmt`, clear `_ogwm_last_error`. **Block the `watermarked` status/badge unless the full/`-scaled` file actually stamped** — if the highest-value served file was skipped `too_large`, status is `failed`/`too_large`, surfaced prominently (not a silent log).
9. Release lock (token-matched) in `finally`.

**Reprocess / restyle (settings change):** identical to steps 4–8, skipping the backup *copy* (backup already exists and is immutable). Settings save bumps nothing destructive; the new signature simply mismatches all attachments → "Watermarked (out of date)" badge + "Re-apply" + an admin notice deep-linking to Bulk Tools. Re-apply always rebuilds from the immutable backup → never stacks.

**Coexistence with third-party regenerate / WP-CLI:** `wp media regenerate`, Regenerate Thumbnails, etc. fire `wp_generate_attachment_metadata`; the 9999 listener schedules **one async** `ogwm_process_one` per flagged attachment (de-duplicated via `as_has_scheduled_action`/pending-args check to avoid a thundering herd). It never stamps inline inside a foreign loop. Document that headless CLI regenerate needs cron/AS to actually run the queued jobs (or a follow-up bulk run) since there's no browser.

**Undo / restore (`restore($id)`):**
1. Acquire lock; verify backup exists and `sha1 == _ogwm_backup_hash` (else abort `backup_missing`).
2. Restore backup → on-disk original location (temp + atomic rename).
3. Regenerate all clean sizes from backup into temp; **commit all renames first.**
4. **Only after every rename is verified** clear `_ogwm_signature`, `_ogwm_sizes_done`, `_ogwm_applied_gmt`, `_ogwm_status`, `_ogwm_enabled`.
5. Keep the backup by default; "delete backup too" runs as the very last step, only after the restored full file is re-read and sanity-checked.
6. On any mid-restore failure: leave meta + backup intact, mark for retry. Clearing meta without rebuilding clean files is forbidden.

**Terminal `backup_missing` recovery story (explicit):** if the pristine backup is gone/corrupt out-of-band and served files are watermarked, freeze all reprocess/restore for that ID, surface a loud per-attachment notice, and offer the only honest options: re-upload a clean original to rebuild the backup, or accept the permanent watermark. The integrity cron acquires (or skips) the per-attachment lock so it never reads meta mid-apply.

---

## Admin UX (settings, opt-in, bulk tool)

Top-level OGM-styled menu **"OG Watermark"** (`dashicons-format-image`), submenus **Settings** + **Bulk Tools**. Header → tabs → white cards → sidebar, classes `.ogwm-*` mirroring `.ogm-eqa-*`/`.hseo-*`.

**Settings page** (`<form action="options.php">` + `settings_fields('ogwm_settings_group')`):
1. **Watermark type** — radio logo｜text; JS toggles panels. Logo: `wp.media` picker → stores attachment ID; PNG-only. Text: template input (tokens), size, color, stroke｜shadow｜none + treatment color.
2. **Placement** — 3×3 grid radio picker (active cell green); margin (% shorter edge).
3. **Sizing & opacity** — scale slider + min/max px clamps; opacity slider; `<output>` mirrors.
4. **Sizes** — checklist from `wp_get_registered_image_subsizes()` + `full` (with `-scaled` note); skip-below-min px input.
5. **Sidebar** — live preview card + "Backup storage" card + at-a-glance status.

**Live preview** (`wp_ajax_ogwm_preview`): runs the **real engine** on a bundled sample using sanitized in-form (unsaved) POST values (same `sanitize_callback`), returns a data URL / temp URL inside uploads (path never echoed in errors). Debounced 400ms **client-side AND** rate-limited **server-side** (short per-user transient), with capped sample dimensions and text length to prevent a direct-POST CPU-pin loop.

**Per-image opt-in** (four native surfaces, one field code path): `attachment_fields_to_edit` checkbox + status badge + inline Apply/Remove (covers modal + edit screen + grid detail pane); list-table "Watermark" column (badge: Not flagged｜Queued｜Watermarked(date)｜Out of date｜Error｜Skipped) with inline button; bulk actions. Non-image attachments render "—" and the field is hidden (`wp_attachment_is_image` guard). Newly-checked-with-JS fires `ogwm_apply_single`; no-JS saves `queued` for the next bulk run.

**Bulk Tools page** — **server-side processing; the browser only observes.** Start (or `?ogwm_resume=1` redirect from the bulk action) → `ogwm_bulk_start` builds the queue (lazy/paged cursor for "all" scope), seeds `ogwm_bulk_state`, enqueues `ogwm_process_one` jobs (Action Scheduler if `function_exists('as_enqueue_async_action')`, else self-rescheduling `wp_schedule_single_event` chain, one attachment per job) under the global `ogwm_bulk_lock`. `bulk.js` **polls `wp_ajax_ogwm_bulk_progress` read-only**; closing the tab does not stop work. A free-space pre-flight estimates total backup growth for the scope and warns/refuses before starting.

**AJAX endpoints, caps, nonces**
| Action | Trigger | Cap | Nonce | Returns |
|---|---|---|---|---|
| `ogwm_apply_single` | flag/Apply | `edit_post($id)` | `ogwm_admin` | {status, badge_html, message} |
| `ogwm_remove_single` | Remove | `edit_post($id)` | `ogwm_admin` | {status, badge_html} |
| `ogwm_bulk_start` | Start/resume | `manage_options` | `ogwm_admin` | {total} |
| `ogwm_bulk_progress` | bulk.js poll (read-only) | `manage_options` | `ogwm_admin` | {done,total,failed,current_title,remaining} |
| `ogwm_preview` | settings change | `manage_options` | `ogwm_admin` | {img_url} |

Order in every handler: **validate ID** (`absint` + `get_post_type==='attachment'` + `wp_attachment_is_image`) → **capability** → `check_ajax_referer('ogwm_admin','nonce')`. Per-image actions use the per-object `edit_post($attachment_id)` cap (resolves the `upload_files` vs `edit_post` contradiction toward the stricter check — closes the IDOR where any author could strip watermarks off others' images). `manage_options` for settings/bulk/preview.

**Idempotency UX:** when the saved settings hash changes, an `admin_notices` banner ("Settings changed — N images out of date. Reprocess?") appears on plugin screens; stale attachments show "Out of date" + "Re-apply" and are included in the "All flagged" bulk scope.

---

## Settings reference (with defaults)

| Setting | Key (in `ogwm_settings`) | Default | Notes |
|---|---|---|---|
| Watermark type | `type` | `text` | `logo｜text` |
| Logo attachment | `logo_id` | `0` | PNG only; re-verified on use |
| Text template | `text` | `{copy} {year} {site}` | tokens resolved at render |
| Text color | `text_color` | `#ffffff` | |
| Legibility treatment | `treatment` | `stroke` | `stroke｜shadow｜none` |
| Treatment color | `treatment_color` | `#000000` | |
| Text scale | `text_scale_pct` | `4` | % of image width → pt, clamped |
| Position | `position` | `bot-right` | 9-point enum |
| Margin | `margin_pct` | `3` | % of shorter edge |
| Logo scale | `scale_pct` | `18` | % of image width |
| Min px / Max px | `min_px` / `max_px` | `60` / `600` | logo width clamps |
| Opacity | `opacity` | `70` | 0–100 |
| Enabled sizes | `sizes[]` | full + all registered | per-size toggle |
| Skip below | `min_dimension_px` | `300` | shorter-edge threshold |
| JPEG flatten bg | `jpeg_bg` | `#ffffff` | for alpha→JPEG |
| Hide unstamped in srcset | `srcset_hide_unstamped` | `false` (OFF) | zero front-end overhead by default; belt-and-suspenders for partial window |
| Delete data on uninstall | `delete_data_on_uninstall` | `false` | options + meta only |
| Also remove backups on uninstall | `delete_backups_on_uninstall` | `false` | destructive; loud warning; never auto-restores |

`sanitize_callback` whitelists the position enum, clamps every numeric range, `absint`s pixel clamps, validates color hex, and rejects a non-PNG logo.

---

## Security checklist

- **Per-image authorization = `current_user_can('edit_post', $attachment_id)`** (not blanket `upload_files`) for every apply/remove/flag/column action, after ID validation. Resolves the IDOR. `manage_options` for settings/bulk/preview.
- **Cap check FIRST, then nonce** (`check_ajax_referer`/`check_admin_referer`); bulk-action handler also re-checks the cap after WP's bulk nonce.
- **Atomic locks** via `wp_cache_add`/`add_option` (not get-then-set transients), with owner-token compare-and-delete + heartbeat.
- **Path-traversal defense (load-bearing):** resolve `realpath(base)`; for every read/write/backup require `strncmp(realpath($path), $base.DIRECTORY_SEPARATOR, strlen($base)+1)===0` (rejects escaping symlinks). All backup/target paths are plugin-derived (`<id>-<sanitized basename>`), stored relative, revalidated on read. Refuse if `wp_get_upload_dir()` errors.
- **Logo validation:** PNG only (`IMAGETYPE_PNG`), re-verify with `getimagesize()` + `wp_check_filetype_and_ext()` on every use; store as attachment ID; reject SVG/polyglots outright.
- **Backup confidentiality:** default outside web-served uploads (`WP_CONTENT_DIR`); when in uploads, dir-deny + `index.php` + random token suffix + nginx/IIS warning.
- **Output escaping** everywhere (`esc_html/esc_attr/esc_url`, `wp_kses` for AJAX badge fragments); never echo server/temp paths in any error.
- **Preview throttle** server-side; sanitize POSTed settings through the production callback before the engine.
- **No raw SQL with user input** (meta APIs); uninstall `LIKE` uses `$wpdb->prepare` + `esc_like`.
- **Bundled TTF ships with its OFL license file;** no arbitrary user fonts (FreeType + untrusted fonts is an attack surface).

---

## Compatibility & edge cases

- **Big-image `-scaled`:** back up the true `original_image` (clean, first apply only); regenerate `-scaled` + sizes from the backup; stamp the `-scaled`/full + every enabled size **and the on-disk `original_image`** (Decision #2). The backup — not any on-disk file — is the clean master; `wp_get_original_image_path()` is never used as a clean source once the backup exists. Do **not** stamp or back up the `-scaled` as master.
- **Regenerate Thumbnails / WP-CLI:** async one-job-per-attachment, de-duplicated; signature short-circuits redundant work.
- **Crop/rotate / WP "Restore original image":** gated backup refresh via the editor save seam only (never filesize/mtime).
- **Offloaded media (S3/Offload Media remove-local, Photon, Cloudinary):** hard pre-flight re-checked **at job execution** (`realpath`-exists on `get_attached_file()` + provider hooks); stub/dimension-mismatch → `skipped_offloaded`, abort before any write. Document tested-compatible vs incompatible offloaders.
- **EXIF orientation:** core-owned; engine never re-orients.
- **CMYK JPEG:** Imagick sRGB transform; GD → route to Imagick or skip+log.
- **Animated GIF / SVG / non-image:** skip with surfaced reason.
- **Format conversion hosts (WebP output):** honor recorded per-size mime/filename.
- **Partial-stamp window:** all enabled sizes stamp in one job; signature written only after the last; `srcset_hide_unstamped` available for the window; skip-below-min thumbs are intentionally unmarked (documented).
- **Multisite:** per-site `ogwm_settings` (no network option); `wp_get_upload_dir()` is auto site-scoped; network-activate runs activation lazily per site via the `ogwm_version` check; `manage_options` (no super-admin).
- **Object-cache hosts:** locks use atomic primitives, not transients.
- **`memory_limit = -1` / CloudLinux LVE:** pre-flight estimate runs regardless; over-budget skips the size (surfaced), never fatals the attachment.

---

## Performance & bulk strategy

- **Bulk = server-side jobs, one attachment per job** (Action Scheduler preferred, self-rescheduling cron chain fallback). Global `ogwm_bulk_lock` (heartbeat-refreshed) prevents overlap. Browser polls read-only; tab-close safe.
- **Per-job environment:** `@set_time_limit(0)`, `wp_raise_memory_limit('image')`, `ignore_user_abort(true)`, Imagick-preference filter for resize, free every handle + `gc_collect_cycles()` between attachments to flatten peak.
- **Coalescing:** dedupe scheduled reprocess jobs (unique-action / pending-args check) to avoid a thundering herd after a foreign regenerate.
- **Storage:** each flagged image roughly doubles its master's footprint (backup only; sizes are regenerated, not duplicated). Pre-flight `disk_free_space()` before each backup copy and before a bulk run (estimate = Σ original bytes for scope); refuse/warn beyond a safety margin; show projected size in the bulk scope summary.
- **Too-large handling:** first-class `too_large`/`failed` status surfaced in the Media column + admin notice; consider Imagick disk-cache (`setResourceLimit DISK`) for oversized originals; never report `watermarked` if the full/`-scaled` was skipped.
- **Integrity cron (daily):** above N backed-up attachments, default to cheap existence + byte-size `stat` checks and sha1 only a rotating subset per day (whole set covered over a window); chunk via Action Scheduler; acquire/skip the per-attachment lock.
- **Tmp/partial reaper cron:** age-gated (older than max job runtime), lock-aware (skip currently-locked IDs), per-job unique tmp suffixes prevent cross-job collision; lone `.partial` backups treated as "no backup."
- **Front-end:** zero JS/CSS; fully-stamped attachments don't touch `wp_calculate_image_srcset`.

---

## Testing plan

**Unit (no GD/Imagick needed):** placement/scale/margin/9-point math + clamps; signature formula (incl. logo/font content-hash changes); format policy decisions; path-traversal `realpath` prefix check; settings sanitize (enum/range/color/PNG-logo rejection).

**Engine (GD + Imagick matrices):** logo opacity correctness (no black box on alpha PNG); text stroke/shadow legibility; JPEG alpha-flatten; PNG alpha preserved; WebP/AVIF gated; animated-GIF skip; CMYK route; orientation NOT double-applied.

**Pipeline / idempotency:** apply→re-apply is a no-op (signature); settings change → reprocess-from-backup never stacks (assert pixel-identical to a single fresh stamp); crash-after-rename-N resumes and completes only missing sizes; restore rebuilds clean files and only then clears meta.

**Data-safety invariants:** backup created+hash-verified before any served write; **backup hash only ever set on a never-composited file** (hard invariant test); abort on backup hash mismatch never reads a served file; stub/offload pre-flight aborts before write; disk-full aborts apply without touching served files.

**Concurrency:** two workers on one ID → atomic lock admits one; lock heartbeat survives a long job; owner-token release doesn't drop another's lock.

**WP integration:** Regenerate Thumbnails + `wp media regenerate` → async, no double-stamp, no recursion; crop/rotate refreshes backup only via editor seam; multisite per-site scoping; sortable column orderby; offload-plugin skip.

**Security:** IDOR attempt (author POSTs another's ID) is rejected by `edit_post`; non-PNG/SVG logo rejected; preview rate-limit; no path leakage in errors.

PHPUnit suite (mirroring heirloom-seo's), with WP test harness for the integration cases.

## Build milestones

### M1 — Foundation, settings, capabilities

Bootstrap (og-watermark.php), constants/namespace OGWM_, plugins_loaded wiring, textdomain on init. OGWM_Settings (defaults/get/sanitize) as the single option-shape source. OGWM_Capabilities (driver + per-format + FreeType probe, cached transient, recomputed on version change). OGWM_Meta with the canonical signature formula. Activation: PHP/WP minimum checks, backup-dir creation+hardening (WP_CONTENT_DIR default, uploads fallback), ogwm_version. uninstall.php (gated, never deletes backups by default). PHPUnit scaffold + unit tests for signature + sanitize.

### M2 — Rendering engine

Watermarker interface + Imagick and GD drivers. Driver-agnostic placement/scale/margin/9-point math (fully unit-tested). GD logo path with per-pixel alpha-fold (NO imagecopymerge); Imagick evaluateImage opacity + composite. Text mode (tokens, auto-fit, GD 8-pass stroke / Imagick native stroke, shadow). Format policy (JPEG flatten, PNG alpha, WebP/AVIF gating, animated-GIF/SVG skip, CMYK route). Honor per-size mime/filename. Memory pre-flight + handle cleanup. Engine is the single source of truth for compositing. Engine test matrix on GD (and Imagick where available).

### M3 — Backup & source-of-truth

OGWM_Backup: resolve true original via wp_get_original_image_path; pre-flight validation (real local image, dimensions match meta, disk_free_space); transactional create (.partial → fsync → same-FS rename → dir fsync → MANDATORY full sha1); realpath strict-prefix path validation; restore; storage stats. Hard invariant: backup hash only ever set on a never-composited file. Data-safety invariant tests (backup-before-write, abort-on-mismatch, stub/disk-full abort).

### M4 — Processor (the single seam) + idempotency

OGWM_Processor::process_attachment with the authoritative temp-build-then-swap pipeline: atomic lock (wp_cache_add/add_option + owner token + heartbeat) + static reentrancy flag; signature short-circuit before any decode; ensure-backup; restore-to-in-place then wp_create_image_subsizes; stage all outputs to per-job temp files; commit renames last; write signature only after the full/-scaled stamps; block 'watermarked' if the full file was skipped. restore() (renames-before-meta-clear). backup_missing/too_large/skipped_offloaded terminal handling. Pipeline + idempotency + concurrency tests.

### M5 — WP integration & coexistence listeners

wp_generate_attachment_metadata at 9999 ASYNC-ONLY (self::$processing bail; else schedule one deduped ogwm_process_one). Crop/rotate backup-refresh gated on the editor save seam only (never filesize/mtime) + WP Restore-original coexistence. wp_image_editors Imagick preference. Offload pre-flight re-checked at job execution. EXIF owned by core (engine never re-orients). Integration tests: Regenerate Thumbnails, wp media regenerate, crop/rotate, multisite, offload skip.

### M6 — Bulk queue & background processing

OGWM_Queue: Action Scheduler when available, self-rescheduling wp-cron chain fallback, one attachment per job, global heartbeat lock, lazy/paged 'all' scope cursor, job dedup/coalescing. Per-job environment (set_time_limit/raise memory/ignore_user_abort/gc between attachments). ogwm_bulk_state progress. Disk-free pre-flight for the run.

### M7 — Admin UI (OGM house style)

Top-level menu + Settings + Bulk Tools pages; .ogwm-* CSS from ogm-eqa (header/tabs/cards/badges/grid/progress). Settings cards (type/placement/sizing/sizes) + wp.media logo picker + slider mirrors. Live preview via real engine on sanitized unsaved POST, server-side rate-limited. Per-image field across modal/edit/grid + list column + sortable (pre_get_posts) + bulk actions that only flag+queue+redirect. bulk.js as read-only progress observer. AJAX handlers with edit_post per-image cap, manage_options elsewhere, cap-then-nonce ordering, ID validation. Out-of-date/Re-apply UX.

### M8 — Crons, hardening, lifecycle, i18n, docs

Integrity cron (threshold-gated sha1, rotating subset, lock-aware, chunked) with terminal backup_missing recovery affordance. Tmp/partial reaper (age-gated, lock-aware, unique suffixes). Server-software detection + nginx/IIS deny notices. Capability-missing notice that disables flag controls. Deactivation (unschedule jobs, release locks). Multisite per-site activation lazily. .pot + languages. readme.txt changelog, bundled font LICENSE. Full security + integration test pass; version bump.

## Top risks

- Backup integrity is the whole product. The single most dangerous failure is ever promoting a watermarked file into the pristine backup (irreversible). Because Decision #2 stamps the on-disk original, that file is **not** a safe clean source — so the mitigations are: (a) read `wp_get_original_image_path()` only on first apply, gated on `_ogwm_status===none` + no existing backup; (b) the hard invariant that `_ogwm_backup_hash` may only be set on a never-composited file; (c) feed WP's image editor the clean backup via `load_image_to_edit_path` so crop/rotate never edits watermarked pixels; (d) gate any backup refresh strictly on the editor save seam. Any code path that violates these silently bakes a permanent, unremovable watermark into the only master.
- Cross-file atomicity is impossible on POSIX: an attachment's N sizes are renamed one-by-one, so a crash mid-commit leaves a mixed clean/watermarked set (and an inconsistent srcset window). The backup — not the served set — is the real safety guarantee; recovery (resume only missing renames via _ogwm_sizes_done) is the mitigation, not true transactionality.
- Memory on big-image originals (e.g. 6000x4000 ≈ 96MB raw, multiple concurrent handles) can exceed the 256M 'image' context on shared hosts. The high-value full/-scaled file getting skipped 'too_large' would defeat the anti-theft purpose; mitigated by surfacing it as a first-class failure and Imagick disk-cache, but some hosts genuinely cannot stamp the largest images.
- Lock correctness on object-cache hosts: transients are non-atomic and evictable, so the design depends on wp_cache_add/add_option atomic primitives plus owner-token compare-and-delete and heartbeat. A regression back to get-then-set transients reintroduces concurrent-writer corruption that atomic-rename only partially masks.
- Storage doubling: flagging a large library can double total media storage with no recovery if the disk fills mid-bulk. Disk-free pre-flight mitigates but the dominant resource cost remains, and in-uploads fallback backups are also the assets most likely to be deleted out-of-band by other tools, leading to the terminal backup_missing state.
- Offload/CDN false negatives: if detection misclassifies a stub/zero-byte local file as the true original, the pipeline could back up or regenerate from garbage and fan out across all sizes. Mitigated by execution-time dimension/byte sanity checks plus default-skip-on-ambiguity, but newer offload plugins may not be covered.

## Open questions

**Decision #2 (stamp the high-res original) is RESOLVED — confirmed by the owner 2026-06-29.** The remaining items below are low-stakes defaults already adopted in this SPEC; listed for confirmation, none block the build.

1. **Backup location** — default `WP_CONTENT_DIR/og-watermark-originals` (outside web-served uploads) for confidentiality, falling back to uploads when content-dir isn't writable. Auto-adapts per host; if uploads-only is preferred for portability, the random-token suffix + nginx deny notice become the primary control. *(Adopted: content-dir-with-fallback.)*

2. **Bundled font** — ship one GPL-compatible OFL face for text mode (proposed: a clean SIL OFL sans). Must be unambiguously redistributable inside a GPL plugin; Ted can swap the face later. *(Adopted: bundle a default OFL sans + its license file.)*

3. **Action Scheduler** — wp-cron self-rescheduling chain as the primary/guaranteed path + opportunistic use of Action Scheduler when a host already provides it; no hard dependency, no bundling. *(Adopted.)*

4. **`srcset_hide_unstamped`** — default OFF (zero front-end overhead, Heirloom ethos). Tiny skip-below-min thumbnails can appear unmarked in srcset; flip ON for stricter coverage at a small front-end cost. *(Adopted: OFF.)*

5. **Integrity cron cadence** — daily existence/byte checks with sha1 on a rotating subset (whole set covered over a window), auto-throttled above N backed-up attachments. *(Adopted: daily + rotating sha1.)*

6. **Per-attachment style overrides** (position/opacity per image) — deferred to v2 to keep the signature single-valued; v1 is global-only styling. *(Adopted: global-only for v1.)*

