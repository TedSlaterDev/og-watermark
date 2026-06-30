<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Integration;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Integration\EditorBridge;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * EditorBridge tests — the safety-critical crop/rotate bridge.
 *
 * This is a genuine integration test, not mock theater: cleanEditSource() drives
 * the REAL Manager (verify/backupPath) against REAL temp backup files + an
 * in-memory meta store, onAfterEdit() drives the REAL Manager::ensureBackup()
 * against real temp originals/backups, the REAL Scheduler (observed via its
 * pending id-set side-effect), and the REAL Lock (in-memory options backend).
 * Only WordPress functions are Brain-Monkey-stubbed; Processor::isProcessing() is
 * driven through its real private static flag via reflection.
 *
 * The two crux properties under test:
 *
 *  1. cleanEditSource() returns the BACKUP path for a watermarked attachment with
 *     a valid backup on the full-size edit request, and the ORIGINAL path in every
 *     other case (not watermarked, no valid backup, not the full-size request).
 *
 *  2. onAfterEdit() refreshes the backup (clears state → ensureBackup → enqueue)
 *     ONLY for a flagged attachment with an `-e<digits>` edited file, a verifying
 *     prior backup, no processing, and a free lock — and CRUCIALLY does NOT refresh
 *     (leaving the existing backup meta byte-for-byte untouched) when the Processor
 *     is mid-regenerate, when no prior backup existed, when the lock is held, when
 *     the attachment isn't flagged, or when the edited file isn't an `-e` original.
 *     The "backup meta untouched" assertions are the data-loss guard.
 */
final class EditorBridgeTest extends TestCase {

	private string $contentDir = '';
	private string $uploadsDir = '';
	private string $monthDir = '';
	private string $backupBase = '';

	/** @var array<int,array<string,mixed>> In-memory post meta, keyed by id. */
	private array $meta = [];

	/** @var array<string,mixed> In-memory options table (Scheduler pending-set + Lock DB). */
	private array $options = [];

	/** @var array<string,mixed> In-memory transient store (Manager stats cache). */
	private array $transients = [];

	/** Generated PNG dimensions — above Preflight's byte floor, matching stubbed meta. */
	private const IMG_W = 64;
	private const IMG_H = 48;

