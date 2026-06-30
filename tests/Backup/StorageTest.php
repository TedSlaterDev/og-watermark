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
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploadsDir ] );
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( $dir ): bool {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );

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
