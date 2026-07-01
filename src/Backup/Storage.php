<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Pristine-backup storage: directory infrastructure + path-safety guards.
 *
 * The backup tree holds the single source of truth — the immutable, clean
 * masters from which every (re)stamp is derived. It must live outside the
 * web-served uploads tree when possible, and be hardened against directory
 * listing and direct fetches everywhere.
 *
 * M1 scope = directory resolution, creation, hardening, self-heal, and the
 * load-bearing path-traversal guard only. The actual backup copy / verify /
 * restore / stats live in M3 and are marked below.
 */
final class Storage {

	/** Backup subdirectory name, appended to whichever base root is chosen. */
	private const SUBDIR = 'og-watermark-originals';

	/**
	 * Option storing the random token suffix appended to the resolved base, so
	 * the directory name is unguessable and the same location is reused across
	 * requests regardless of transient writability changes.
	 */
	private const TOKEN_OPTION = 'ogwm_backup_token';

	/**
	 * Option storing the resolved absolute base directory, decided once (at
	 * activation / first use) so the location can never silently switch between
	 * the wp-content and uploads roots if WP_CONTENT_DIR writability flips.
	 */
	private const BASE_OPTION = 'ogwm_backup_base';

	/**
	 * Option caching the measured web-exposure verdict for the CURRENT base, as
	 * ['state' => 'exposed'|'protected'|'unknown', 'base' => string, 'time' => int].
	 * Written by {@see probeExposure()}; a verdict for a different base is ignored.
	 */
	private const EXPOSURE_OPTION = 'ogwm_backup_exposure';

	/**
	 * Option recording the base dir for which the operator dismissed the exposure
	 * notice. Keyed to the path so the notice re-appears if the base ever moves.
	 */
	private const DISMISS_OPTION = 'ogwm_backup_notice_dismissed';

	/** Option storing the random marker embedded in the access-probe canary file. */
	private const PROBE_MARKER_OPTION = 'ogwm_backup_probe_marker';

	/** Canary filename fetched over HTTP to measure whether the base is web-served. */
	private const PROBE_FILE = 'og-watermark-access-probe.txt';

	/** wp-cron hook that runs the loopback exposure probe OFF the request path. */
	public const PROBE_HOOK = 'ogwm_backup_probe';

	/** Seconds a CONCLUSIVE exposure verdict (exposed/protected) is trusted before re-probing. */
	private const EXPOSURE_TTL = 43200; // 12 hours.

	/** Seconds an INCONCLUSIVE verdict ('unknown') is trusted — short, so it re-probes soon. */
	private const UNKNOWN_TTL = 1800; // 30 minutes.

	/**
	 * Hook suffix / screen id of the main OG Watermark settings page, where the
	 * exposure notice is shown (NOT globally). The add_menu_page slug is 'ogwm', so
	 * WordPress derives this id — kept in sync with Admin\SettingsPage::PAGE.
	 */
	private const SETTINGS_HOOK = 'toplevel_page_ogwm';

	/** Resolved base dir, cached for the duration of the request. */
	private static ?string $base = null;

	/**
	 * Absolute path to the backup base directory (no trailing slash).
	 *
	 * The location is decided ONCE and persisted in the `ogwm_backup_base`
	 * option, then reused on every subsequent request. This keeps backups (and
	 * the `_ogwm_backup_rel` meta that resolves against this base) stable even if
	 * the writability of WP_CONTENT_DIR changes between requests.
	 *
	 * Both candidate roots (wp-content and the uploads fallback) get the SAME
	 * unguessable random token suffix. wp-content is web-served on virtually all
	 * installs and the per-directory deny files (.htaccess/web.config) are
	 * Apache/IIS-only — they do NOTHING on nginx — so the token is the only
	 * cross-server obfuscation we can rely on. See isWebReachable()/maybeNotice()
	 * for the nginx-exposure admin warning.
	 */
	public static function baseDir(): string {
		if ( null !== self::$base ) {
			return self::$base;
		}

		// Reuse the persisted location if it is still present, so the resolved
		// base never moves out from under existing backups.
		$stored = get_option( self::BASE_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored && is_dir( $stored ) ) {
			self::$base = untrailingslashit( $stored );
			return self::$base;
		}

		$base = self::resolveBase();

		// Persist the choice the first time we successfully create it; we record
		// it here so a stored-but-deleted base re-resolves to the same candidate.
		update_option( self::BASE_OPTION, $base, false );

		self::$base = $base;
		return self::$base;
	}