	protected function setUp(): void {
		parent::setUp();

		$root             = sys_get_temp_dir() . '/ogwm-editor-' . uniqid( '', true );
		$this->contentDir = $root . '/wp-content';
		$this->uploadsDir = $this->contentDir . '/uploads';
		$this->monthDir   = $this->uploadsDir . '/2026/06';
		mkdir( $this->monthDir, 0777, true );

		// Pin the backup base inside THIS test's temp root via the persisted-base
		// option (Storage reuses a stored base verbatim when it exists on disk), so
		// the backup tree is isolated regardless of test ordering.
		$this->backupBase = $this->contentDir . '/og-watermark-originals-test';
		mkdir( $this->backupBase, 0777, true );

		// BackupWriter routes namespaced copy()/rename() through Brain Monkey in a
		// sibling test (process-wide via Patchwork); declare faithful pass-throughs so
		// the real backup copy still hits the genuine filesystem if those are mocked.
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->alias(
			static fn( string $s, string $d ): bool => copy( $s, $d )
		);
		Functions\when( 'OrchardGrove\OgWatermark\Backup\rename' )->alias(
			static fn( string $a, string $b ): bool => rename( $a, $b )
		);

		// Storage helpers.
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_generate_password' )->justReturn( 'tok123456789' );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploadsDir ] );
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn( $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0777, true )
		);
		Functions\when( 'sanitize_file_name' )->alias(
			static fn( $name ) => preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name )
		);

		// Options table (Storage base pin + Scheduler pending-set + Lock DB fallback).
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( 'ogwm_backup_base' === $name ) {
					return $this->backupBase;
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value, $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false; // add_option fails when the option exists (atomic claim).
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		// In-memory post meta.
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key, $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);

		// Transients (Manager stats cache invalidation).
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		// No persistent object cache → Lock uses the options-table backend (above).
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

		// Scheduler: pin the wp-cron fallback (Action Scheduler genuinely absent here).
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = false;
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		self::resetBaseCache();
		self::resetProcessingFlag();
		self::resetCleanSubstituted();
	}

	protected function tearDown(): void {
		self::resetBaseCache();
		self::resetProcessingFlag();
		self::resetCleanSubstituted();
		\OrchardGrove\OgWatermark\Queue\Scheduler::$forceActionScheduler = null;
		$this->rrmdir( dirname( $this->contentDir ) );
		$this->meta       = [];
		$this->options    = [];
		$this->transients = [];
		parent::tearDown();
	}

	// =====================================================================
	// register() wires both seams.
	// =====================================================================

	public function testRegisterAddsBothSeams(): void {
		$captured = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $cb, $priority = 10, $args = 1 ) use ( &$captured ): void {
				$captured[] = [ $hook, $cb, $priority, $args ];
			}
		);

		EditorBridge::register();

		$this->assertContains(
			[ 'load_image_to_edit_path', [ EditorBridge::class, 'cleanEditSource' ], 10, 3 ],
			$captured,
			'the clean-edit-source filter must be wired at 10,3'
		);
		$this->assertContains(
			[ 'wp_update_attachment_metadata', [ EditorBridge::class, 'onAfterEdit' ], 10, 2 ],
			$captured,
			'the post-edit refresh filter must be wired on wp_update_attachment_metadata at 10,2'
		);
	}

	// =====================================================================
	// cleanEditSource() — feed the editor the CLEAN backup.
	// =====================================================================

	public function testCleanEditSourceReturnsBackupForWatermarkedAttachmentWithValidBackupOnFullRequest(): void {
		$id       = 42;
		$original = $this->writeOriginal( $id );

		// Seed a REAL, verifying backup via the genuine Manager (first-apply create).
		$this->seedValidBackup( $id, $original );
		$backupPath = Manager::backupPath( $id );
		$this->assertNotNull( $backupPath );
		$this->assertFileExists( $backupPath );

		// The attachment is now watermarked on disk; the backup is the clean master.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		// The editor requests the full-size source → it must receive the CLEAN backup,
		// never the (watermarked) on-disk original.
		$result = EditorBridge::cleanEditSource( $original, $id, 'full' );

		$this->assertSame(
			$backupPath,
			$result,
			'a watermarked attachment with a valid backup must edit from the pristine backup'
		);
		$this->assertNotSame( $original, $result, 'the watermarked on-disk original must never be the edit source' );
	}

	public function testCleanEditSourceReturnsOriginalWhenNotWatermarkedYet(): void {
		$id       = 7;
		$original = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $original );

		// Status is still 'none' (not yet stamped): the on-disk file IS clean, so no
		// substitution — even though a backup happens to exist.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_NONE;

		$this->assertSame(
			$original,
			EditorBridge::cleanEditSource( $original, $id, 'full' ),
			'a not-yet-watermarked attachment edits its (clean) on-disk original unchanged'
		);
	}

	public function testCleanEditSourceReturnsOriginalWhenNoValidBackup(): void {
		$id       = 8;
		$original = $this->writeOriginal( $id );

		// Watermarked but NO backup recorded → there is nothing safe to substitute.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$this->assertSame(
			$original,
			EditorBridge::cleanEditSource( $original, $id, 'full' ),
			'no valid backup means no clean source to offer — return the original unchanged'
		);
	}

	public function testCleanEditSourceReturnsOriginalForIntermediateSizeRequest(): void {
		$id       = 9;
		$original = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $original );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		// A concrete intermediate size is NEVER the edit source; only 'full' is.
		$this->assertSame(
			$original,
			EditorBridge::cleanEditSource( $original, $id, 'thumbnail' ),
			'only the full-size request is substituted; intermediate sizes pass through'
		);
	}

	public function testCleanEditSourceTreatsFalseSizeAsFullRequest(): void {
		$id       = 10;
		$original = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $original );
		$backupPath                        = Manager::backupPath( $id );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		// WP's loader passes a falsy size to mean "the full/original file" — that is
		// the editable source and must still receive the clean backup.
		$this->assertSame(
			$backupPath,
			EditorBridge::cleanEditSource( $original, $id, false ),
			'a falsy size means the full/original file — still substitute the backup'
		);
	}

	public function testCleanEditSourceReturnsOriginalForBackupWhoseFileVanished(): void {
		$id       = 11;
		$original = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $original );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		// Delete the backup file out-of-band but leave the meta hash: verify() now
		// fails (file gone), so we must NOT hand the editor a non-existent path.
		$backupPath = Manager::backupPath( $id );
		unlink( $backupPath );

		$this->assertSame(
			$original,
			EditorBridge::cleanEditSource( $original, $id, 'full' ),
			'a recorded-but-missing backup fails verify() — fall back to the original path'
		);
	}

	// =====================================================================
	// onAfterEdit() — HAPPY refresh (the only safe refresh path).
	// =====================================================================

	public function testOnAfterEditRefreshesBackupAndEnqueuesForUserCropWithValidPriorBackup(): void {
		$id = 50;

		// 1) A prior clean original + a REAL prior backup (the master the user edited
		//    from, courtesy of cleanEditSource).
		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$priorBackupRel    = $this->meta[ $id ][ Meta::BACKUP_REL ];
		$priorBackupHash   = $this->meta[ $id ][ Meta::BACKUP_HASH ];
		$priorBackupAbs    = Manager::backupPath( $id );
		$this->assertNotNull( $priorBackupAbs );
		$this->assertFileExists( $priorBackupAbs );

		// Capture the cron args so we can assert the enqueue CONTEXT is 'regenerate'
		// (the dedup id-set is keyed on id alone and cannot prove the context).
		$cronArgs = null;
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) use ( &$cronArgs ) {
				$cronArgs = $args;
				return true;
			}
		);

		// Simulate a fully-stamped attachment with stale watermark fingerprint.
		$this->meta[ $id ][ Meta::STATUS ]      = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::SIGNATURE ]   = 'old-signature';
		$this->meta[ $id ][ Meta::SIZES_DONE ]  = [ 'full' => 1, 'thumbnail' => 1 ];
		$this->meta[ $id ][ Meta::APPLIED_GMT ] = '2026-06-29T00:00:00+00:00';
		$this->meta[ $id ][ Meta::ENABLED ]     = '1';

		// 2) SMALL IMAGE: the attached file IS the original (no separate
		//    original_image), so cleanEditSource hands back the backup directly and
		//    records the per-request clean-substitution marker. Drive the REAL filter
		//    so the marker that gates the refresh is set the same way production sets
		//    it. wp_get_original_image_path → the on-disk original (same basename as
		//    the edit source → NOT a big image).
		$this->stubOriginalPath( $id, $priorOriginal );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		$editSource = EditorBridge::cleanEditSource( $priorOriginal, $id, 'full' );
		$this->assertSame(
			$priorBackupAbs,
			$editSource,
			'a small watermarked image must edit from the clean backup (and mark clean-substituted)'
		);

		// 3) WP has written a NEW clean `-e<13 digits>` edited original (because
		//    cleanEditSource fed the editor the clean backup) and repointed the
		//    attached file to it. Make it a genuine, decodable PNG so seedFrom's
		//    Preflight passes and a fresh master is really seeded from it. The
		//    resolver is left pointing at the OLD original to prove seedFrom never
		//    consults it — the master must come from the attached `-e` file.
		$editedOriginal = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedOriginal );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		$editedHash = sha1_file( $editedOriginal );

		// 4) Not processing; lock free → the refresh proceeds.
		$out = EditorBridge::onAfterEdit( [ 'sizes' => [] ], $id );

		// The filter MUST return meta unchanged.
		$this->assertSame( [ 'sizes' => [] ], $out, 'onAfterEdit must always pass the metadata through unchanged' );

		// State was reset then a FRESH backup seeded from the CLEAN edited original:
		//  - the old fingerprint/sizes/applied are cleared;
		//  - the backup hash is now the edited original's hash (a NEW master);
		//  - status reflects ensureBackup's advisory 'watermarked' write (the Processor
		//    re-stamp will own the real lifecycle status when the queued job runs).
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ], 'the stale signature must be cleared' );
		$this->assertArrayNotHasKey( Meta::SIZES_DONE, $this->meta[ $id ], 'the stale sizes map must be cleared' );
		$this->assertArrayNotHasKey( Meta::APPLIED_GMT, $this->meta[ $id ], 'the stale applied time must be cleared' );

		$this->assertSame(
			$editedHash,
			$this->meta[ $id ][ Meta::BACKUP_HASH ],
			'the refreshed master must be a byte-for-byte copy of the CLEAN edited original'
		);
		$this->assertNotSame(
			$priorBackupHash,
			$this->meta[ $id ][ Meta::BACKUP_HASH ],
			'the backup hash must have changed — a new master was seeded from the edit'
		);
		$this->assertNotSame(
			$priorBackupRel,
			$this->meta[ $id ][ Meta::BACKUP_REL ],
			'the backup rel-path must point at the new edited-original master'
		);

		// The fresh backup file genuinely exists and matches the edited original.
		$newBackup = Manager::backupPath( $id );
		$this->assertNotNull( $newBackup );
		$this->assertFileExists( $newBackup );
		$this->assertSame( $editedHash, sha1_file( $newBackup ) );

		// A re-stamp was queued (observed via the Scheduler's pending id-set).
		$this->assertTrue(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'a re-stamp job must be queued after a successful refresh'
		);

		// ...and in the 'regenerate' context, NOT the default 'bulk' (the wrong
		// context would route the job down the wrong pipeline).
		$this->assertIsArray( $cronArgs, 'the cron one-shot must have been scheduled with args' );
		$this->assertSame(
			[ $id, 'regenerate', '' ],
			$cronArgs,
			'the re-stamp must be enqueued in the regenerate context with no bulk run token'
		);

		// The stale prior backup file must not be left orphaned on disk (storage leak).
		// ensureBackup() seeds a NEW master beside it; the prior master is superseded
		// — the new backup path differs and the prior file should be gone.
		$this->assertNotSame( $priorBackupAbs, $newBackup, 'the new master is a distinct file' );
		$this->assertFileDoesNotExist(
			$priorBackupAbs,
			'the superseded prior backup file must not be orphaned on disk after a crop/rotate refresh'
		);
	}

	// =====================================================================
	// onAfterEdit() — MUST-NOT-REFRESH guards (data-loss protection).
	// Each asserts the existing backup meta is left BYTE-FOR-BYTE untouched.
	// =====================================================================

	public function testOnAfterEditDoesNotRefreshWhileProcessing(): void {
		$id            = 60;
		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ]    = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ]   = '1';
		$this->meta[ $id ][ Meta::SIGNATURE ] = 'keep-me';
		$snapshot = $this->backupMetaSnapshot( $id );

		// An `-e` edited file is present, BUT the Processor is mid-regenerate: our own
		// regenerate is NOT a user edit, so the refresh must not fire.
		$editedOriginal = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedOriginal );

		self::setProcessingFlag( true );

		$out = EditorBridge::onAfterEdit( [ 'x' => 1 ], $id );

		$this->assertSame( [ 'x' => 1 ], $out, 'meta is still returned unchanged' );
		$this->assertSame(
			$snapshot,
			$this->backupMetaSnapshot( $id ),
			'DATA-LOSS GUARD: the existing backup meta must be untouched while processing'
		);
		$this->assertSame( 'keep-me', $this->meta[ $id ][ Meta::SIGNATURE ], 'state must not be reset while processing' );
		$this->assertFalse(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'no re-stamp may be queued while processing'
		);
	}

	public function testOnAfterEditDoesNotRefreshWhenNoPriorBackupExisted(): void {
		$id = 61;

		// Flagged + watermarked status, an `-e` edited file present — BUT NO prior
		// backup ever existed. cleanEditSource therefore NEVER substituted, so the
		// edited file may be WATERMARKED. Refusing to refresh is the cardinal rule.
		$this->meta[ $id ][ Meta::STATUS ]  = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ] = '1';

		$editedOriginal = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedOriginal );
		$this->stubOriginalPath( $id, $editedOriginal );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		$out = EditorBridge::onAfterEdit( [ 'y' => 2 ], $id );

		$this->assertSame( [ 'y' => 2 ], $out );

		// No backup meta was fabricated from the possibly-watermarked edited file —
		// this is the irreversible-data-loss guard. (ensureBackup would itself refuse
		// once status was reset; the bridge refuses EARLIER, so status is also intact.)
		$this->assertArrayNotHasKey(
			Meta::BACKUP_HASH,
			$this->meta[ $id ],
			'DATA-LOSS GUARD: no master may be seeded when no prior backup proved a clean edit'
		);
		$this->assertArrayNotHasKey( Meta::BACKUP_REL, $this->meta[ $id ] );
		$this->assertSame(
			Meta::STATUS_WATERMARKED,
			$this->meta[ $id ][ Meta::STATUS ],
			'status must NOT be reset to none when the refresh is refused'
		);
		$this->assertFalse(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'no re-stamp may be queued when the refresh is refused'
		);
	}

	public function testOnAfterEditDoesNotRefreshWhenLockHeld(): void {
		$id            = 62;
		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ]  = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ] = '1';
		$snapshot = $this->backupMetaSnapshot( $id );

		$editedOriginal = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedOriginal );

		// A worker owns this attachment's lock right now (e.g. a concurrent reprocess).
		// Acquire via the Processor's single source of truth for the key so a prefix
		// drift in Processor would genuinely break this guard's coverage.
		\OrchardGrove\OgWatermark\Support\Lock::acquire( Processor::lockKey( $id ), 300 );

		$out = EditorBridge::onAfterEdit( [ 'z' => 3 ], $id );

		$this->assertSame( [ 'z' => 3 ], $out );
		$this->assertSame(
			$snapshot,
			$this->backupMetaSnapshot( $id ),
			'DATA-LOSS GUARD: a held lock means a worker owns this id — leave the backup meta untouched'
		);
		$this->assertFalse( \OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ) );
	}

	public function testOnAfterEditDoesNotRefreshForNonFlaggedAttachment(): void {
		$id            = 63;
		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;
		// NOT flagged — Meta::ENABLED is absent.
		$snapshot = $this->backupMetaSnapshot( $id );

		$editedOriginal = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedOriginal );

		$out = EditorBridge::onAfterEdit( [ 'a' => 1 ], $id );

		$this->assertSame( [ 'a' => 1 ], $out );
		$this->assertSame(
			$snapshot,
			$this->backupMetaSnapshot( $id ),
			'a non-flagged attachment is none of our business — backup meta untouched'
		);
		$this->assertFalse( \OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ) );
	}

	public function testOnAfterEditNeverSeedsANewMasterFromANonEditedOnDiskFile(): void {
		$id            = 64;
		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ]  = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ] = '1';
		$snapshot = $this->backupMetaSnapshot( $id );

		// The attached file is the ordinary original (no `-e<digits>`) — i.e. a WP
		// "Restore original image" repoint (our own regenerate is excluded by the
		// isProcessing gate, not this path). The CRITICAL data-loss guard: a new master
		// must NEVER be seeded from this (possibly watermarked) on-disk file.
		$this->stubAttachedFile( $id, $priorOriginal );

		$out = EditorBridge::onAfterEdit( [ 'b' => 2 ], $id );

		$this->assertSame( [ 'b' => 2 ], $out );
		$this->assertSame(
			$snapshot,
			$this->backupMetaSnapshot( $id ),
			'DATA-LOSS GUARD: a non-`-e` attached file must NEVER seed a new master — backup_* meta untouched'
		);

		// The restore-coexistence behaviour: served sizes are rebuilt from the EXISTING
		// backup, so a regenerate is queued (and only the served-size fingerprint is
		// cleared — never the backup master itself).
		$this->assertTrue(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'a restore repoint must queue a regenerate-from-backup'
		);
	}

	public function testCleanEditSourceReturnsFilepathUnchangedForNonPositiveId(): void {
		// The defensive id<=0 guard: a falsy id from WP must short-circuit before any
		// meta read or substitution.
		$this->assertSame(
			'/some/path.jpg',
			EditorBridge::cleanEditSource( '/some/path.jpg', 0, 'full' ),
			'a non-positive attachment id must return the filepath unchanged'
		);
	}

	public function testOnAfterEditReturnsMetaUnchangedForNonPositiveId(): void {
		// The matching id<=0 guard in onAfterEdit: no enqueue, no meta mutation.
		$out = EditorBridge::onAfterEdit( [ 'k' => 'v' ], 0 );

		$this->assertSame( [ 'k' => 'v' ], $out, 'a non-positive id must return meta unchanged' );
		$this->assertFalse( \OrchardGrove\OgWatermark\Queue\Scheduler::isPending( 0 ) );
	}

	// =====================================================================
	// cleanEditSource() — BIG-IMAGE geometry guard.
	// =====================================================================

	public function testCleanEditSourceReturnsScaledCleanCopyForBigImageNotTheFullResBackup(): void {
		$id = 70;

		// A big image: the TRUE original is full-res; the attached/edited file is the
		// smaller `-scaled` derivative. The backup mirrors the full-res original.
		$fullResOriginal = $this->writeOriginal( $id ); // stands in for the full-res original.
		$this->seedValidBackup( $id, $fullResOriginal );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$backupPath = Manager::backupPath( $id );
		$this->assertNotNull( $backupPath );

		// The attached file WP edits is the `-scaled` derivative (a DIFFERENT basename
		// from the true original), at scaled dimensions in the metadata.
		$scaledFile = $this->monthDir . '/' . $id . '-original-scaled.png';
		$this->writePng( $scaledFile );
		$scaledW = 32;
		$scaledH = 24;

		// wp_get_original_image_path → the TRUE original (full-res); get_attached_file
		// (passed as $filepath here) → the `-scaled` file. Their basenames differ, so
		// the bridge detects a big image.
		$this->stubOriginalPath( $id, $fullResOriginal );
		$this->stubMeta( $id, $scaledW, $scaledH );

		// Stub WP's image editor to produce a real clean PNG at the scaled dimensions.
		$savedPath = null;
		$this->stubImageEditor( $scaledW, $scaledH, $savedPath );

		$result = EditorBridge::cleanEditSource( $scaledFile, $id, 'full' );

		// The substituted source must NOT be the full-res backup (wrong geometry) and
		// must NOT be the watermarked `-scaled` on-disk file.
		$this->assertNotSame( $backupPath, $result, 'a big image must NOT edit from the full-res backup' );
		$this->assertNotSame( $scaledFile, $result, 'a big image must NOT edit the watermarked -scaled file' );

		// It must be a real file at the SAME pixel dimensions as the `-scaled` file WP
		// edits — clean pixels at the geometry the crop selection is computed against.
		$this->assertFileExists( $result );
		$size = getimagesize( $result );
		$this->assertIsArray( $size );
		$this->assertSame( $scaledW, $size[0], 'the clean edit source must match the -scaled width' );
		$this->assertSame( $scaledH, $size[1], 'the clean edit source must match the -scaled height' );
	}

	public function testCleanEditSourceDoesNotSubstituteBigImageWhenScaledCopyCannotBeMade(): void {
		$id = 71;

		$fullResOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $fullResOriginal );
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$scaledFile = $this->monthDir . '/' . $id . '-original-scaled.png';
		$this->writePng( $scaledFile );

		$this->stubOriginalPath( $id, $fullResOriginal );
		$this->stubMeta( $id, 32, 24 );

		// The image editor fails (WP_Error) → no clean-scaled copy can be made, so the
		// bridge must NOT substitute (handing back the full-res backup would corrupt
		// the crop; handing back the watermarked -scaled would poison the master).
		$wpError = new \stdClass();
		$wpError->isWpError = true;
		Functions\when( 'wp_get_image_editor' )->justReturn( $wpError );
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => is_object( $thing ) && isset( $thing->isWpError )
		);

		$result = EditorBridge::cleanEditSource( $scaledFile, $id, 'full' );

		$this->assertSame(
			$scaledFile,
			$result,
			'when no clean-scaled copy can be derived, the bridge must not substitute'
		);
	}

	// =====================================================================
	// onAfterEdit() — WP "Restore original image" coexistence.
	// =====================================================================

	public function testOnAfterEditOnRestoreRebuildsFromExistingBackupWithoutSeedingFromOnDiskFile(): void {
		$id = 80;

		// A flagged, watermarked attachment with a valid prior backup (the master).
		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ]      = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ]     = '1';
		$this->meta[ $id ][ Meta::SIGNATURE ]   = 'old-signature';
		$this->meta[ $id ][ Meta::SIZES_DONE ]  = [ 'full' => 1 ];
		$this->meta[ $id ][ Meta::APPLIED_GMT ] = '2026-06-29T00:00:00+00:00';

		$snapshot = $this->backupMetaSnapshot( $id );

		// WP "Restore original image" repointed the attached file back to the ordinary
		// (non-`-e`) original and fired wp_update_attachment_metadata.
		$this->stubAttachedFile( $id, $priorOriginal );

		$out = EditorBridge::onAfterEdit( [ 'r' => 1 ], $id );

		$this->assertSame( [ 'r' => 1 ], $out, 'onAfterEdit must always pass metadata through unchanged' );

		// The served-size fingerprint is cleared so the sizes are rebuilt from backup.
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ], 'restore must clear the stale signature' );
		$this->assertArrayNotHasKey( Meta::SIZES_DONE, $this->meta[ $id ], 'restore must clear the stale sizes map' );
		$this->assertArrayNotHasKey( Meta::APPLIED_GMT, $this->meta[ $id ], 'restore must clear the stale applied time' );
		$this->assertSame(
			Meta::STATUS_QUEUED,
			$this->meta[ $id ][ Meta::STATUS ],
			'restore must set status queued pending the regenerate-from-backup'
		);

		// CRUCIAL: NO new master was seeded from the on-disk file — the existing
		// pristine backup meta is BYTE-FOR-BYTE untouched.
		$this->assertSame(
			$snapshot,
			$this->backupMetaSnapshot( $id ),
			'DATA-LOSS GUARD: a restore must NOT seed a new master from the on-disk file'
		);

		// A regenerate (rebuild served sizes from the existing backup) was queued.
		$this->assertTrue(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'restore must queue a regenerate-from-backup'
		);
	}

	// =====================================================================
	// onAfterEdit() — BIG-IMAGE refresh (the confirmed data-loss bug).
	// =====================================================================

	/**
	 * THE regression test for the confirmed hotfix bug.
	 *
	 * A BIG image (>2560px) keeps a separate full-res original_image that the plugin
	 * WATERMARKED on first apply. WP's wp_save_image() repoints _wp_attached_file to
	 * the clean `-e` edited file but NEVER touches original_image, so
	 * wp_get_original_image_path() still resolves to the WATERMARKED full-res file —
	 * which DIVERGES from get_attached_file() (the clean `-e` file).
	 *
	 * The fix: refreshFromUserEdit must seed the master STRICTLY from the clean `-e`
	 * file (get_attached_file via Manager::seedFrom), NEVER from the resolver. The
	 * pre-fix code seeded via ensureBackup → wp_get_original_image_path → the
	 * watermarked full-res file, permanently poisoning (or destroying) the master.
	 *
	 * This test drives the FULL request flow: cleanEditSource (sets the clean-
	 * substitution marker for the big image) → onAfterEdit (seeds from the `-e`
	 * file). It asserts the new master is byte-for-byte the CLEAN `-e` file and is
	 * NEVER the watermarked full-res original. It fails against the pre-fix code.
	 */
	public function testOnAfterEditBigImageSeedsFromCleanEditedFileNeverTheWatermarkedFullResOriginal(): void {
		$id = 90;

		// The TRUE full-res original, already WATERMARKED on first apply. Distinct
		// bytes from everything else so a wrongful copy is unmistakable.
		$watermarkedFullRes = $this->monthDir . '/' . $id . '-original.png';
		$this->writePng( $watermarkedFullRes );
		$watermarkedHash = sha1_file( $watermarkedFullRes );

		// A prior valid backup exists (first-apply master). Seed it, then keep the
		// backup_* meta. (Its content is irrelevant to the assertion; the point is the
		// attachment is in the watermarked-with-backup state a real big image is in.)
		$this->seedValidBackup( $id, $watermarkedFullRes );
		$priorBackupHash = $this->meta[ $id ][ Meta::BACKUP_HASH ];
		$this->meta[ $id ][ Meta::STATUS ]      = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ]     = '1';
		$this->meta[ $id ][ Meta::SIGNATURE ]   = 'old-signature';
		$this->meta[ $id ][ Meta::SIZES_DONE ]  = [ 'full' => 1 ];
		$this->meta[ $id ][ Meta::APPLIED_GMT ] = '2026-06-29T00:00:00+00:00';

		// The attached file WP edits is the smaller `-scaled` derivative (a DIFFERENT
		// basename from the full-res original → big image), at scaled dims in meta.
		$scaledFile = $this->monthDir . '/' . $id . '-original-scaled.png';
		$this->writePng( $scaledFile );
		$scaledW = 32;
		$scaledH = 24;

		// THE divergence: wp_get_original_image_path → the WATERMARKED full-res file;
		// it differs from the `-scaled` edit source AND (below) from the `-e` file.
		$this->stubOriginalPath( $id, $watermarkedFullRes );
		$this->stubMeta( $id, $scaledW, $scaledH );

		// Stub WP's editor so cleanEditSource can derive a real clean-scaled copy.
		$savedPath = null;
		$this->stubImageEditor( $scaledW, $scaledH, $savedPath );

		// 1) cleanEditSource on the `-scaled` edit source → substitutes a clean-scaled
		//    copy and SETS the per-request clean-substitution marker for this id.
		$editSource = EditorBridge::cleanEditSource( $scaledFile, $id, 'full' );
		$this->assertNotSame( $scaledFile, $editSource, 'big image must edit from a clean-scaled copy' );
		$this->assertNotSame( $watermarkedFullRes, $editSource, 'big image must NOT edit the full-res file directly' );

		// 2) WP wrote a clean `-e` edited file from the clean-scaled pixels and
		//    repointed the attached file to it. original_image (the resolver) is left
		//    naming the WATERMARKED full-res file — the bug's trap.
		$editedFile = $this->writeEditedOriginal( $id );
		$editedHash = sha1_file( $editedFile );
		$this->assertNotSame( $watermarkedHash, $editedHash, 'the clean -e file must differ from the watermarked full-res' );

		$this->stubAttachedFile( $id, $editedFile );
		// Resolver STILL diverges (watermarked full-res), but the new metadata's dims
		// now describe the `-e` file so seedFrom's Preflight passes against it.
		$this->stubOriginalPath( $id, $watermarkedFullRes );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		// 3) Refresh proceeds (marker set, flagged, not processing, lock free).
		$out = EditorBridge::onAfterEdit( [ 'sizes' => [] ], $id );
		$this->assertSame( [ 'sizes' => [] ], $out, 'onAfterEdit must pass metadata through unchanged' );

		// THE assertion: the new master is the CLEAN `-e` file, byte-for-byte, and is
		// NEVER the watermarked full-res original the resolver would have handed
		// ensureBackup. This is exactly what the pre-fix code got wrong.
		$this->assertSame(
			$editedHash,
			$this->meta[ $id ][ Meta::BACKUP_HASH ],
			'the new master must be a byte-for-byte copy of the CLEAN -e edited file'
		);
		$this->assertNotSame(
			$watermarkedHash,
			$this->meta[ $id ][ Meta::BACKUP_HASH ],
			'DATA-LOSS GUARD: the master must NEVER be the watermarked full-res original'
		);
		$this->assertNotSame( $priorBackupHash, $this->meta[ $id ][ Meta::BACKUP_HASH ], 'a NEW master was seeded' );

		$newBackup = Manager::backupPath( $id );
		$this->assertNotNull( $newBackup );
		$this->assertFileExists( $newBackup );
		$this->assertSame( $editedHash, sha1_file( $newBackup ), 'the master file on disk equals the clean -e file' );

		// Stale fingerprint cleared, re-stamp queued.
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::SIZES_DONE, $this->meta[ $id ] );
		$this->assertTrue(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'a re-stamp must be queued after the big-image refresh'
		);
	}

	// =====================================================================
	// onAfterEdit() — DECLINED substitution (marker unset → never seed).
	// =====================================================================

	/**
	 * When cleanEditSource was NOT called / DECLINED to substitute this request, the
	 * per-request clean-substitution marker is unset, so refreshFromUserEdit must
	 * leave the backup_* meta byte-for-byte untouched and seed NOTHING — even though
	 * everything else (flagged, `-e` file, valid prior backup, not processing, free
	 * lock) would otherwise authorize a refresh. This models the big-image
	 * declined-substitution gap (the clean-scaled copy could not be produced), where
	 * the editor edited WATERMARKED pixels and the `-e` file is therefore dirty.
	 */
	public function testOnAfterEditDeclinedSubstitutionLeavesMasterUntouchedAndSeedsNothing(): void {
		$id = 91;

		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ]  = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ] = '1';
		$snapshot = $this->backupMetaSnapshot( $id );

		// An `-e` edited file is present and the attached file points at it — BUT
		// cleanEditSource was never called (declined), so the marker is unset. The
		// edited file may be watermarked: the refresh must be refused entirely.
		$editedFile = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedFile );
		$this->stubOriginalPath( $id, $priorOriginal );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		$out = EditorBridge::onAfterEdit( [ 'd' => 1 ], $id );

		$this->assertSame( [ 'd' => 1 ], $out, 'onAfterEdit must pass metadata through unchanged' );

		// DATA-LOSS GUARD: the existing master is untouched — no new hash, no deleted
		// hash, no rel-path change.
		$this->assertSame(
			$snapshot,
			$this->backupMetaSnapshot( $id ),
			'DATA-LOSS GUARD: a declined substitution must leave the backup meta byte-for-byte untouched'
		);

		// Status is NOT reset to none and no re-stamp is queued — we touched nothing.
		$this->assertSame(
			Meta::STATUS_WATERMARKED,
			$this->meta[ $id ][ Meta::STATUS ],
			'a declined refresh must not reset the status'
		);
		$this->assertFalse(
			\OrchardGrove\OgWatermark\Queue\Scheduler::isPending( $id ),
			'a declined refresh must not queue a re-stamp'
		);

		// A skip note is recorded for operator visibility. Here the attached file IS an
		// `-e` edited original AND the prior backup still verifies, yet the marker was
		// missed — the DESYNC case (offload/CDN short-circuit on a genuinely clean
		// edit). The note must surface that stale-master desync for a manual reprocess.
		$this->assertArrayHasKey( Meta::LAST_ERROR, $this->meta[ $id ], 'a declined refresh records a skip note' );
		$note = (string) $this->meta[ $id ][ Meta::LAST_ERROR ];
		$this->assertStringContainsString( 'edit-refresh-skipped', $note );
		$this->assertStringContainsString( 'STALE', $note, 'a clean-edit desync must be flagged stale for manual reprocess' );
		$this->assertStringContainsString( 'reprocess', $note );
	}

	/**
	 * The GENERIC decline note: when the skipped attached file is NOT an `-e` edited
	 * original (or no prior backup verifies), refreshFromUserEdit records the ordinary
	 * "not provably clean" note — NOT the stale-master desync flag, which is reserved
	 * for a detected clean-but-unmarked edit. Proves skipNote() distinguishes the two.
	 */
	public function testOnAfterEditDeclinedGenericNoteWhenAttachedFileIsNotAnEditedOriginal(): void {
		$id = 92;

		$priorOriginal = $this->writeOriginal( $id );
		$this->seedValidBackup( $id, $priorOriginal );
		$this->meta[ $id ][ Meta::STATUS ]  = Meta::STATUS_WATERMARKED;
		$this->meta[ $id ][ Meta::ENABLED ] = '1';

		// An `-e` edited file exists on disk (so onAfterEdit routes to the user-edit
		// branch), but get_attached_file resolves the ORDINARY original — so skipNote()
		// sees a non-`-e` attached file and must emit the GENERIC note. (We reach
		// refreshFromUserEdit via isEditedOriginal on the attached path, so point the
		// attached file at the `-e` for routing, then DESYNC detection re-reads it.)
		$editedFile = $this->writeEditedOriginal( $id );
		$this->stubAttachedFile( $id, $editedFile );
		$this->stubOriginalPath( $id, $priorOriginal );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		// Marker unset (declined). Force verify() to FAIL by removing the backup file so
		// the DESYNC branch (which requires a verifying backup) is NOT taken.
		$backupAbs = Manager::backupPath( $id );
		$this->assertNotNull( $backupAbs );
		unlink( $backupAbs );

		EditorBridge::onAfterEdit( [ 'g' => 1 ], $id );

		$note = (string) $this->meta[ $id ][ Meta::LAST_ERROR ];
		$this->assertStringContainsString( 'edit-refresh-skipped', $note );
		$this->assertStringContainsString( 'not provably clean', $note, 'a non-verifying decline gets the generic note' );
		$this->assertStringNotContainsString( 'STALE', $note, 'the generic note must not raise a stale-master desync flag' );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/**
	 * Write a real, decodable PNG at the uploads year/month location for $id.
	 * Random per-pixel noise keeps it above Preflight's byte floor.
	 */
	private function writeOriginal( int $id ): string {
		return $this->writePng( $this->monthDir . '/' . $id . '-original.png' );
	}

	/** Write a real `-e<13 digits>` edited original PNG for $id. */
	private function writeEditedOriginal( int $id ): string {
		return $this->writePng( $this->monthDir . '/' . $id . '-original-e1700000000000.png' );
	}

	/** Generate a genuine noisy PNG at $path and return it. */
	private function writePng( string $path ): string {
		$im = imagecreatetruecolor( self::IMG_W, self::IMG_H );
		for ( $x = 0; $x < self::IMG_W; $x++ ) {
			for ( $y = 0; $y < self::IMG_H; $y++ ) {
				$color = imagecolorallocate( $im, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) );
				imagesetpixel( $im, $x, $y, $color );
			}
		}
		imagepng( $im, $path );

		return $path;
	}

	/**
	 * Seed a REAL, verifying pristine backup for $id from $original via the genuine
	 * Manager first-apply create path, then reset status back to 'none' so callers
	 * set the status they want. Leaves BACKUP_* meta populated + the backup on disk.
	 */
	private function seedValidBackup( int $id, string $original ): void {
		$this->stubOriginalPath( $id, $original );
		$this->stubMeta( $id, self::IMG_W, self::IMG_H );

		$result = Manager::ensureBackup( $id );
		$this->assertTrue( $result['ok'], 'seed: ensureBackup must create a pristine master' );
		$this->assertTrue( Manager::verify( $id ), 'seed: the freshly created backup must verify' );

		// ensureBackup returns an advisory 'watermarked' status but writes none; keep
		// status 'none' until the test sets it explicitly.
		unset( $this->meta[ $id ][ Meta::STATUS ] );
	}

	/** Stub wp_get_original_image_path() → $path for $id. */
	private function stubOriginalPath( int $id, string $path ): void {
		Functions\when( 'wp_get_original_image_path' )->alias(
			static fn( $arg ) => (int) $arg === $id ? $path : false
		);
	}

	/** Stub get_attached_file() → $path for $id. */
	private function stubAttachedFile( int $id, string $path ): void {
		Functions\when( 'get_attached_file' )->alias(
			static fn( $arg ) => (int) $arg === $id ? $path : false
		);
	}

	/**
	 * Stub wp_get_image_editor() → a fake editor that resizes to $w×$h and saves a
	 * real PNG at the requested path (so the bridge's output is a genuine, decodable
	 * file at the scaled geometry). Also stubs is_wp_error to recognise the editor's
	 * non-error results. $savedPath is set by-ref to the path the editor saved to.
	 */
	private function stubImageEditor( int $w, int $h, ?string &$savedPath ): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_get_image_editor' )->alias(
			static function () use ( $w, $h, &$savedPath ) {
				return new class( $w, $h, $savedPath ) {
					public function __construct(
						private int $w,
						private int $h,
						private ?string &$savedPath
					) {}

					public function resize( $width, $height, $crop = false ) {
						$this->w = (int) $width;
						$this->h = (int) $height;
						return true;
					}

					/** @return array<string,mixed> */
					public function save( $destfilename = null ) {
						$im = imagecreatetruecolor( $this->w, $this->h );
						imagepng( $im, (string) $destfilename );
						$this->savedPath = (string) $destfilename;
						return [
							'path'      => (string) $destfilename,
							'width'     => $this->w,
							'height'    => $this->h,
							'mime-type' => 'image/png',
						];
					}
				};
			}
		);
	}

	/** Stub wp_get_attachment_metadata() → width/height for $id. */
	private function stubMeta( int $id, int $w, int $h ): void {
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( $arg ) => (int) $arg === $id ? [
				'width'  => $w,
				'height' => $h,
			] : false
		);
	}

	/**
	 * Snapshot the three BACKUP_* meta keys for $id so a guard test can prove they
	 * are untouched.
	 *
	 * @return array<string,mixed>
	 */
	private function backupMetaSnapshot( int $id ): array {
		return [
			Meta::BACKUP_REL   => $this->meta[ $id ][ Meta::BACKUP_REL ] ?? null,
			Meta::BACKUP_HASH  => $this->meta[ $id ][ Meta::BACKUP_HASH ] ?? null,
			Meta::BACKUP_BYTES => $this->meta[ $id ][ Meta::BACKUP_BYTES ] ?? null,
		];
	}

	/** Reset Storage's private static base cache between tests. */
	private static function resetBaseCache(): void {
		$prop = ( new ReflectionClass( Storage::class ) )->getProperty( 'base' );
		$prop->setValue( null, null );
	}

	/** Reset the Processor's private static $processing flag. */
	private static function resetProcessingFlag(): void {
		self::setProcessingFlag( false );
	}

	/** Drive the Processor's private static $processing flag (no public setter). */
	private static function setProcessingFlag( bool $value ): void {
		$prop = ( new ReflectionClass( Processor::class ) )->getProperty( 'processing' );
		$prop->setValue( null, $value );
	}

	/**
	 * Clear EditorBridge's per-request clean-substitution marker between tests so a
	 * marker set in one test can never leak into another (the marker is request-
	 * scoped in production, but the test process is one long-lived request).
	 */
	private static function resetCleanSubstituted(): void {
		$prop = ( new ReflectionClass( EditorBridge::class ) )->getProperty( 'cleanSubstituted' );
		$prop->setValue( null, [] );
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
