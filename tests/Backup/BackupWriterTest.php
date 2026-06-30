<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Backup;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Backup\BackupWriter;
use OrchardGrove\OgWatermark\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Real-filesystem tests for the transactional backup primitive.
 *
 * BackupWriter has NO WordPress dependency, so every test works against real
 * temp files in a fresh sys_get_temp_dir() subtree: copy() actually copies,
 * fsync actually flushes, and the mandatory full sha1 verification actually
 * hashes the bytes on disk.
 *
 * Process-isolation note (load-bearing): a handful of tests need to make a
 * faithful-LOOKING but corrupt/short copy to prove the verification gate. The
 * only way to force that deterministically is to stub the namespaced copy()
 * the class calls. Brain Monkey does that via Patchwork, which instruments the
 * function PROCESS-WIDE — so once stubbed it would poison every other class
 * that legitimately calls the real copy() through BackupWriter (e.g. the
 * sibling Manager tests). Those tests therefore run with
 * #[RunInSeparateProcess] + #[PreserveGlobalState(false)] so the instrumentation
 * lives and dies in a forked process and never leaks. The remaining tests do
 * NOT mock any internal fs function and run normally; their failure paths are
 * driven by genuine on-disk conditions (read-only dirs, dir-as-dest, etc.).
 */
final class BackupWriterTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/ogwm-bw-' . uniqid( '', true );
		mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->dir );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// copyVerified — happy path (real copy, no mocking)
	// -----------------------------------------------------------------

	public function testCopyVerifiedProducesByteForByteIdenticalDest(): void {
		// A non-trivial binary payload so a faithful copy is a meaningful claim.
		$payload = random_bytes( 50000 );
		$src     = $this->dir . '/src.bin';
		$dest    = $this->dir . '/dest.bin';
		file_put_contents( $src, $payload );

		$this->assertTrue( BackupWriter::copyVerified( $src, $dest ) );

		$this->assertFileExists( $dest );
		$this->assertSame( $payload, file_get_contents( $dest ), 'dest must equal src byte-for-byte' );
		$this->assertSame( sha1_file( $src ), sha1_file( $dest ) );
		$this->assertFileDoesNotExist( $dest . '.partial', 'no work file may survive a success' );
	}

	public function testCopyVerifiedCreatesMissingParentDirectories(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/2026/06/42-photo.jpg'; // Nested, not-yet-existing.
		file_put_contents( $src, 'pristine-original-bytes' );

		$this->assertDirectoryDoesNotExist( $this->dir . '/2026' );

		$this->assertTrue( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileExists( $dest );
		$this->assertSame( 'pristine-original-bytes', file_get_contents( $dest ) );
	}

	public function testCopyVerifiedOverwritesAnExistingDestAtomically(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, 'NEW-clean-master' );
		file_put_contents( $dest, 'older-and-different-bytes' );

		$this->assertTrue( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertSame( 'NEW-clean-master', file_get_contents( $dest ) );
	}

	public function testCopyVerifiedCleansUpAPreExistingStalePartial(): void {
		$src     = $this->dir . '/src.bin';
		$dest    = $this->dir . '/dest.bin';
		$partial = $dest . '.partial';
		file_put_contents( $src, 'good-bytes' );
		// A leftover .partial from an aborted earlier run must not poison this run.
		file_put_contents( $partial, 'garbage-from-a-crash' );

		$this->assertTrue( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertSame( 'good-bytes', file_get_contents( $dest ) );
		$this->assertFileDoesNotExist( $partial );
	}

	// -----------------------------------------------------------------
	// copyVerified — corruption / short-write detection (THE safety gate)
	// These force a faithful-looking-but-wrong copy via a stubbed copy(); they
	// run in a forked process so the Patchwork instrumentation never leaks.
	// -----------------------------------------------------------------

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedRejectsSameSizeCorruptionAndLeavesNothing(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, 'AAAAAAAAAA' ); // 10 bytes.

		// A SAME-SIZE but different payload: byte-size alone would pass, so only
		// the full sha1 check can catch it — exactly the failure mode the SPEC
		// forbids slipping into the pristine backup.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->alias(
			static fn( $s, $d ) => (bool) file_put_contents( $d, 'BBBBBBBBBB' )
		);

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest, 'a corrupt dest must never survive' );
		$this->assertFileDoesNotExist( $dest . '.partial', 'no partial may survive a failure' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedRejectsShortWriteAndLeavesNothing(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, str_repeat( 'X', 20000 ) );

		// A truncated copy (short write) must be rejected.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->alias(
			static fn( $s, $d ) => (bool) file_put_contents( $d, str_repeat( 'X', 19999 ) )
		);

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest );
		$this->assertFileDoesNotExist( $dest . '.partial' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedReturnsFalseWhenTheCopyItselfFails(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, 'pristine' );

		// copy() reports failure (and writes nothing) — abort cleanly.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->justReturn( false );

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest );
		$this->assertFileDoesNotExist( $dest . '.partial' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedRemovesAPriorGoodDestWhenTheNewWriteFails(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, 'incoming-bytes' );
		file_put_contents( $dest, 'previous-backup' );

		// The new write is corrupt; the prior dest must also be cleared so the
		// caller never sees a stale-but-present backup it might wrongly trust.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->alias(
			static fn( $s, $d ) => (bool) file_put_contents( $d, 'corrupt' )
		);

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest );
		$this->assertFileDoesNotExist( $dest . '.partial' );
	}

	// -----------------------------------------------------------------
	// copyVerified — cross-filesystem commit branch. Forced by stubbing the
	// namespaced sameFilesystem() to false so the verified copy-with-rollback
	// path (stream a sibling temp → fsync → verify → atomic swap) is exercised.
	// Process-isolated because the stub is Patchwork-instrumented process-wide.
	// -----------------------------------------------------------------

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedCrossFilesystemProducesByteForByteDest(): void {
		$payload = random_bytes( 70000 );
		$src      = $this->dir . '/src.bin';
		$dest     = $this->dir . '/dest.bin';
		file_put_contents( $src, $payload );

		// Force the cross-FS (non-rename) commit branch.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\stat' )->justReturn( false );

		$this->assertTrue( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileExists( $dest );
		$this->assertSame( $payload, file_get_contents( $dest ), 'cross-FS dest must equal src byte-for-byte' );
		$this->assertSame( sha1_file( $src ), sha1_file( $dest ) );
		$this->assertFileDoesNotExist( $dest . '.partial', 'no work file may survive a cross-FS success' );
		$this->assertFileDoesNotExist( $dest . '.partial2', 'no staging file may survive a cross-FS success' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedCrossFilesystemShortWriteLeavesNothing(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, str_repeat( 'Z', 30000 ) );

		// Force the cross-FS branch...
		Functions\when( 'OrchardGrove\OgWatermark\Backup\stat' )->justReturn( false );
		// ...and make the stream write short by one byte on every fwrite call, so
		// streamCopy() must detect the short write and abort.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\fwrite' )->alias(
			static function ( $handle, $data ) {
				$short = substr( (string) $data, 0, max( 0, strlen( (string) $data ) - 1 ) );
				return fwrite( $handle, $short );
			}
		);

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest, 'a torn cross-FS write must never persist' );
		$this->assertFileDoesNotExist( $dest . '.partial' );
		$this->assertFileDoesNotExist( $dest . '.partial2' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testCopyVerifiedCrossFilesystemPreservesPriorGoodDestOnFailure(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest.bin';
		file_put_contents( $src, str_repeat( 'N', 40000 ) );
		// A pre-existing, valid backup that must SURVIVE a failed re-create on the
		// cross-FS path — the asymmetry the writer now closes (no unlink-before-proof).
		file_put_contents( $dest, 'PRIOR-VALID-BACKUP-BYTES' );

		Functions\when( 'OrchardGrove\OgWatermark\Backup\stat' )->justReturn( false );
		// Corrupt the staged stream copy so verification of the stage fails before
		// any swap is attempted; the live dest must be left untouched.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\fwrite' )->alias(
			static function ( $handle, $data ) {
				return fwrite( $handle, str_repeat( 'x', strlen( (string) $data ) ) );
			}
		);

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileExists( $dest, 'a prior good dest must survive a failed cross-FS re-create' );
		$this->assertSame( 'PRIOR-VALID-BACKUP-BYTES', file_get_contents( $dest ) );
		$this->assertFileDoesNotExist( $dest . '.partial' );
		$this->assertFileDoesNotExist( $dest . '.partial2' );
	}

	// -----------------------------------------------------------------
	// copyVerified — commit failure driven by real on-disk conditions
	// (no mocking, so these run in the shared process safely)
	// -----------------------------------------------------------------

	public function testCopyVerifiedReturnsFalseWhenTheRenameCannotComplete(): void {
		$src  = $this->dir . '/src.bin';
		$dest = $this->dir . '/dest'; // Will be a non-empty directory below.
		file_put_contents( $src, 'pristine-bytes' );

		// A file cannot be renamed over a non-empty directory, so the same-FS
		// commit rename fails for real. The verified partial we could not promote
		// must be cleaned up rather than stranded.
		mkdir( $dest, 0777, true );
		file_put_contents( $dest . '/occupied', 'child' );

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );
		$this->assertFileDoesNotExist( $dest . '.partial', 'no partial may be stranded on a rename failure' );
		$this->assertDirectoryExists( $dest, 'a pre-existing dest dir is not the writer’s to delete' );
	}

	// -----------------------------------------------------------------
	// copyVerified — bad inputs
	// -----------------------------------------------------------------

	public function testCopyVerifiedReturnsFalseForMissingSource(): void {
		$dest = $this->dir . '/dest.bin';
		$this->assertFalse( BackupWriter::copyVerified( $this->dir . '/nope.bin', $dest ) );
		$this->assertFileDoesNotExist( $dest );
	}

	public function testCopyVerifiedReturnsFalseForEmptyPaths(): void {
		$this->assertFalse( BackupWriter::copyVerified( '', $this->dir . '/d.bin' ) );
		$this->assertFalse( BackupWriter::copyVerified( $this->dir . '/s.bin', '' ) );
	}

	public function testCopyVerifiedReturnsFalseWhenSourceIsADirectory(): void {
		$sub = $this->dir . '/asdir';
		mkdir( $sub );
		$this->assertFalse( BackupWriter::copyVerified( $sub, $this->dir . '/d.bin' ) );
	}

	public function testCopyVerifiedReturnsFalseWhenParentCannotBeCreated(): void {
		$src = $this->dir . '/src.bin';
		file_put_contents( $src, 'bytes' );

		// A read-only ancestor prevents creating the dest's parent directory.
		$readonly = $this->dir . '/locked';
		mkdir( $readonly, 0500 );
		$dest = $readonly . '/child/dest.bin';

		$this->assertFalse( BackupWriter::copyVerified( $src, $dest ) );

		// Restore perms so tearDown can clean up.
		chmod( $readonly, 0700 );
	}

	// -----------------------------------------------------------------
	// sha1Of
	// -----------------------------------------------------------------

	public function testSha1OfMatchesNativeForRealFile(): void {
		$file = $this->dir . '/f.bin';
		file_put_contents( $file, random_bytes( 4096 ) );
		$this->assertSame( sha1_file( $file ), BackupWriter::sha1Of( $file ) );
	}

	public function testSha1OfReturnsNullForMissingFile(): void {
		$this->assertNull( BackupWriter::sha1Of( $this->dir . '/missing.bin' ) );
	}

	public function testSha1OfReturnsNullForEmptyPathAndDirectory(): void {
		$this->assertNull( BackupWriter::sha1Of( '' ) );
		$this->assertNull( BackupWriter::sha1Of( $this->dir ) );
	}

	// -----------------------------------------------------------------
	// sameFilesystem
	// -----------------------------------------------------------------

	public function testSameFilesystemTrueForTwoPathsInSameDir(): void {
		$a = $this->dir . '/a.bin';
		$b = $this->dir . '/b.bin';
		file_put_contents( $a, 'a' );
		file_put_contents( $b, 'b' );
		$this->assertTrue( BackupWriter::sameFilesystem( $a, $b ) );
	}

	public function testSameFilesystemTrueForADirAndAFileInIt(): void {
		$a = $this->dir . '/a.bin';
		file_put_contents( $a, 'a' );
		$this->assertTrue( BackupWriter::sameFilesystem( $a, $this->dir ) );
	}

	public function testSameFilesystemTrueForNotYetExistingTargetUnderRealDir(): void {
		// A backup-to-be-written path resolves via its nearest existing ancestor.
		$this->assertTrue(
			BackupWriter::sameFilesystem( $this->dir . '/future/child.bin', $this->dir )
		);
	}

	public function testSameFilesystemFalseWhenAPathCannotBeResolved(): void {
		// An empty path has no real ancestor → conservatively not "same FS"
		// (so the caller takes the safe verified-copy branch).
		$this->assertFalse( BackupWriter::sameFilesystem( '', $this->dir ) );
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
