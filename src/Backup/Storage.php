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
			return;
		}

		if (
			! file_exists( $dir . '/index.php' )
			|| ! file_exists( $dir . '/.htaccess' )
			|| ! file_exists( $dir . '/web.config' )
		) {
			self::harden( $dir );
		}
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
	 * admin_notices hook: warn when the resolved backup base is web-reachable on
	 * a server that ignores our deny files (e.g. nginx). The unguessable token
	 * still obfuscates the path, but operators should ideally add a server-level
	 * deny rule or move backups outside the docroot.
	 */
	public static function maybeNotice(): void {
		if ( ! self::isWebReachable() ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: web-server software string. */
			__( 'OG Watermark stored its pristine image backups inside the public web directory because your server (%s) does not honor the per-directory access rules the plugin writes. The folder name is randomized so it is not easily guessable, but for full protection add a server-level rule denying access to the og-watermark-originals folder, or move it above the web root.', 'og-watermark' ),
			esc_html( self::serverSoftware() ?: __( 'unknown', 'og-watermark' ) )
		);

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $message )
		);
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
