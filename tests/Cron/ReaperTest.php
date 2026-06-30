<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Cron;

use OrchardGrove\OgWatermark\Cron\Reaper;
use OrchardGrove\OgWatermark\Tests\TestCase;

/**
 * Tmp/partial reaper tests.
 *
 * Driven through the injectable core {@see Reaper::reap()} so the age and lock
 * gates are deterministic, but the FILES are real temp files on disk and the
 * injected `unlink` performs a REAL deletion — so "removed" vs "left in place" is
 * observed by checking the filesystem afterwards, not a mock counter.
 *
 * The bar (never weakened): an OLD `.ogwm-<token>.tmp` and an OLD lone `.partial`
 * are removed; a YOUNG temp is left untouched (a job may be mid-rename); and a
 * temp whose attachment is currently LOCKED is left untouched (a resumed job owns
 * it). Age AND lock gating are each proven by a left-behind file.
 */
final class ReaperTest extends TestCase {

	/** Temp root holding this test's staging files. */
	private string $root = '';

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/ogwm-reaper-' . uniqid( '', true );
		mkdir( $this->root, 0777, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->root . '/*' ) ?: [] as $f ) {
			@unlink( $f );
		}
		if ( is_dir( $this->root ) ) {
			@rmdir( $this->root );
		}
		parent::tearDown();
	}

	// =====================================================================
	// Removal: an OLD served temp and an OLD lone .partial are reaped.
	// =====================================================================

	public function testRemovesAnOldServedTemp(): void {
		$now  = 1_000_000;
		$temp = $this->touch( 'photo.jpg.ogwm-abc123XYZ.tmp', $now - 7200 ); // 2h old

		$report = Reaper::reap( [ $temp ], $now, $this->deps() );

		$this->assertSame( 1, $report['removed'] );
		$this->assertFileDoesNotExist( $temp, 'an orphaned served temp older than the max runtime is reaped' );
	}

	public function testRemovesAnOldLonePartialBackup(): void {
		// A lone .partial is an incomplete backup ("no backup") — remove when old.
		$now     = 1_000_000;
		$partial = $this->touch( '42-photo.jpg.partial', $now - 7200 );

		$report = Reaper::reap( [ $partial ], $now, $this->deps() );

		$this->assertSame( 1, $report['removed'] );
		$this->assertFileDoesNotExist( $partial );
	}

	public function testRemovesAnOldPartial2StagingFile(): void {
		// The cross-filesystem staging sibling is reaped on the same terms.
		$now     = 1_000_000;
		$partial = $this->touch( '43-photo.jpg.partial2', $now - 7200 );

		$report = Reaper::reap( [ $partial ], $now, $this->deps() );

		$this->assertSame( 1, $report['removed'] );
		$this->assertFileDoesNotExist( $partial );
	}

	// =====================================================================
	// Age gate: a YOUNG temp is left untouched.
	// =====================================================================

	public function testLeavesAYoungServedTempUntouched(): void {
		$now  = 1_000_000;
		$temp = $this->touch( 'photo.jpg.ogwm-fresh0001.tmp', $now - 60 ); // 1 minute old

		$report = Reaper::reap( [ $temp ], $now, $this->deps() );

		$this->assertSame( 0, $report['removed'] );
		$this->assertSame( 1, $report['kept_young'] );
		$this->assertFileExists( $temp, 'a young temp may belong to an in-flight job and must be left' );
	}

	public function testLeavesAYoungPartialUntouched(): void {
		$now     = 1_000_000;
		$partial = $this->touch( '44-photo.jpg.partial', $now - 120 ); // 2 minutes old

		$report = Reaper::reap( [ $partial ], $now, $this->deps() );

		$this->assertSame( 0, $report['removed'] );
		$this->assertSame( 1, $report['kept_young'] );
		$this->assertFileExists( $partial );
	}

	public function testTempExactlyAtTheThresholdIsStillYoung(): void {
		// now - mtime === maxAge is NOT yet "older than"; the file is kept.
		$now  = 1_000_000;
		$temp = $this->touch( 'edge.jpg.ogwm-edgecase01.tmp', $now - 3600 );

		$report = Reaper::reap( [ $temp ], $now, $this->deps( [ 'maxAge' => 3600 ] ) );

		$this->assertSame( 1, $report['kept_young'] );
		$this->assertFileExists( $temp );
	}

	// =====================================================================
	// Lock gate: an OLD temp for a LOCKED attachment is left untouched.
	// =====================================================================

	public function testLeavesAnOldButLockedAttachmentsPartialUntouched(): void {
		// Old enough to pass the age gate, but its attachment (id 77, parsed from the
		// "77-" backup prefix) is currently locked — a resumed job owns it. Leave it.
		$now     = 1_000_000;
		$partial = $this->touch( '77-photo.jpg.partial', $now - 7200 );

		$report = Reaper::reap(
			[ $partial ],
			$now,
			$this->deps(
				[
					'isLocked' => static fn( int $id ): bool => 77 === $id,
				]
			)
		);

		$this->assertSame( 0, $report['removed'] );
		$this->assertSame( 1, $report['kept_locked'] );
		$this->assertFileExists( $partial, "a locked attachment's staging file must be left untouched" );
	}

	public function testReapsAnOldPartialOnceItsAttachmentIsUnlocked(): void {
		// Same file, but the attachment is no longer locked → it is reaped. This
		// proves the lock gate (not the age gate) is what spared it above.
		$now     = 1_000_000;
		$partial = $this->touch( '77-photo.jpg.partial', $now - 7200 );

		$report = Reaper::reap(
			[ $partial ],
			$now,
			$this->deps(
				[
					'isLocked' => static fn( int $id ): bool => false,
				]
			)
		);

		$this->assertSame( 1, $report['removed'] );
		$this->assertFileDoesNotExist( $partial );
	}

	// =====================================================================
	// Robustness: a vanished candidate is counted, not fataled.
	// =====================================================================

	public function testCountsAVanishedCandidateAsMissing(): void {
		$now     = 1_000_000;
		$missing = $this->root . '/never-existed.ogwm-zzz.tmp';

		$report = Reaper::reap( [ $missing ], $now, $this->deps() );

		$this->assertSame( 1, $report['missing'] );
		$this->assertSame( 0, $report['removed'] );
	}

	public function testMixedBatchReapsOnlyTheEligibleOldUnlockedFiles(): void {
		$now    = 1_000_000;
		$oldA   = $this->touch( 'a.jpg.ogwm-aaa.tmp', $now - 9000 ); // reap
		$young  = $this->touch( 'b.jpg.ogwm-bbb.tmp', $now - 30 );   // keep (young)
		$locked = $this->touch( '88-c.jpg.partial', $now - 9000 );   // keep (locked)
		$oldP   = $this->touch( '89-d.jpg.partial', $now - 9000 );   // reap

		$report = Reaper::reap(
			[ $oldA, $young, $locked, $oldP ],
			$now,
			$this->deps( [ 'isLocked' => static fn( int $id ): bool => 88 === $id ] )
		);

		$this->assertSame( 2, $report['removed'] );
		$this->assertSame( 1, $report['kept_young'] );
		$this->assertSame( 1, $report['kept_locked'] );
		$this->assertFileDoesNotExist( $oldA );
		$this->assertFileExists( $young );
		$this->assertFileExists( $locked );
		$this->assertFileDoesNotExist( $oldP );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Baseline dependencies: real mtime read from disk (so the touch()ed file's
	 * timestamp is honored), nothing locked, and a REAL unlink so removals actually
	 * delete the temp file.
	 *
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function deps( array $overrides = [] ): array {
		$base = [
			'maxAge'   => 3600,
			'mtimeOf'  => static function ( string $path ): int {
				if ( ! is_file( $path ) ) {
					return 0;
				}
				$m = filemtime( $path );
				return false === $m ? 0 : (int) $m;
			},
			'isLocked' => static fn( int $id ): bool => false,
			'unlink'   => static function ( string $path ): bool {
				if ( ! is_file( $path ) ) {
					return false;
				}
				unlink( $path );
				return ! file_exists( $path );
			},
		];

		return array_merge( $base, $overrides );
	}

	/** Create a real temp file with a controlled mtime; returns its absolute path. */
	private function touch( string $name, int $mtime ): string {
		$path = $this->root . '/' . $name;
		file_put_contents( $path, 'staging-bytes' );
		touch( $path, $mtime );
		return $path;
	}
}
