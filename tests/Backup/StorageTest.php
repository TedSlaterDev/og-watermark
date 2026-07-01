<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Backup;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

final class StorageTest extends TestCase {

	private string $contentDir = '';
	private string $uploadsDir = '';

	/** @var array<string,mixed> In-memory options store, so option round-trips are consistent. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		// Real temp roots so harden()/within() can touch the filesystem.
		$root             = sys_get_temp_dir() . '/ogwm-test-' . uniqid( '', true );
		$this->contentDir = $root . '/wp-content';
		$this->uploadsDir = $this->contentDir . '/uploads';
		mkdir( $this->uploadsDir, 0777, true );

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', $this->contentDir );
		}

		// Common WP helper stubs Storage relies on.
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_generate_password' )->justReturn( 'tok123456789' );
		Functions\when( 'wp_upload_dir' )->justReturn(
			[
				'basedir' => $this->uploadsDir,
				'baseurl' => 'https://example.test/wp-content/uploads',
			]
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( $dir ): bool {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		$this->options = [];
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);

		// WP helpers the exposure-probe / notice paths touch. Tests override the
		// per-probe ones (wp_remote_* / is_wp_error) as needed.
		Functions\when( 'content_url' )->justReturn( 'https://example.test/wp-content' );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'add_query_arg' )->alias( static fn( $k, $v, $url ) => $url . '?' . $k . '=' . rawurlencode( (string) $v ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );

		self::resetBaseCache();
	}

	protected function tearDown(): void {
		self::resetBaseCache();
		$this->rrmdir( dirname( $this->contentDir ) );
		parent::tearDown();
	}

	public function testBaseDirPrefersContentDirWhenWritable(): void {
		// WP_CONTENT_DIR is a freshly-made (writable) temp dir. The unguessable
		// token suffix is ALWAYS appended (even in wp-content) so the dir is not
		// fetchable at a guessable URL on servers that ignore .htaccess (nginx).
		$this->assertSame(
			$this->contentDir . '/og-watermark-originals-tok123456789',
			Storage::baseDir()
		);
	}

	public function testBaseDirReusesPersistedLocation(): void {
		// Once a base is persisted in the ogwm_backup_base option and exists on
		// disk, it is reused verbatim regardless of WP_CONTENT_DIR writability.
		$persisted = $this->contentDir . '/og-watermark-originals-persisted';
		mkdir( $persisted, 0777, true );
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => 'ogwm_backup_base' === $name ? $persisted : $default
		);
		self::resetBaseCache();

		$this->assertSame( $persisted, Storage::baseDir() );
	}

	public function testIsWebReachableTrueOnNginx(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
		$this->assertTrue( Storage::isWebReachable() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function testIsWebReachableFalseOnApache(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.57 (Unix)';
		$this->assertFalse( Storage::isWebReachable() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function testEnsureDirCreatesAndHardensTheBase(): void {
		$this->assertTrue( Storage::ensureDir() );

		$dir = Storage::baseDir();
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/web.config' );
	}

	public function testHardenWritesExpectedFilesWithDenyDirectives(): void {
		$dir = $this->contentDir . '/manual-harden';
		mkdir( $dir, 0777, true );

		Storage::harden( $dir );

		$this->assertStringContainsString( 'Silence is golden', (string) file_get_contents( $dir . '/index.php' ) );

		$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Options -Indexes', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );
		$this->assertStringContainsString( 'Require all denied', $htaccess );

		$this->assertStringContainsString( 'deny users="*"', (string) file_get_contents( $dir . '/web.config' ) );
	}

	public function testWithinAcceptsPathInsideBase(): void {
		Storage::ensureDir();
		$base = Storage::baseDir();

		// A not-yet-existing child path must validate (backup-to-be-written case).
		$this->assertTrue( Storage::within( $base . '/2026/06/42-photo.jpg' ) );
	}

	public function testWithinRejectsTraversalEscape(): void {
		Storage::ensureDir();
		$base = Storage::baseDir();

		$this->assertFalse( Storage::within( $base . '/../secret.txt' ) );
		$this->assertFalse( Storage::within( $base . '/2026/../../etc/passwd' ) );
	}

	public function testWithinRejectsAbsolutePathOutsideBase(): void {
		Storage::ensureDir();

		$this->assertFalse( Storage::within( '/etc/passwd' ) );
		$this->assertFalse( Storage::within( $this->uploadsDir . '/elsewhere.jpg' ) );
	}

	public function testWithinRejectsTheBaseItself(): void {
		Storage::ensureDir();
		$this->assertFalse( Storage::within( Storage::baseDir() ) );
	}

	public function testSelfHealRecreatesMissingHardeningFiles(): void {
		Storage::ensureDir();
		$dir = Storage::baseDir();

		unlink( $dir . '/.htaccess' );
		$this->assertFileDoesNotExist( $dir . '/.htaccess' );

		Storage::selfHeal();
		$this->assertFileExists( $dir . '/.htaccess' );
	}

	public function testServerSoftwareReturnsLowercasedValue(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.57 (Unix)';
		$this->assertSame( 'apache/2.4.57 (unix)', Storage::serverSoftware() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	// =====================================================================
	// Exposure measurement + dismissible/self-clearing notice (v1.2.0).
	// =====================================================================

	public function testExposureStateIsUnknownWithoutAMeasurement(): void {
		$this->assertSame( 'unknown', Storage::exposureState() );
	}

	public function testExposureStateReadsTheVerdictForTheCurrentBase(): void {
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'exposed',
			'base'  => Storage::baseDir(),
			'time'  => time(),
		];
		$this->assertSame( 'exposed', Storage::exposureState() );
	}

	public function testExposureStateIgnoresAVerdictForADifferentBase(): void {
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'protected',
			'base'  => '/some/other/path',
			'time'  => time(),
		];
		$this->assertSame( 'unknown', Storage::exposureState(), 'a verdict for a moved base must not apply' );
	}

	public function testExposureShowsNoticeFallsBackToHeuristicWhenUnknown(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
		$this->assertTrue( Storage::exposureShowsNotice(), 'unknown + nginx → show (conservative)' );

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.57 (Unix)';
		$this->assertFalse( Storage::exposureShowsNotice(), 'unknown + apache → hidden' );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function testMeasuredProtectedSuppressesNoticeEvenOnNginx(): void {
		$_SERVER['SERVER_SOFTWARE']             = 'nginx/1.25.3';
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'protected',
			'base'  => Storage::baseDir(),
			'time'  => time(),
		];
		$this->assertFalse( Storage::exposureShowsNotice(), 'a proven-protected measurement wins over the nginx heuristic' );
		$this->assertFalse( Storage::shouldShowNotice() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function testMeasuredExposedShowsNoticeEvenOnApache(): void {
		$_SERVER['SERVER_SOFTWARE']             = 'Apache/2.4.57 (Unix)';
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'exposed',
			'base'  => Storage::baseDir(),
			'time'  => time(),
		];
		$this->assertTrue( Storage::exposureShowsNotice(), 'a proven exposure wins over the apache heuristic' );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function testDismissalIsKeyedToTheCurrentBase(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25.3';
		$this->assertTrue( Storage::shouldShowNotice(), 'nginx + not dismissed → shows' );

		Storage::setNoticeDismissed();
		$this->assertTrue( Storage::isNoticeDismissed() );
		$this->assertFalse( Storage::shouldShowNotice(), 'a dismissal for this base hides the notice' );

		// A dismissal recorded for a DIFFERENT base must not suppress the current one.
		$this->options['ogwm_backup_notice_dismissed'] = '/some/old/base';
		$this->assertFalse( Storage::isNoticeDismissed() );
		$this->assertTrue( Storage::shouldShowNotice() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	public function testProbeMarksExposedWhenTheCanaryIsServed(): void {
		$this->pinWebServedBase();
		$marker = 'ogwm-tok123456789'; // 'ogwm-' + the stubbed wp_generate_password.
		Functions\when( 'wp_remote_get' )->justReturn( [ 'ok' => true ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( "prefix {$marker} suffix" );

		$this->assertSame( 'exposed', Storage::probeExposure() );
		$this->assertSame( 'exposed', Storage::exposureState(), 'the verdict is cached for the current base' );
	}

	public function testProbeMarksProtectedOnABlockedResponse(): void {
		$this->pinWebServedBase();
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Forbidden' );

		$this->assertSame( 'protected', Storage::probeExposure() );
	}

	public function testProbeIsUnknownOnA200WithoutTheMarker(): void {
		// A generic 200 (e.g. a catch-all page) that does NOT echo our marker is
		// inconclusive — it must NOT be read as exposure, and must NOT hide it either.
		$this->pinWebServedBase();
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'some unrelated body' );

		$this->assertSame( 'unknown', Storage::probeExposure() );
	}

	public function testProbeIsUnknownOnATransportError(): void {
		$this->pinWebServedBase();
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertSame( 'unknown', Storage::probeExposure() );
	}

	public function testNoticeAssetsAreScopedToTheSettingsScreen(): void {
		// Off the OG Watermark settings screen the notice helper is NEVER enqueued —
		// the exposure warning no longer loads on every admin page. (The hook check
		// short-circuits before any capability/exposure work.)
		Functions\expect( 'wp_enqueue_script' )->never();

		Storage::enqueueNoticeAssets( 'edit.php' );
		Storage::enqueueNoticeAssets( 'upload.php' );
		Storage::enqueueNoticeAssets( 'toplevel_page_something-else' );
		Storage::enqueueNoticeAssets( '' );

		$this->assertTrue( true );
	}

	public function testProbeShortCircuitsToProtectedWhenBaseIsNotWebServed(): void {
		// A base OUTSIDE wp-content and uploads has no mappable public URL, so it is
		// not fetchable: probeExposure() returns 'protected' WITHOUT any HTTP request.
		// wp_remote_get is intentionally NOT stubbed here — if it were reached the
		// call would fail, proving the short-circuit skipped the probe entirely.
		$outside = sys_get_temp_dir() . '/ogwm-outside-' . uniqid( '', true );
		mkdir( $outside, 0777, true );
		$this->options['ogwm_backup_base'] = $outside;
		self::resetBaseCache();

		$this->assertSame( 'protected', Storage::probeExposure() );
		$this->rrmdir( $outside );
	}

	public function testProvenExposedNoticeIsNotDismissible(): void {
		// A dismissal must NOT hide a PROVEN exposure (a 200 that echoed our marker).
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'exposed',
			'base'  => Storage::baseDir(),
			'time'  => time(),
		];
		Storage::setNoticeDismissed();
		$this->assertTrue( Storage::isNoticeDismissed() );
		$this->assertTrue( Storage::shouldShowNotice(), 'a confirmed exposure overrides dismissal' );
	}

	public function testBaseUnderWebRootWithoutAUrlIsInconclusiveNotProtected(): void {
		// Base physically under the uploads root, but wp_upload_dir() reports no
		// baseurl → the public URL is unresolvable. That is INCONCLUSIVE (heuristic),
		// NOT a proof of protection. wp_remote_get is intentionally NOT stubbed:
		// reaching it would fail, proving we short-circuited to 'unknown'.
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploadsDir ] );
		$base = $this->uploadsDir . '/og-watermark-originals-nourl';
		mkdir( $base, 0777, true );
		$this->options['ogwm_backup_base'] = $base;
		self::resetBaseCache();

		$this->assertSame( 'unknown', Storage::probeExposure() );
	}

	public function testProbeRemovesTheCanaryWhenProtected(): void {
		$this->pinWebServedBase();
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Forbidden' );

		$this->assertSame( 'protected', Storage::probeExposure() );
		$this->assertFileDoesNotExist( Storage::baseDir() . '/og-watermark-access-probe.txt' );
	}

	public function testStaleUnknownVerdictReschedulesButFreshConclusiveDoesNot(): void {
		$scheduled = false;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function () use ( &$scheduled ): bool {
				$scheduled = true;
				return true;
			}
		);

		// A stale 'unknown' (older than the 30m unknown window) must re-probe.
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'unknown',
			'base'  => Storage::baseDir(),
			'time'  => time() - 3600,
		];
		Storage::selfHeal();
		$this->assertTrue( $scheduled, 'a stale unknown verdict must reschedule a probe' );

		// A fresh conclusive 'protected' (well within the 12h window) must NOT.
		$scheduled                             = false;
		$this->options['ogwm_backup_exposure'] = [
			'state' => 'protected',
			'base'  => Storage::baseDir(),
			'time'  => time(),
		];
		Storage::selfHeal();
		$this->assertFalse( $scheduled, 'a fresh conclusive verdict must not reschedule' );
	}

	/**
	 * Pin the backup base to a real directory under the (URL-mappable) uploads
	 * root and reset the cache, so baseUrl() resolves and the HTTP probe path runs
	 * deterministically regardless of the define-once WP_CONTENT_DIR quirk.
	 */
	private function pinWebServedBase(): string {
		$base = $this->uploadsDir . '/og-watermark-originals-pinned';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}
		$this->options['ogwm_backup_base'] = $base;
		self::resetBaseCache();

		return $base;
	}

	/** Reset the private static base cache between tests. */
	private static function resetBaseCache(): void {
		// getProperty is accessible without setAccessible() since PHP 8.1
		// (which is also deprecated in 8.5), keeping the suite warning-clean.
		$prop = ( new ReflectionClass( Storage::class ) )->getProperty( 'base' );
		$prop->setValue( null, null );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
