<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Support;

use OrchardGrove\OgWatermark\Support\Meta;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Meta is pure (constants + array helpers, no DB), so this extends PHPUnit's
 * TestCase directly. The reflection-sync tests guard against silent drift: a
 * new key/status constant that someone forgets to add to keys()/statuses().
 */
final class MetaTest extends TestCase {

	public function testKeysAreAllUnderscoreOgwmPrefixed(): void {
		foreach ( Meta::keys() as $key ) {
			$this->assertStringStartsWith( '_ogwm_', $key, "$key is not _ogwm_ prefixed" );
		}
	}

	public function testKeysContainTheCanonicalNine(): void {
		$expected = [
			'_ogwm_enabled',
			'_ogwm_status',
			'_ogwm_signature',
			'_ogwm_backup_rel',
			'_ogwm_backup_hash',
			'_ogwm_backup_bytes',
			'_ogwm_sizes_done',
			'_ogwm_applied_gmt',
			'_ogwm_last_error',
		];

		// Order-independent set comparison.
		sort( $expected );
		$actual = Meta::keys();
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	public function testKeysHaveNoDuplicates(): void {
		$keys = Meta::keys();
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );
	}

	public function testStatusesContainTheCanonicalSet(): void {
		$expected = [
			'none',
			'queued',
			'watermarked',
			'failed',
			'restored',
			'skipped_offloaded',
			'backup_missing',
			'too_large',
		];

		sort( $expected );
		$actual = Meta::statuses();
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * keys() must enumerate every public _ogwm_ key constant (not the STATUS_*
	 * value constants), so adding a new meta key without listing it fails here.
	 */
	public function testKeysStayInSyncWithKeyConstants(): void {
		$reflection = new ReflectionClass( Meta::class );
		$keyConsts  = [];
		foreach ( $reflection->getConstants() as $value ) {
			// Meta keys are the constants whose VALUE is _ogwm_-prefixed; the
			// STATUS_* value constants ('none', 'queued', …) are not.
			if ( is_string( $value ) && str_starts_with( $value, '_ogwm_' ) ) {
				$keyConsts[] = $value;
			}
		}

		sort( $keyConsts );
		$keys = Meta::keys();
		sort( $keys );

		$this->assertSame( $keyConsts, $keys );
	}

	/**
	 * statuses() must enumerate every STATUS_* value constant.
	 */
	public function testStatusesStayInSyncWithStatusConstants(): void {
		$reflection   = new ReflectionClass( Meta::class );
		$statusConsts = [];
		foreach ( $reflection->getConstants() as $name => $value ) {
			// Status VALUE constants are STATUS_* (e.g. STATUS_NONE) — exclude the
			// STATUS key constant itself, whose value is the _ogwm_status meta key.
			if ( str_starts_with( $name, 'STATUS_' ) ) {
				$statusConsts[] = $value;
			}
		}

		sort( $statusConsts );
		$statuses = Meta::statuses();
		sort( $statuses );

		$this->assertSame( $statusConsts, $statuses );
	}
}