	/**
	 * Pick the candidate base root. Prefers WP_CONTENT_DIR when writable, else
	 * the uploads basedir. Either way the unguessable token suffix is applied so
	 * the directory is not fetchable at a guessable URL on web-served roots
	 * (notably nginx, where the deny files are inert).
	 */
	private static function resolveBase(): string {
		$contentRoot = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

		if ( is_writable( $contentRoot ) ) {
			$root = untrailingslashit( $contentRoot );
		} else {
			$uploads = wp_upload_dir();
			$root    = ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) )
				? untrailingslashit( (string) $uploads['basedir'] )
				: untrailingslashit( $contentRoot ) . '/uploads';
		}

		return $root . '/' . self::SUBDIR . '-' . self::token();
	}

	/**
	 * Create the backup base directory and harden it. Returns true when the
	 * directory exists (and hardening was attempted) afterwards.
	 */
	public static function ensureDir(): bool {
		$dir = self::baseDir();

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::harden( $dir );

		return is_dir( $dir );
	}

	/**
	 * Write the deny/silence guard files into $dir.
	 *
	 * - index.php : "silence is golden" so a missing-index dir can't be listed.
	 * - .htaccess : Options -Indexes + legacy "Deny from all" + an Apache 2.4
	 *               mod_authz_core "Require all denied" block, each wrapped in
	 *               its own <IfModule> so a server lacking a module never 500s.
	 * - web.config: IIS deny-all.
	 *
	 * Direct writes (mirroring heirloom-seo's FileCache house pattern); the
	 * directory is plugin-owned so WP_Filesystem credentials are unnecessary.
	 */
	public static function harden( string $dir ): void {
		$dir = untrailingslashit( $dir );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, self::htaccessBody() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			@file_put_contents( $webconfig, self::webConfigBody() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Strict path-containment guard. Returns true only when $path resolves to a
	 * location strictly inside the backup base — defeating "../" traversal and
	 * symlinks that escape the base.
	 *
	 * For not-yet-existing paths (e.g. a backup file about to be written) the
	 * deepest existing ancestor is canonicalized and the non-existent remainder
	 * is appended, so the check still works before the file is created.
	 */
	public static function within( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}

		$base = realpath( self::baseDir() );
		if ( false === $base ) {
			// Base does not exist yet — nothing can legitimately be inside it.
			return false;
		}

		$resolved = self::canonicalize( $path );
		if ( null === $resolved ) {
			return false;
		}

		// Equality with the base itself is not "inside" it; require a child path.
		$needle = $base . DIRECTORY_SEPARATOR;

		return 0 === strncmp( $resolved . DIRECTORY_SEPARATOR, $needle, strlen( $needle ) )
			&& $resolved !== $base;
	}

	/**
	 * admin_init hook: recreate the directory and any missing hardening files.
	 * Cheap, idempotent, and safe to run on every admin request.
	 */
	public static function selfHeal(): void {
		$dir = self::baseDir();

		if ( ! is_dir( $dir ) ) {
			self::ensureDir();
		} elseif (
			! file_exists( $dir . '/index.php' )
			|| ! file_exists( $dir . '/.htaccess' )
			|| ! file_exists( $dir . '/web.config' )
		) {
			self::harden( $dir );
		}

		// Keep the measured exposure verdict fresh WITHOUT adding a loopback HTTP
		// request to this admin page load — schedule it as a one-off wp-cron event.
		self::maybeScheduleProbe();
	}

	/** Best-effort web-server identity from SERVER_SOFTWARE (lowercased). */
	public static function serverSoftware(): string {
		$raw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return strtolower( trim( $raw ) );
	}

	/**
	 * Whether the resolved backup base is likely directly web-reachable with no
	 * server-level protection. The per-directory deny files we write are honored by
	 * Apache (.htaccess), IIS (web.config), and LiteSpeed (which natively emulates
	 * .htaccess); nginx and other servers ignore them entirely, so on those the
	 * unguessable token is the only thing standing between the public web and the
	 * pristine originals. This returns true when we detect such a server (i.e. one
	 * that does NOT honor the deny files), so the operator gets the exposure notice.
	 */
	public static function isWebReachable(): bool {
		$server = self::serverSoftware();

		// Apache and IIS honor the deny files we write; treat them as protected.
		$honors_deny = ( false !== strpos( $server, 'apache' ) )
			|| ( false !== strpos( $server, 'iis' ) )
			|| ( false !== strpos( $server, 'litespeed' ) );

		return ! $honors_deny;
	}

	/**
	 * admin_notices hook: warn when the pristine backups are (or may be) directly
	 * web-reachable. The notice is DISMISSIBLE (the dismissal is persisted, keyed to
	 * the current base path) and self-clears once a measurement proves the folder is
	 * protected — see {@see shouldShowNotice()} / {@see probeExposure()}.
	 */
	public static function maybeNotice(): void {
		if ( ! self::onSettingsScreen() || ! current_user_can( 'manage_options' ) || ! self::shouldShowNotice() ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: web-server software string. */
			__( 'OG Watermark stored its pristine image backups inside the public web directory because your server (%s) does not honor the per-directory access rules the plugin writes. The folder name is randomized so it is not easily guessable, but for full protection add a server-level rule denying access to the og-watermark-originals folder, or move it above the web root.', 'og-watermark' ),
			self::serverSoftware() ?: __( 'unknown', 'og-watermark' )
		);

		printf(
			'<div class="notice notice-warning is-dismissible" data-ogwm-notice="backup-exposure"><p>%s</p><p><button type="button" class="button-link ogwm-recheck">%s</button></p></div>',
			esc_html( $message ),
			esc_html__( 'Re-check now — I have secured the folder', 'og-watermark' )
		);
	}

	/**
	 * admin_enqueue_scripts hook: load the tiny notice helper (persist-dismiss +
	 * re-check) ONLY on screens where the notice will actually render, and only for
	 * an operator who can act on it.
	 *
	 * @param string $hook Current admin page hook suffix (unused — the notice is global).
	 */
	public static function enqueueNoticeAssets( string $hook = '' ): void {
		if ( self::SETTINGS_HOOK !== $hook ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || ! self::shouldShowNotice() ) {
			return;
		}

		wp_enqueue_script( 'ogwm-notice', OGWM_URL . 'assets/js/notice.js', [], OGWM_VERSION, true );
		wp_localize_script(
			'ogwm-notice',
			'ogwmNotice',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				// Must match Admin\Ajax::NONCE_ACTION (the one shared admin nonce).
				'nonce'         => wp_create_nonce( 'ogwm_admin' ),
				'dismissAction' => 'ogwm_dismiss_backup_notice',
				'recheckAction' => 'ogwm_recheck_backup_exposure',
				'i18n'          => [
					'checking' => __( 'Re-checking…', 'og-watermark' ),
					'stillOpen' => __( 'Still reachable — the folder is not protected yet.', 'og-watermark' ),
				],
			]
		);
	}

	/**
	 * Whether the exposure notice should be shown right now: the base is (or may
	 * be) web-reachable AND the operator has not dismissed it for this base path.
	 */
	public static function shouldShowNotice(): bool {
		// A PROVEN exposure (a 200 that echoed our canary marker) is NOT dismissible:
		// the operator must not be able to one-click-hide a confirmed public exposure
		// of the pristine originals. It clears only when a re-measure proves it
		// protected. Only the conservative heuristic / 'unknown' notice is dismissible.
		if ( 'exposed' === self::exposureState() ) {
			return true;
		}

		return ! self::isNoticeDismissed() && self::exposureShowsNotice();
	}

	/**
	 * The exposure VERDICT, ignoring dismissal: true when the backups are exposed
	 * (or not yet measured on a server that ignores our deny files). A measurement
	 * of 'protected' is the ONLY thing that suppresses this — so we never silently
	 * hide a possible exposure, but a real remediation (nginx rule / relocation)
	 * does clear it once the next probe confirms it.
	 */
	public static function exposureShowsNotice(): bool {
		$state = self::exposureState();

		if ( 'protected' === $state ) {
			return false;
		}
		if ( 'exposed' === $state ) {
			return true;
		}

		// 'unknown' (never measured, or an inconclusive probe) → fall back to the
		// server-software heuristic, which is conservative (shows on nginx et al.).
		return self::isWebReachable();
	}

	/**
	 * The cached exposure verdict for the CURRENT base: 'exposed', 'protected', or
	 * 'unknown'. A stored verdict for a different base path is treated as unknown
	 * (the location moved, so the old measurement no longer applies).
	 */
	public static function exposureState(): string {
		$record = get_option( self::EXPOSURE_OPTION, [] );

		if ( ! is_array( $record ) || ! isset( $record['state'], $record['base'] ) ) {
			return 'unknown';
		}
		if ( self::baseDir() !== $record['base'] ) {
			return 'unknown';
		}

		$state = (string) $record['state'];

		return in_array( $state, [ 'exposed', 'protected', 'unknown' ], true ) ? $state : 'unknown';
	}

	/**
	 * Actively measure whether the backup base is directly fetchable over HTTP, by
	 * requesting a harmless canary file (containing only a random marker) at the
	 * base's public URL, and cache the verdict. Runs off the request path (wp-cron)
	 * or on demand (the "Re-check" button). Returns the new state.
	 *
	 * Verdict mapping (deliberately conservative):
	 *  - no mappable public URL (base is above the web root) → 'protected'.
	 *  - HTTP 200 whose body carries our marker                → 'exposed' (proven).
	 *  - HTTP 401 / 403 / 404                                  → 'protected' (blocked).
	 *  - anything else (network error, redirect, 5xx, 200 w/o marker) → 'unknown',
	 *    which falls back to the server-software heuristic rather than hiding a
	 *    possible exposure.
	 */
	public static function probeExposure(): string {
		$url = self::baseUrl();
		if ( null === $url ) {
			// No mappable public URL. A base under a KNOWN web-served root (uploads/
			// wp-content) whose URL merely could not be resolved is INCONCLUSIVE — fall
			// back to the conservative heuristic, do NOT claim protection. Only a base
			// under no web root (a genuine above-docroot relocation) is truly unreachable.
			return self::storeExposure( self::isUnderWebRoot() ? 'unknown' : 'protected' );
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return self::storeExposure( 'unknown' );
		}

		// Ensure the canary exists so the fetch has a concrete target. It holds only
		// a random marker, so serving it leaks nothing about the real backups.
		self::ensureDir();
		$marker = self::probeMarker();
		$canary = self::baseDir() . '/' . self::PROBE_FILE;
		if ( ! file_exists( $canary ) ) {
			@file_put_contents( $canary, $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		// The cache-buster is a THROWAWAY value, deliberately DISTINCT from the body
		// marker: a catch-all / WAF / custom-404 page that reflects the query string
		// into its body must not be able to satisfy the marker check and fake an
		// 'exposed' verdict. Only genuinely serving the canary file (whose body holds
		// the secret marker) can match.
		$probeUrl = add_query_arg( 'ogwm-cb', wp_generate_password( 8, false, false ), $url . '/' . self::PROBE_FILE );

		$response = wp_remote_get(
			$probeUrl,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'ogwm_probe_sslverify', true ),
				'headers'     => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return self::storeExposure( 'unknown' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 === $code && '' !== $marker && false !== strpos( $body, $marker ) ) {
			return self::storeExposure( 'exposed' );
		}

		if ( 401 === $code || 403 === $code || 404 === $code ) {
			// Confirmed blocked — the canary has served its purpose; keep the pristine
			// tree clean of probe artifacts (a later probe re-creates it if needed).
			self::deleteCanary();
			return self::storeExposure( 'protected' );
		}

		return self::storeExposure( 'unknown' );
	}

	/** Best-effort removal of the probe canary from the backup base. */
	private static function deleteCanary(): void {
		$canary = self::baseDir() . '/' . self::PROBE_FILE;
		if ( file_exists( $canary ) ) {
			@unlink( $canary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/** Whether the operator dismissed the notice for the CURRENT base path. */
	public static function isNoticeDismissed(): bool {
		$dismissed = get_option( self::DISMISS_OPTION, '' );

		return is_string( $dismissed ) && '' !== $dismissed && self::baseDir() === $dismissed;
	}

	/** Persist a dismissal, keyed to the current base path (re-shows if it moves). */
	public static function setNoticeDismissed(): void {
		update_option( self::DISMISS_OPTION, self::baseDir(), false );
	}

	/**
	 * Schedule a one-off exposure probe when the cached verdict is missing or stale
	 * for the current base, and none is already queued. Keeps loopback HTTP off the
	 * admin request path.
	 */
	private static function maybeScheduleProbe(): void {
		$record = get_option( self::EXPOSURE_OPTION, [] );
		$state  = is_array( $record ) && isset( $record['state'] ) ? (string) $record['state'] : '';

		// A conclusive verdict is trusted for 12h; an inconclusive 'unknown' (a
		// transient error / redirect / catch-all 200) for only 30m, so a host that
		// blipped on its first probe re-measures soon instead of nagging for 12h.
		$ttl   = ( 'exposed' === $state || 'protected' === $state ) ? self::EXPOSURE_TTL : self::UNKNOWN_TTL;
		$fresh = is_array( $record )
			&& isset( $record['base'], $record['time'] )
			&& self::baseDir() === $record['base']
			&& ( self::now() - (int) $record['time'] ) < $ttl;

		if ( $fresh ) {
			return;
		}

		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::PROBE_HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( self::now() + 15, self::PROBE_HOOK );
		}
	}

	/** Store an exposure verdict for the current base and return it unchanged. */
	private static function storeExposure( string $state ): string {
		update_option(
			self::EXPOSURE_OPTION,
			[
				'state' => $state,
				'base'  => self::baseDir(),
				'time'  => self::now(),
			],
			false
		);

		return $state;
	}

	/**
	 * Map the absolute backup base to its public URL, or null when it is not under
	 * any web-served root we can address (e.g. moved above the docroot) — in which
	 * case it is not fetchable and the exposure probe short-circuits to 'protected'.
	 */
	private static function baseUrl(): ?string {
		$base = self::baseDir();

		$contentDir = defined( 'WP_CONTENT_DIR' ) ? untrailingslashit( WP_CONTENT_DIR ) : untrailingslashit( ABSPATH . 'wp-content' );
		if ( '' !== $contentDir && 0 === strpos( $base, $contentDir . '/' ) ) {
			return untrailingslashit( content_url() ) . substr( $base, strlen( $contentDir ) );
		}

		$uploads = wp_upload_dir();
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) && ! empty( $uploads['baseurl'] ) ) {
			$upDir = untrailingslashit( (string) $uploads['basedir'] );
			if ( 0 === strpos( $base, $upDir . '/' ) ) {
				return untrailingslashit( (string) $uploads['baseurl'] ) . substr( $base, strlen( $upDir ) );
			}
		}

		return null;
	}

	/**
	 * Whether the base sits under a known web-served filesystem root (wp-content or
	 * the uploads basedir). Used to tell "URL unresolvable but web-rooted"
	 * (inconclusive) apart from "under no web root" (genuinely unreachable).
	 */
	private static function isUnderWebRoot(): bool {
		$base = self::baseDir();

		$contentDir = defined( 'WP_CONTENT_DIR' ) ? untrailingslashit( WP_CONTENT_DIR ) : untrailingslashit( ABSPATH . 'wp-content' );
		if ( '' !== $contentDir && 0 === strpos( $base, $contentDir . '/' ) ) {
			return true;
		}

		$uploads = wp_upload_dir();
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$upDir = untrailingslashit( (string) $uploads['basedir'] );
			if ( 0 === strpos( $base, $upDir . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/** Stable random marker embedded in the probe canary, persisted in an option. */
	private static function probeMarker(): string {
		$marker = get_option( self::PROBE_MARKER_OPTION, '' );
		if ( is_string( $marker ) && '' !== $marker ) {
			return $marker;
		}

		$marker = 'ogwm-' . wp_generate_password( 24, false, false );
		update_option( self::PROBE_MARKER_OPTION, $marker, false );

		return $marker;
	}

	/** Whether the current request is rendering the main OG Watermark settings screen. */
	private static function onSettingsScreen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && self::SETTINGS_HOOK === $screen->id;
	}

	/** Current Unix time; isolated so tests can reason about freshness windows. */
	private static function now(): int {
		return time();
	}

	// ---------------------------------------------------------------------
	// M3: backup create/verify/restore/stats live here.
	//
	// M3: create( int $attachment_id, string $source_path ): true|\WP_Error
	//        — transactional .partial → fsync → same-FS rename → dir fsync →
	//          MANDATORY full sha1 match; store _ogwm_backup_rel/_hash/_bytes.
	// M3: verify( int $attachment_id ): bool
	//        — sha1_file( backup ) === _ogwm_backup_hash; never re-derive from
	//          a served file on mismatch.
	// M3: restore( int $attachment_id ): true|\WP_Error
	//        — temp + atomic rename of the backup back to the on-disk original.
	// M3: stats(): array
	//        — total bytes / count / root path for the "Backup storage" card.
	//
	// Every M3 read/write path MUST be funneled through within() before any
	// filesystem operation, and resolve concrete file paths under baseDir()
	// using the year/month/<id>-<sanitized basename> layout from the SPEC.
	// ---------------------------------------------------------------------

	/**
	 * Stable random token for the in-uploads fallback base. Persisted in an
	 * option so the resolved fallback directory is reused across requests.
	 */
	private static function token(): string {
		$token = get_option( self::TOKEN_OPTION, '' );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}

		$token = wp_generate_password( 12, false, false );
		update_option( self::TOKEN_OPTION, $token, false );

		return $token;
	}

	/**
	 * Canonicalize an absolute path even when (part of) it does not yet exist:
	 * realpath the deepest existing ancestor and re-append the missing tail,
	 * collapsing any "." / ".." segments. Returns null if it cannot anchor to a
	 * real ancestor (so a fabricated parent can't sneak through).
	 */
	private static function canonicalize( string $path ): ?string {
		$real = realpath( $path );
		if ( false !== $real ) {
			return $real;
		}

		$prefix = $path;
		$tail   = [];

		// Walk up until we find an existing ancestor to anchor against.
		while ( '' !== $prefix && '.' !== $prefix ) {
			$parent = \dirname( $prefix );
			if ( $parent === $prefix ) {
				// Reached the filesystem root without finding a real ancestor.
				return null;
			}

			$realParent = realpath( $parent );
			if ( false !== $realParent ) {
				$tail[] = basename( $prefix );
				$resolved = $realParent;
				foreach ( array_reverse( $tail ) as $segment ) {
					if ( '..' === $segment ) {
						$resolved = \dirname( $resolved );
					} elseif ( '.' !== $segment && '' !== $segment ) {
						$resolved .= DIRECTORY_SEPARATOR . $segment;
					}
				}
				return $resolved;
			}

			$tail[]  = basename( $prefix );
			$prefix  = $parent;
		}

		return null;
	}

	/** Contents of the hardening .htaccess (deny all + no indexes). */
	private static function htaccessBody(): string {
		return <<<'HTACCESS'
# OG Watermark — pristine originals. Deny all direct access.
Options -Indexes

<IfModule mod_authz_core.c>
	<RequireAll>
		Require all denied
	</RequireAll>
</IfModule>

<IfModule !mod_authz_core.c>
	Order allow,deny
	Deny from all
</IfModule>
HTACCESS;
	}

	/** Contents of the hardening web.config (IIS deny all). */
	private static function webConfigBody(): string {
		return <<<'WEBCONFIG'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
	<system.webServer>
		<authorization>
			<deny users="*" />
		</authorization>
	</system.webServer>
</configuration>
WEBCONFIG;
	}
}
