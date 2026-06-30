<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Pipeline;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Backup\Storage;
use OrchardGrove\OgWatermark\Engine\Compositor;
use OrchardGrove\OgWatermark\Pipeline\Outcome;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Pipeline\SizeResolver;
use OrchardGrove\OgWatermark\Pipeline\SubsizeRegenerator;
use OrchardGrove\OgWatermark\Support\Capabilities;
use OrchardGrove\OgWatermark\Support\Lock;
use OrchardGrove\OgWatermark\Support\Meta;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * End-to-end Processor tests — the orchestration keystone, exercised for real.
 *
 * This is a genuine integration test, NOT a mock theater: it uses real temp PNG
 * files, the REAL GD Compositor (GD is present, so pixels are actually stamped),
 * the REAL Manager / BackupWriter / Storage / Preflight against real temp files,
 * and the REAL Lock (modeled on an in-memory object cache that faithfully mirrors
 * wp_cache_add's atomic store-iff-absent). Only WordPress functions are stubbed,
 * and the SubsizeRegenerator is replaced with a fake that writes CLEAN copies of
 * the restored original at the recorded size paths (standing in for core's
 * wp_create_image_subsizes), so the whole restore→regenerate→stamp→commit flow
 * runs without a live WP image stack.
 *
 * The assertions prove real behavior: that target file pixels actually change, the
 * backup file exists and is pristine, status/signature/sizes_done are correct, and
 * the noop / backup_missing / locked / restore / too_large branches each behave per
 * the SPEC ordering (backup-before-touch, rebuild-from-backup, temp-then-atomic-
 * rename, commit-signature-LAST, restore-rebuilds-before-clearing-meta).
 */
final class ProcessorTest extends TestCase {

	private string $root = '';
	private string $contentDir = '';
	private string $uploadsDir = '';
	private string $monthDir = '';
	private string $backupBase = '';

	/** @var array<int,array<string,mixed>> In-memory post meta, keyed by id. */
	private array $meta = [];

	/** @var array<string,mixed> In-memory transient store. */
	private array $transients = [];

	/** @var array<string,string> In-memory object cache ("group:key" => token). */
	private array $cache = [];

	/** @var array<string,mixed> In-memory options table (for Lock's DB fallback + misc). */
	private array $options = [];

	/** @var array<int,array<string,mixed>> Per-attachment fake attachment metadata. */
	private array $attachmentMeta = [];

	/** @var array<int,string> get_attached_file() return per id (the "full"). */
	private array $attachedFile = [];

	/** @var array<int,string> wp_get_original_image_path() return per id (the true original). */
	private array $originalPath = [];

	/** Settings array Options reads back from get_option('ogwm_settings'). */
	private array $settings = [];

	/** Big-enough image so intermediates clear the 300px min-dimension default. */
	private const FULL_W = 1200;
	private const FULL_H = 800;

	protected function setUp(): void {
		parent::setUp();

		$this->root       = sys_get_temp_dir() . '/ogwm-proc-' . uniqid( '', true );
		$this->contentDir = $this->root . '/wp-content';
		$this->uploadsDir = $this->contentDir . '/uploads';
		$this->monthDir   = $this->uploadsDir . '/2026/06';
		mkdir( $this->monthDir, 0777, true );

		$this->backupBase = $this->contentDir . '/og-watermark-originals-test';
		mkdir( $this->backupBase, 0777, true );

		// Default settings: LOGO mode (deterministic, visible pixel change), all
		// sizes enabled, 300px min-dimension (so 1200x800 intermediates qualify).
		$this->settings = [
			'watermark' => [
				'type'    => 'logo',
				'logo_id' => 9001,
			],
			'placement' => [ 'position' => 'bot-right', 'margin_pct' => 3 ],
			'sizing'    => [ 'scale_pct' => 30, 'min_px' => 60, 'max_px' => 600, 'opacity' => 100 ],
			'sizes'     => [ 'enabled' => [], 'min_dimension_px' => 300 ],
			'output'    => [ 'jpeg_bg' => '#ffffff', 'srcset_hide_unstamped' => false ],
			'advanced'  => [],
		];

		$this->stubWpFunctions();

		self::resetStorageBase();
		self::resetCapsMemo();
		Compositor::resetTestDriver(); // Use the REAL GD driver.
	}

	protected function tearDown(): void {
		self::resetStorageBase();
		self::resetCapsMemo();
		$this->rrmdir( $this->root );
		$this->meta           = [];
		$this->transients     = [];
		$this->cache          = [];
		$this->options        = [];
		$this->attachmentMeta = [];
		$this->attachedFile   = [];
		$this->originalPath   = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// =====================================================================
	// (a) Happy path — real backup + real GD stamping of every target.
	// =====================================================================

	public function testHappyPathStampsEveryTargetAndCommitsSignatureLast(): void {
		$id = 42;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// Snapshot the CLEAN pixels of the full + every size before processing.
		$fullPath = $this->attachedFile[ $id ];
		$cleanFull = sha1_file( $fullPath );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		$this->assertSame( Outcome::WATERMARKED, $outcome->status, 'a flagged pristine image must watermark' );

		// --- the pristine backup exists and is byte-identical to the clean source.
		$backup = Manager::backupPath( $id );
		$this->assertNotNull( $backup );
		$this->assertFileExists( $backup );
		$this->assertSame(
			$this->pristineHash[ $id ],
			sha1_file( $backup ),
			'the backup must be a byte-for-byte copy of the never-composited original'
		);

		// --- the full file's pixels ACTUALLY changed (real GD watermark).
		$this->assertNotSame(
			$cleanFull,
			sha1_file( $fullPath ),
			'the full file pixels must change — a real watermark was baked in'
		);
		$this->assertTrue( $this->cornerPixelChanged( $fullPath, $id ), 'the bottom-right corner must be visibly stamped' );

		// --- every recorded size file exists and was stamped (pixels differ from clean).
		$sizesDone = $this->meta[ $id ][ Meta::SIZES_DONE ];
		$this->assertIsArray( $sizesDone );
		$this->assertArrayHasKey( SizeResolver::SLUG_FULL, $sizesDone );
		$this->assertArrayHasKey( 'medium', $sizesDone );
		$this->assertSame( $sizesDone, $outcome->sizesStamped );

		foreach ( $this->sizePaths[ $id ] as $slug => $path ) {
			$this->assertFileExists( $path );
			$this->assertNotSame(
				$this->cleanSizeHash[ $id ][ $slug ],
				sha1_file( $path ),
				"size {$slug} must have been stamped (pixels differ from the clean regenerate)"
			);
		}

		// --- lifecycle meta: watermarked + signature set + applied + no error.
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertNotEmpty( $this->meta[ $id ][ Meta::SIGNATURE ] );
		$this->assertArrayHasKey( Meta::APPLIED_GMT, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );

		// --- no staging temp files left behind anywhere.
		$this->assertCount( 0, glob( $this->monthDir . '/*.ogwm-*.tmp' ) ?: [] );

		// --- the lock was released (re-acquirable).
		$this->assertFalse( Lock::held( 'ogwm_lock_' . $id ) );
		// --- and the reentrancy flag was cleared.
		$this->assertFalse( Processor::isProcessing() );
	}

	// =====================================================================
	// (b) Signature short-circuit — a matching signature is a true no-op.
	// =====================================================================

	public function testSignatureShortCircuitReturnsNoopWithoutRestamping(): void {
		$id = 43;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// First apply lands the watermark + signature.
		( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ] );

		// Snapshot the watermarked bytes of every served file.
		$watermarkedFull = sha1_file( $this->attachedFile[ $id ] );
		$watermarkedSizes = [];
		foreach ( $this->sizePaths[ $id ] as $slug => $path ) {
			$watermarkedSizes[ $slug ] = sha1_file( $path );
		}

		// Snapshot the backup file's identity so we can prove the no-op decides BEFORE
		// any backup work (the signature short-circuit must precede ensureBackup): a
		// no-op must not re-copy/replace the master.
		$backupPath = Manager::backupPath( $id );
		$this->assertNotNull( $backupPath );
		clearstatcache();
		$backupInodeBefore = fileinode( $backupPath );
		$backupMtimeBefore = filemtime( $backupPath );
		$backupHashBefore  = sha1_file( $backupPath );

		// A second run with UNCHANGED settings must short-circuit BEFORE any work:
		// inject a regenerator that fails the test if it is ever called.
		$tripwire = new SubsizeRegenerator(
			static function ( int $id, string $sourceFile ): array {
				throw new \LogicException( 'regenerate() must NOT run on a signature no-op' );
			}
		);

		$outcome = ( new Processor( $tripwire ) )->process( $id, 'settings-change' );

		$this->assertSame( Outcome::NOOP, $outcome->status );

		// Files are byte-identical — nothing was re-stamped or rebuilt.
		$this->assertSame( $watermarkedFull, sha1_file( $this->attachedFile[ $id ] ) );
		foreach ( $this->sizePaths[ $id ] as $slug => $path ) {
			$this->assertSame( $watermarkedSizes[ $slug ], sha1_file( $path ), "size {$slug} must be untouched on a no-op" );
		}

		// The backup master is untouched: same inode, mtime, and bytes. A no-op must
		// not re-run any backup work — proving the signature short-circuit fires
		// BEFORE ensureBackup() (a master re-copy would change inode/mtime).
		clearstatcache();
		$this->assertSame( $backupInodeBefore, fileinode( $backupPath ), 'the backup file must not be replaced on a no-op' );
		$this->assertSame( $backupMtimeBefore, filemtime( $backupPath ), 'the backup file must not be rewritten on a no-op' );
		$this->assertSame( $backupHashBefore, sha1_file( $backupPath ) );
	}

	public function testSignatureMismatchAfterSettingsChangeReprocessesFromBackup(): void {
		$id = 44;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		$proc = new Processor( $this->fakeRegenerator() );
		$proc->process( $id, 'flag' );
		$firstSig = $this->meta[ $id ][ Meta::SIGNATURE ];

		// Change a pixel-affecting setting (opacity) → signature must differ →
		// reprocess from the immutable backup (never stacks).
		$this->settings['sizing']['opacity'] = 50;

		$outcome = $proc->process( $id, 'settings-change' );
		$this->assertSame( Outcome::WATERMARKED, $outcome->status );
		$this->assertNotSame( $firstSig, $this->meta[ $id ][ Meta::SIGNATURE ], 'the signature must update after a settings change' );

		// The backup is still the pristine master (never overwritten with a stamp).
		$this->assertSame( $this->pristineHash[ $id ], sha1_file( Manager::backupPath( $id ) ) );
	}

	// =====================================================================
	// (c) backup_missing guard — already-stamped, no backup → nothing stamped.
	// =====================================================================

	public function testBackupMissingGuardStampsNothing(): void {
		$id = 45;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// Simulate an attachment that WAS watermarked but whose backup vanished
		// out-of-band: status=watermarked, no backup meta/file. ensureBackup()'s
		// hard guard must refuse to re-derive a master from the (dirty) original.
		$this->meta[ $id ][ Meta::STATUS ] = Meta::STATUS_WATERMARKED;

		$fullBefore = sha1_file( $this->attachedFile[ $id ] );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		$this->assertSame( Outcome::BACKUP_MISSING, $outcome->status );

		// NOTHING stamped: the served full is byte-for-byte unchanged.
		$this->assertSame( $fullBefore, sha1_file( $this->attachedFile[ $id ] ) );
		// No backup file was fabricated from the dirty original.
		$this->assertNull( Manager::backupPath( $id ) );
		// Status + surfaced error reflect the terminal state.
		$this->assertSame( Meta::STATUS_BACKUP_MISSING, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );
		// And no completion signature was written.
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ] );
	}

	// =====================================================================
	// (d) Lock contention — a pre-held lock yields locked, no work done.
	// =====================================================================

	public function testLockContentionReturnsLockedAndDoesNotProcess(): void {
		$id = 46;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// Another worker already holds the lock.
		$otherToken = Lock::acquire( 'ogwm_lock_' . $id );
		$this->assertIsString( $otherToken );

		$fullBefore = sha1_file( $this->attachedFile[ $id ] );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		$this->assertSame( Outcome::LOCKED, $outcome->status );
		// Nothing was processed while the lock was held by another owner.
		$this->assertSame( $fullBefore, sha1_file( $this->attachedFile[ $id ] ) );
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ] ?? [] );
		$this->assertNull( Manager::backupPath( $id ) );

		// The other worker's lock survived intact (our run must not release it).
		$this->assertTrue( Lock::held( 'ogwm_lock_' . $id ) );
		$this->assertTrue( Lock::release( 'ogwm_lock_' . $id, $otherToken ) );
	}

	// =====================================================================
	// (e) Restore — clean sizes rebuilt, then meta cleared, backup retained.
	// =====================================================================

	public function testRestoreRebuildsCleanFilesThenClearsMetaAndKeepsBackup(): void {
		$id = 47;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		$proc = new Processor( $this->fakeRegenerator() );
		$proc->process( $id, 'flag' );

		// Sanity: it really is watermarked now.
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertNotSame( $this->pristineHash[ $id ], sha1_file( $this->attachedFile[ $id ] ) );

		$outcome = $proc->restore( $id );

		$this->assertSame( Outcome::RESTORED, $outcome->status );

		// The full + every size are byte-identical to the PRISTINE original again.
		$this->assertSame(
			$this->pristineHash[ $id ],
			sha1_file( $this->attachedFile[ $id ] ),
			'restore must put the pristine full back in place'
		);
		foreach ( $this->sizePaths[ $id ] as $slug => $path ) {
			$this->assertSame(
				$this->pristineHash[ $id ],
				sha1_file( $path ),
				"restore must rebuild a clean (un-watermarked) {$slug}"
			);
		}

		// Lifecycle meta cleared; status restored; flag cleared.
		$this->assertSame( Meta::STATUS_RESTORED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::SIZES_DONE, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::APPLIED_GMT, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::ENABLED, $this->meta[ $id ], 'undo must also unflag' );

		// The backup is RETAINED (undo can be re-applied later).
		$this->assertNotNull( Manager::backupPath( $id ) );
		$this->assertTrue( Manager::verify( $id ) );
	}

	public function testRestoreWithoutBackupReturnsBackupMissing(): void {
		$id = 48;
		$this->seedSmallImageAttachment( $id );
		// No backup ever created.
		$outcome = ( new Processor( $this->fakeRegenerator() ) )->restore( $id );
		$this->assertSame( Outcome::BACKUP_MISSING, $outcome->status );
	}

	public function testRestoreWhileLockedReturnsLocked(): void {
		$id = 49;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );
		( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		$other = Lock::acquire( 'ogwm_lock_' . $id );
		$this->assertIsString( $other );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->restore( $id );
		$this->assertSame( Outcome::LOCKED, $outcome->status );

		Lock::release( 'ogwm_lock_' . $id, $other );
	}

	// =====================================================================
	// (f) Full/-scaled too_large → status is NOT watermarked.
	// =====================================================================

	public function testFullTooLargeBlocksWatermarkedStatus(): void {
		$id = 50;
		// A large-DIMENSION image whose w*h*4*1.8 exceeds a deliberately-shrunk
		// memory budget. The file is a solid color so it is tiny on disk and
		// getimagesize() reads only the header — the Compositor's memory pre-flight
		// rejects it as too_large BEFORE any decode, so no real RAM is consumed.
		$this->seedLargeDimensionAttachment( $id, 5000, 4000 );
		$this->flag( $id );

		$fullClean = sha1_file( $this->attachedFile[ $id ] );

		// Shrink the memory budget so 5000*4000*4*1.8 (~144 MiB) overruns it. We set
		// the limit AFTER the canvas was created (during the fixture) so creating it
		// never hit the low ceiling; the pre-flight needs only the header dimensions.
		$originalLimit = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '64M' );

		try {
			$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );
		} finally {
			ini_set( 'memory_limit', false === $originalLimit ? '-1' : $originalLimit );
		}

		$this->assertSame( Outcome::TOO_LARGE, $outcome->status, 'an over-budget full file must report too_large, never watermarked' );
		$this->assertSame( Meta::STATUS_TOO_LARGE, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ], 'no completion signature when the full was too large' );
		$this->assertArrayHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );

		// The backup exists (ensured before any stamp) and the served full is the
		// CLEAN regenerate — never a partially-stamped file.
		$this->assertNotNull( Manager::backupPath( $id ) );
		$this->assertSame( $fullClean, sha1_file( $this->attachedFile[ $id ] ) );
	}

	public function testFullStampFailureIsNotWatermarked(): void {
		$id = 51;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// A test driver whose save() fails makes EVERY Compositor::stamp() return
		// failed — including the required 'full' target. The Processor must then
		// refuse to write 'watermarked' or the completion signature.
		$driver         = new FailingSaveDriver();
		Compositor::setTestDriver( $driver );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		$this->assertSame( Outcome::FAILED, $outcome->status, 'a failed full stamp must NOT report watermarked' );
		$this->assertSame( Meta::STATUS_FAILED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ], 'no completion signature when the full file did not stamp' );
		$this->assertArrayHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );

		// The backup still exists (it is ensured before any stamp attempt), and the
		// served files are the CLEAN regenerate (never a half-stamped set), because
		// the failed-rename/clean-file invariant leaves the regenerated clean file.
		$this->assertNotNull( Manager::backupPath( $id ) );
		$this->assertSame(
			$this->pristineHash[ $id ],
			sha1_file( $this->attachedFile[ $id ] ),
			'a failed stamp must leave the clean regenerated full in place, not a partial write'
		);
	}

	/**
	 * Partial-stamp resume path: a lesser size commits but the REQUIRED full fails.
	 * The Processor must block watermarked, withhold the signature, record the
	 * committed intermediate (but NOT 'full') in sizes_done + the outcome's partial
	 * map, and leave the full on disk as the CLEAN regenerate (not a partial write).
	 */
	public function testPartialStampWhereOnlyFullFailsBlocksWatermarkedButCommitsIntermediate(): void {
		$id = 52;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		$cleanFull = sha1_file( $this->attachedFile[ $id ] );

		// A driver that stamps intermediates for real but fails save() on the full.
		Compositor::setTestDriver( new FullOnlyFailDriver() );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		$this->assertSame( Outcome::FAILED, $outcome->status, 'a failed required full must block watermarked even when a lesser size stamped' );
		$this->assertSame( Meta::STATUS_FAILED, $this->meta[ $id ][ Meta::STATUS ] );

		// No completion signature — the served set is NOT current.
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ], 'no signature when only lesser sizes stamped' );
		$this->assertArrayHasKey( Meta::LAST_ERROR, $this->meta[ $id ] );

		// The partial map carries the committed intermediate but NOT 'full'.
		$sizesDone = $this->meta[ $id ][ Meta::SIZES_DONE ];
		$this->assertIsArray( $sizesDone );
		$this->assertArrayHasKey( 'medium', $sizesDone, 'the intermediate that stamped must be recorded' );
		$this->assertArrayNotHasKey( SizeResolver::SLUG_FULL, $sizesDone, 'the failed full must NOT be recorded as done' );
		$this->assertSame( $sizesDone, $outcome->sizesStamped, 'the outcome carries the partial sizes_done map' );

		// The medium was REALLY stamped (pixels differ from its clean regenerate).
		$this->assertNotSame(
			$this->cleanSizeHash[ $id ]['medium'],
			sha1_file( $this->sizePaths[ $id ]['medium'] ),
			'the committed intermediate must hold real stamped pixels'
		);

		// The full on disk is the CLEAN regenerate (a failed stamp never leaves a
		// half-written full — the rename was never reached).
		$this->assertSame(
			$cleanFull,
			sha1_file( $this->attachedFile[ $id ] ),
			'a failed full stamp must leave the clean regenerated full in place'
		);

		// No staging temps left behind.
		$this->assertCount( 0, glob( $this->monthDir . '/*.ogwm-*.tmp' ) ?: [] );
	}

	/**
	 * An over-budget INTERMEDIATE must NOT block watermarked. Only the required
	 * full/-scaled (and the big-image original) gate the status — a too-large lesser
	 * size is simply omitted from sizesStamped while the attachment still watermarks.
	 * This isolates "a too-large intermediate does not block" from the full-blocks
	 * case in testFullTooLargeBlocksWatermarkedStatus.
	 */
	public function testTooLargeIntermediateDoesNotBlockWatermarked(): void {
		$id = 53;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// The fake regenerator writes a GIANT-dimension 'medium' (so its memory pre-
		// flight rejects it as too_large) while the full + thumbnail stay small.
		$regen = new SubsizeRegenerator(
			fn( int $rid, string $src ): array => $this->regenerateWithGiantMedium( $rid, $src )
		);

		$originalLimit = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '64M' );
		try {
			$outcome = ( new Processor( $regen ) )->process( $id, 'flag' );
		} finally {
			ini_set( 'memory_limit', false === $originalLimit ? '-1' : $originalLimit );
		}

		// The full stamped, so the attachment IS watermarked despite the giant medium.
		$this->assertSame( Outcome::WATERMARKED, $outcome->status, 'a too-large intermediate must not block the watermarked status' );
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertNotEmpty( $this->meta[ $id ][ Meta::SIGNATURE ], 'the signature is written because the full stamped' );

		// The full + the small thumbnail are present; the over-budget medium is absent.
		$this->assertArrayHasKey( SizeResolver::SLUG_FULL, $outcome->sizesStamped );
		$this->assertArrayHasKey( 'thumbnail', $outcome->sizesStamped );
		$this->assertArrayNotHasKey( 'medium', $outcome->sizesStamped, 'the too-large intermediate is simply omitted, not fatal' );
	}

	/**
	 * A regenerate failure (core returns a non-array → SubsizeRegenerator yields
	 * null) must NOT wipe the attachment's existing metadata: the Processor reports
	 * failed WITHOUT calling wp_update_attachment_metadata, so sizes / dimensions
	 * survive. (Guards the OOM-on-a-big-original case from also nuking srcset.)
	 */
	public function testRegenerateReturningNullDoesNotWipeMetadata(): void {
		$id = 54;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		// Pre-seed some existing sizes in the attachment metadata; a failed regenerate
		// must leave them intact.
		$this->attachmentMeta[ $id ]['sizes'] = [
			'medium' => [ 'file' => $id . '-photo-600x400.png', 'width' => 600, 'height' => 400, 'mime-type' => 'image/png' ],
		];
		$metaBefore = $this->attachmentMeta[ $id ];

		// A regenerator that simulates core's WP_Error path: returns null.
		$nullRegen = new SubsizeRegenerator(
			static fn( int $rid, string $src ): ?array => null
		);

		$outcome = ( new Processor( $nullRegen ) )->process( $id, 'flag' );

		$this->assertSame( Outcome::FAILED, $outcome->status );
		$this->assertSame( 'regenerate-failed', $outcome->reason );
		$this->assertSame( Meta::STATUS_FAILED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ] );

		// The attachment metadata was NOT clobbered — its sizes/dimensions survive.
		$this->assertSame( $metaBefore, $this->attachmentMeta[ $id ], 'a failed regenerate must not overwrite the attachment metadata' );
		$this->assertArrayHasKey( 'medium', $this->attachmentMeta[ $id ]['sizes'] );
	}

	/**
	 * The per-target try/finally in stampTargets() must sweep the staging temp even
	 * when save() throws (a future change could let an exception escape the stamp).
	 * The full's stamp explodes → failed, but no `.ogwm-<token>.tmp` may remain.
	 */
	public function testStagingTempIsSweptWhenStampThrows(): void {
		$id = 55;
		$this->seedSmallImageAttachment( $id );
		$this->flag( $id );

		Compositor::setTestDriver( new ThrowingSaveDriver() );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );

		// The required full could not stamp, so the run failed — but the point is the
		// temp sweep, asserted next.
		$this->assertSame( Outcome::FAILED, $outcome->status );
		$this->assertCount(
			0,
			glob( $this->monthDir . '/*.ogwm-*.tmp' ) ?: [],
			'no staging temp may survive even when save() throws'
		);
	}

	/**
	 * Big-image restore: after watermarking BOTH the -scaled full and the true
	 * original, restore() must rebuild BOTH back to their pristine clean bytes. The
	 * -scaled full is NOT touched by Manager::restoreOriginal (which only restores the
	 * TRUE original); it is recreated by the regenerate step — exactly the
	 * "rebuild clean sizes BEFORE clearing meta" invariant for big images.
	 */
	public function testRestoreRebuildsBigImageScaledAndOriginal(): void {
		$id = 71;
		$this->seedBigImageAttachment( $id );
		$this->flag( $id );

		// Clean (pristine) bytes of BOTH served files, before any stamp.
		$cleanScaled   = sha1_file( $this->attachedFile[ $id ] );
		$cleanOriginal = sha1_file( $this->originalPath[ $id ] );

		$proc = new Processor( $this->bigImageRegenerator() );
		$proc->process( $id, 'flag' );

		// Sanity: both were really stamped (pixels differ from clean).
		$this->assertSame( Meta::STATUS_WATERMARKED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertNotSame( $cleanScaled, sha1_file( $this->attachedFile[ $id ] ), 'the -scaled full must be stamped before restore' );
		$this->assertNotSame( $cleanOriginal, sha1_file( $this->originalPath[ $id ] ), 'the true original must be stamped before restore' );

		$outcome = $proc->restore( $id );
		$this->assertSame( Outcome::RESTORED, $outcome->status );

		// BOTH the -scaled full and the true original are byte-identical to pristine.
		$this->assertSame(
			$cleanScaled,
			sha1_file( $this->attachedFile[ $id ] ),
			'restore must rebuild the clean -scaled full (regenerate overwrites the stamped scaled)'
		);
		$this->assertSame(
			$cleanOriginal,
			sha1_file( $this->originalPath[ $id ] ),
			'restore must put the pristine true original back in place'
		);

		// Lifecycle meta cleared; flag cleared; backup retained.
		$this->assertSame( Meta::STATUS_RESTORED, $this->meta[ $id ][ Meta::STATUS ] );
		$this->assertArrayNotHasKey( Meta::SIGNATURE, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::SIZES_DONE, $this->meta[ $id ] );
		$this->assertArrayNotHasKey( Meta::ENABLED, $this->meta[ $id ], 'undo must also unflag' );
		$this->assertNotNull( Manager::backupPath( $id ) );
		$this->assertTrue( Manager::verify( $id ) );
	}

	// =====================================================================
	// Non-image + not-flagged skips.
	// =====================================================================

	public function testNonImageIsSkipped(): void {
		$id = 60;
		Functions\when( 'wp_attachment_is_image' )->alias( static fn( $arg ) => false );
		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );
		$this->assertSame( Outcome::SKIPPED, $outcome->status );
		$this->assertSame( 'not-an-image', $outcome->reason );
	}

	public function testUnflaggedRegenerateContextIsSkipped(): void {
		$id = 61;
		$this->seedSmallImageAttachment( $id );
		// NOT flagged.
		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'regenerate' );
		$this->assertSame( Outcome::SKIPPED, $outcome->status );
		$this->assertSame( 'not-flagged', $outcome->reason );
		// Nothing was backed up.
		$this->assertNull( Manager::backupPath( $id ) );
	}

	// =====================================================================
	// Big-image case: full (-scaled) AND the distinct true original both stamp.
	// =====================================================================

	public function testBigImageStampsBothScaledFullAndTrueOriginal(): void {
		$id = 70;
		$this->seedBigImageAttachment( $id );
		$this->flag( $id );

		$cleanScaled  = sha1_file( $this->attachedFile[ $id ] );
		$cleanOriginal = sha1_file( $this->originalPath[ $id ] );
		$this->assertNotSame( $this->attachedFile[ $id ], $this->originalPath[ $id ], 'big image must have a distinct -scaled full' );

		$outcome = ( new Processor( $this->fakeRegenerator() ) )->process( $id, 'flag' );
		$this->assertSame( Outcome::WATERMARKED, $outcome->status );

		// BOTH the -scaled full and the true original were stamped (Decision #2).
		$this->assertArrayHasKey( SizeResolver::SLUG_FULL, $outcome->sizesStamped );
		$this->assertArrayHasKey( SizeResolver::SLUG_ORIGINAL, $outcome->sizesStamped );
		$this->assertNotSame( $cleanScaled, sha1_file( $this->attachedFile[ $id ] ), 'the -scaled full must be stamped' );
		$this->assertNotSame( $cleanOriginal, sha1_file( $this->originalPath[ $id ] ), 'the true original must be stamped too' );

		// The backup is the TRUE original's pristine bytes.
		$this->assertSame( $this->pristineHash[ $id ], sha1_file( Manager::backupPath( $id ) ) );
	}

	// =====================================================================
	// Fixtures & helpers.
	// =====================================================================

	/** @var array<int,string> Pristine (clean) sha1 of the source per id. */
	private array $pristineHash = [];

	/** @var array<int,array<string,string>> slug => path for the regenerated sizes per id. */
	private array $sizePaths = [];

	/** @var array<int,array<string,string>> slug => clean sha1 of each regenerated size per id. */
	private array $cleanSizeHash = [];

	/**
	 * Seed a small (single-file) image attachment: the attached file IS the true
	 * original (no big-image -scaled). Writes a real PNG with a known-color
	 * bottom-right corner so the watermark's effect is detectable.
	 */
	private function seedSmallImageAttachment( int $id ): void {
		$path = $this->monthDir . '/' . $id . '-photo.png';
		$this->writeCanvas( $path, self::FULL_W, self::FULL_H );

		$this->attachedFile[ $id ] = $path;
		$this->originalPath[ $id ] = $path; // single-file: full === original.
		$this->pristineHash[ $id ] = sha1_file( $path );

		$this->attachmentMeta[ $id ] = [
			'width'  => self::FULL_W,
			'height' => self::FULL_H,
			'file'   => '2026/06/' . $id . '-photo.png',
			'sizes'  => [], // populated by the fake regenerator.
		];
	}

	/**
	 * Seed a big-image attachment: the attached file is a `-scaled` derivative and
	 * wp_get_original_image_path() returns a DISTINCT, larger true original.
	 */
	private function seedBigImageAttachment( int $id ): void {
		$scaled   = $this->monthDir . '/' . $id . '-photo-scaled.png';
		$original = $this->monthDir . '/' . $id . '-photo.png';
		$this->writeCanvas( $scaled, self::FULL_W, self::FULL_H );
		$this->writeCanvas( $original, 3000, 2000 );

		$this->attachedFile[ $id ] = $scaled;
		$this->originalPath[ $id ] = $original;
		$this->pristineHash[ $id ] = sha1_file( $original );

		// Top-level width/height carry the TRUE original's dimensions so the M3
		// backup Preflight (which cross-checks the resolved original file against the
		// recorded dimensions) accepts the 3000x2000 master. The 'full' SizeResolver
		// target still points at the -scaled file and the engine reads that file's
		// real pixels via getimagesize, so stamping the scaled derivative is correct.
		$this->attachmentMeta[ $id ] = [
			'width'                 => 3000,
			'height'                => 2000,
			'original_image'        => $id . '-photo.png',
			'original_image_width'  => 3000,
			'original_image_height' => 2000,
			'file'                  => '2026/06/' . $id . '-photo-scaled.png',
			'sizes'                 => [],
		];
	}

	/**
	 * Seed a single-file attachment with large pixel dimensions but a tiny on-disk
	 * footprint (solid color), so the Compositor's memory pre-flight can reject the
	 * full as too_large without any real decode/allocation.
	 */
	private function seedLargeDimensionAttachment( int $id, int $w, int $h ): void {
		$path = $this->monthDir . '/' . $id . '-photo.png';
		$im   = imagecreatetruecolor( $w, $h );
		$blue = imagecolorallocate( $im, 20, 40, 200 );
		imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, $blue );
		imagepng( $im, $path );
		unset( $im ); // Free the large canvas before the budget is shrunk.

		$this->attachedFile[ $id ] = $path;
		$this->originalPath[ $id ] = $path;
		$this->pristineHash[ $id ] = sha1_file( $path );

		$this->attachmentMeta[ $id ] = [
			'width'  => $w,
			'height' => $h,
			'file'   => '2026/06/' . $id . '-photo.png',
			'sizes'  => [],
		];
	}

	private function flag( int $id ): void {
		$this->meta[ $id ][ Meta::ENABLED ] = '1';
	}

	/**
	 * A REAL SubsizeRegenerator subclass standing in for core: it (re)writes the
	 * attachment metadata's `sizes` map by copying the just-restored pristine
	 * source into freshly-named size files on disk (clean copies — exactly what
	 * wp_create_image_subsizes produces from a clean original), and returns the
	 * fresh metadata. Recording the clean per-size hashes lets the happy-path test
	 * prove the Processor then changed them.
	 */
	private function fakeRegenerator(): SubsizeRegenerator {
		return new SubsizeRegenerator(
			fn( int $id, string $sourceFile ): array => $this->regenerateClean( $id, $sourceFile )
		);
	}

	/**
	 * The fake regenerate body (public so the anonymous class can reach it):
	 * writes clean `medium` + `thumbnail` copies of $sourceFile next to it and
	 * returns metadata pointing at them. Mirrors core's "regenerate from the clean
	 * in-place original" contract.
	 *
	 * @return array<string,mixed>
	 */
	public function regenerateClean( int $id, string $sourceFile ): array {
		$dir  = dirname( $this->attachedFile[ $id ] );
		$base = $id . '-photo';

		$sizes      = [];
		$this->sizePaths[ $id ]     = [];
		$this->cleanSizeHash[ $id ] = [];

		foreach ( [ 'medium' => [ 600, 400 ], 'thumbnail' => [ 400, 400 ] ] as $slug => $dims ) {
			$file = $base . '-' . $dims[0] . 'x' . $dims[1] . '.png';
			$path = $dir . '/' . $file;
			// Clean copy of the (restored, pristine) source — same bytes as a real
			// regenerate would yield from the clean original at full res; the exact
			// pixels don't matter for the stamping assertion, only that it is CLEAN.
			copy( $sourceFile, $path );

			$sizes[ $slug ] = [
				'file'      => $file,
				'width'     => $dims[0],
				'height'    => $dims[1],
				'mime-type' => 'image/png',
			];
			$this->sizePaths[ $id ][ $slug ]     = $path;
			$this->cleanSizeHash[ $id ][ $slug ] = sha1_file( $path );
		}

		$meta          = $this->attachmentMeta[ $id ];
		$meta['sizes'] = $sizes;
		$this->attachmentMeta[ $id ] = $meta;

		return $meta;
	}

	/**
	 * A big-image fake regenerate: deterministically recreates the CLEAN -scaled full
	 * (a fixed 1200x800 canvas, byte-identical to the seed) at the attached path AND
	 * writes clean intermediates. Because writeCanvas() is deterministic, the -scaled
	 * it regenerates is byte-identical every run — so the restore round-trip can
	 * assert byte-equality of the rebuilt -scaled, the exact big-image invariant
	 * (regenerate, not restoreOriginal, rebuilds the clean -scaled).
	 */
	private function bigImageRegenerator(): SubsizeRegenerator {
		return new SubsizeRegenerator(
			function ( int $id, string $sourceFile ): array {
				// Recreate the clean -scaled full deterministically (core would downscale
				// the restored original; here a fixed canvas stands in, byte-stable).
				$this->writeCanvas( $this->attachedFile[ $id ], self::FULL_W, self::FULL_H );

				$dir   = dirname( $this->attachedFile[ $id ] );
				$base  = $id . '-photo';
				$sizes = [];
				$this->sizePaths[ $id ]     = [];
				$this->cleanSizeHash[ $id ] = [];

				foreach ( [ 'medium' => [ 600, 400 ], 'thumbnail' => [ 400, 400 ] ] as $slug => $dims ) {
					$file = $base . '-' . $dims[0] . 'x' . $dims[1] . '.png';
					$path = $dir . '/' . $file;
					$this->writeCanvas( $path, $dims[0], $dims[1] );
					$sizes[ $slug ] = [
						'file'      => $file,
						'width'     => $dims[0],
						'height'    => $dims[1],
						'mime-type' => 'image/png',
					];
					$this->sizePaths[ $id ][ $slug ]     = $path;
					$this->cleanSizeHash[ $id ][ $slug ] = sha1_file( $path );
				}

				$meta          = $this->attachmentMeta[ $id ];
				$meta['sizes'] = $sizes;
				$this->attachmentMeta[ $id ] = $meta;
				return $meta;
			}
		);
	}

	/**
	 * A fake regenerate where the 'medium' size is GIANT-dimension (so the engine's
	 * memory pre-flight rejects it as too_large) while the full + thumbnail stay
	 * small. Used to prove a too-large INTERMEDIATE does not block watermarked.
	 *
	 * @return array<string,mixed>
	 */
	public function regenerateWithGiantMedium( int $id, string $sourceFile ): array {
		$dir  = dirname( $this->attachedFile[ $id ] );
		$base = $id . '-photo';

		$sizes                      = [];
		$this->sizePaths[ $id ]     = [];
		$this->cleanSizeHash[ $id ] = [];

		// A small, real thumbnail (a clean copy of the source) that stamps fine.
		$thumbFile = $base . '-400x400.png';
		$thumbPath = $dir . '/' . $thumbFile;
		copy( $sourceFile, $thumbPath );
		$sizes['thumbnail'] = [ 'file' => $thumbFile, 'width' => 400, 'height' => 400, 'mime-type' => 'image/png' ];
		$this->sizePaths[ $id ]['thumbnail']     = $thumbPath;
		$this->cleanSizeHash[ $id ]['thumbnail'] = sha1_file( $thumbPath );

		// A giant-DIMENSION 'medium' (tiny on disk, solid color) the pre-flight rejects.
		$medFile = $base . '-5000x4000.png';
		$medPath = $dir . '/' . $medFile;
		$im      = imagecreatetruecolor( 5000, 4000 );
		$blue    = imagecolorallocate( $im, 20, 40, 200 );
		imagefilledrectangle( $im, 0, 0, 4999, 3999, $blue );
		imagepng( $im, $medPath );
		unset( $im );
		$sizes['medium'] = [ 'file' => $medFile, 'width' => 5000, 'height' => 4000, 'mime-type' => 'image/png' ];
		$this->sizePaths[ $id ]['medium']     = $medPath;
		$this->cleanSizeHash[ $id ]['medium'] = sha1_file( $medPath );

		$meta          = $this->attachmentMeta[ $id ];
		$meta['sizes'] = $sizes;
		$this->attachmentMeta[ $id ] = $meta;
		return $meta;
	}

	/**
	 * Write a real PNG canvas with a distinctive solid bottom-right block, so a
	 * watermark composited at bot-right demonstrably changes those pixels.
	 */
	private function writeCanvas( string $path, int $w, int $h ): void {
		$im   = imagecreatetruecolor( $w, $h );
		$blue = imagecolorallocate( $im, 20, 40, 200 );
		imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, $blue );
		// A solid known corner so the stamp's effect is unambiguous.
		$white = imagecolorallocate( $im, 255, 255, 255 );
		imagefilledrectangle( $im, $w - 200, $h - 120, $w - 1, $h - 1, $white );
		imagepng( $im, $path );
		// No imagedestroy(): deprecated no-op on PHP 8+ (suite runs failOnWarning).
	}

	/**
	 * The fake logo: a small solid-red PNG. LogoAsset::resolvePath verifies it is a
	 * genuine PNG via getimagesize, so this is a real on-disk file.
	 */
	private function writeLogo(): string {
		$path = $this->uploadsDir . '/logo.png';
		$im   = imagecreatetruecolor( 200, 80 );
		$red  = imagecolorallocate( $im, 230, 10, 10 );
		imagefilledrectangle( $im, 0, 0, 199, 79, $red );
		imagepng( $im, $path );
		return $path;
	}

	/**
	 * Whether the rendered watermark is visibly present inside the logo's placement
	 * box. The logo is composited at bot-right; a 30%-of-width logo on a 1200px
	 * image is ~360px wide with a 3%-of-shorter-edge (~24px) margin, so it lands
	 * well inside the white corner block. We scan that region for ANY non-white
	 * pixel — the clean canvas there was pure white, so a single colored pixel
	 * proves real pixels were baked in (not just a re-encode hash change).
	 */
	private function cornerPixelChanged( string $path, int $id ): bool {
		$im = imagecreatefrompng( $path );
		if ( false === $im ) {
			return false;
		}
		$w = imagesx( $im );
		$h = imagesy( $im );

		// Sample a grid across the lower-right quadrant (where the logo box sits).
		for ( $x = (int) ( $w * 0.7 ); $x < $w - 25; $x += 7 ) {
			for ( $y = (int) ( $h * 0.55 ); $y < $h - 25; $y += 7 ) {
				$rgb = imagecolorat( $im, $x, $y );
				$r   = ( $rgb >> 16 ) & 0xFF;
				$g   = ( $rgb >> 8 ) & 0xFF;
				$b   = $rgb & 0xFF;
				if ( ! ( 255 === $r && 255 === $g && 255 === $b ) ) {
					return true; // A non-white pixel inside the clean white block.
				}
			}
		}
		return false;
	}

	/** Wire every WordPress function the whole pipeline touches to an in-memory model. */
	private function stubWpFunctions(): void {
		$logoPath = $this->writeLogo();

		Functions\when( 'wp_attachment_is_image' )->alias( static fn( $id ) => (int) $id > 0 );

		// Logo resolution: get_attached_file is used for BOTH the attachment full
		// and the logo id (9001 → the logo PNG).
		Functions\when( 'get_attached_file' )->alias(
			function ( $id ) use ( $logoPath ) {
				$id = (int) $id;
				if ( 9001 === $id ) {
					return $logoPath;
				}
				return $this->attachedFile[ $id ] ?? false;
			}
		);
		Functions\when( 'wp_get_original_image_path' )->alias(
			fn( $id, $unfiltered = false ) => $this->originalPath[ (int) $id ] ?? false
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			fn( $id, $unfiltered = false ) => $this->attachmentMeta[ (int) $id ] ?? false
		);
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $meta ) {
				$this->attachmentMeta[ (int) $id ] = is_array( $meta ) ? $meta : [];
				return true;
			}
		);
		Functions\when( 'wp_check_filetype' )->alias(
			static function ( $file ) {
				$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
				$map = [
					'png'  => 'image/png',
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'webp' => 'image/webp',
					'gif'  => 'image/gif',
				];
				return [
					'ext'  => $ext,
					'type' => $map[ $ext ] ?? '',
				];
			}
		);

		// --- post meta ---
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

		// --- object cache (Lock fast path): atomic store-iff-absent. ---
		// Lock gates its cache backend on a PERSISTENT object cache; we model one
		// (the atomic wp_cache_add store-iff-absent below), so report it present.
		// This must be stubbed unconditionally: once a sibling Lock test mocks this
		// function, Brain Monkey/Patchwork instruments it process-wide, so a test
		// that leaves it unmocked would throw "not defined nor mocked".
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$k = $group . ':' . $key;
				if ( array_key_exists( $k, $this->cache ) ) {
					return false;
				}
				$this->cache[ $k ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key, $group = '' ) => $this->cache[ $group . ':' . $key ] ?? false
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group = '', $ttl = 0 ) {
				$this->cache[ $group . ':' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				unset( $this->cache[ $group . ':' . $key ] );
				return true;
			}
		);

		// --- options (Lock DB fallback + Storage base/token + Options + Caps). ---
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				switch ( $name ) {
					case 'ogwm_settings':
						return $this->settings;
					case 'ogwm_capabilities':
						return $this->realGdCaps();
					case 'ogwm_backup_base':
						return $this->backupBase;
					case 'ogwm_backup_token':
						return 'proctoken123';
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
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

		// --- transients (Manager stats cache). ---
		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $k ) {
				unset( $this->transients[ $k ] );
				return true;
			}
		);

		// --- Storage / BackupWriter helpers. ---
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_generate_password' )->alias(
			static fn( $len = 12, $special = true, $extra = false ) => substr( str_repeat( 'abcdef0123456789', 4 ), 0, (int) $len )
		);
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => $this->uploadsDir ] );
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn( $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0777, true )
		);
		Functions\when( 'sanitize_file_name' )->alias(
			static fn( $name ) => preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name )
		);

		// Faithful pass-throughs for the namespaced copy()/rename() the backup layer
		// uses (Brain Monkey/Patchwork may have instrumented them process-wide via a
		// sibling Backup test, so they MUST be mocked here too — see ManagerTest).
		Functions\when( 'OrchardGrove\OgWatermark\Backup\copy' )->alias(
			static fn( string $s, string $d ): bool => copy( $s, $d )
		);
		Functions\when( 'OrchardGrove\OgWatermark\Backup\rename' )->alias(
			static fn( string $a, string $b ): bool => rename( $a, $b )
		);

		// --- engine token resolution + filters (logo mode needs none of these, but
		//     they are harmless and keep text-mode paths from fataling if reached). ---
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( $url, $component = -1 ) => parse_url( (string) $url, $component )
		);
	}

	/** A real GD capabilities report so Compositor uses the actual GD driver. */
	private function realGdCaps(): array {
		return [
			'driver'     => 'gd',
			'imagick'    => false,
			'gd'         => true,
			'freetype'   => true,
			'formats'    => [
				'image/jpeg' => true,
				'image/png'  => true,
				'image/webp' => true,
				'image/avif' => true,
				'image/gif'  => true,
			],
			'probed_gmt' => '2026-06-29T00:00:00+00:00',
		];
	}

	private static function resetStorageBase(): void {
		( new ReflectionClass( Storage::class ) )->getProperty( 'base' )->setValue( null, null );
	}

	private static function resetCapsMemo(): void {
		( new ReflectionClass( Capabilities::class ) )->getProperty( 'cache' )->setValue( null, null );
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

/**
 * A Watermarker test double whose save() always fails, so Compositor::stamp()
 * returns Result::failed() for every target — used to prove the Processor refuses
 * to report 'watermarked' (or write the completion signature) when the required
 * full file does not stamp.
 */
final class FailingSaveDriver implements \OrchardGrove\OgWatermark\Engine\Watermarker {
	public function load( string $path ): bool {
		$info = @getimagesize( $path );
		$this->w = is_array( $info ) ? (int) $info[0] : 0;
		$this->h = is_array( $info ) ? (int) $info[1] : 0;
		return true;
	}
	public function dimensions(): array {
		return [ $this->w, $this->h ];
	}
	public function paint_logo( string $logoPath, \OrchardGrove\OgWatermark\Engine\Placement $p ): void {}
	public function paint_text( \OrchardGrove\OgWatermark\Engine\TextSpec $t, \OrchardGrove\OgWatermark\Engine\Placement $p ): void {}
	public function flatten( string $hexBg ): void {}
	public function save( string $dest, string $mime, int $quality ): bool {
		return false; // Always fail the encode → Result::failed for the target.
	}
	public function free(): void {}

	private int $w = 0;
	private int $h = 0;
}

/**
 * A decorator driver that delegates EVERYTHING to a real GdDriver — so intermediate
 * targets stamp real pixels — EXCEPT save() to the required full's destination,
 * which it forces to fail. The full target's temp dest is the attached file path
 * (no `-WxH` size suffix) plus the `.ogwm-<token>.tmp` staging suffix; intermediates
 * carry a `-NNNxNNN` suffix. This isolates the partial-stamp resume path: a lesser
 * size commits while the required full does NOT, so the Processor must withhold the
 * signature and report failed with the partial sizes_done map.
 */
final class FullOnlyFailDriver implements \OrchardGrove\OgWatermark\Engine\Watermarker {
	private \OrchardGrove\OgWatermark\Engine\GdDriver $gd;

	public function __construct() {
		$this->gd = new \OrchardGrove\OgWatermark\Engine\GdDriver();
	}
	public function load( string $path ): bool {
		return $this->gd->load( $path );
	}
	public function dimensions(): array {
		return $this->gd->dimensions();
	}
	public function paint_logo( string $logoPath, \OrchardGrove\OgWatermark\Engine\Placement $p ): void {
		$this->gd->paint_logo( $logoPath, $p );
	}
	public function paint_text( \OrchardGrove\OgWatermark\Engine\TextSpec $t, \OrchardGrove\OgWatermark\Engine\Placement $p ): void {
		$this->gd->paint_text( $t, $p );
	}
	public function flatten( string $hexBg ): void {
		$this->gd->flatten( $hexBg );
	}
	public function save( string $dest, string $mime, int $quality ): bool {
		// Fail ONLY the full target (no `-WxH` size suffix before the .png/.ogwm temp).
		if ( 1 !== preg_match( '/-\d+x\d+\.[a-z]+\.ogwm-/', $dest ) ) {
			return false;
		}
		return $this->gd->save( $dest, $mime, $quality );
	}
	public function free(): void {
		$this->gd->free();
	}
}

/**
 * A driver whose save() THROWS, to prove the per-target try/finally in
 * stampTargets() sweeps the staging temp even when an exception escapes the stamp
 * (a future-proofing regression guard — today Compositor catches Throwable, but the
 * cleanup guarantee must not rest on that incidental behavior).
 */
final class ThrowingSaveDriver implements \OrchardGrove\OgWatermark\Engine\Watermarker {
	public function load( string $path ): bool {
		$info = @getimagesize( $path );
		$this->w = is_array( $info ) ? (int) $info[0] : 0;
		$this->h = is_array( $info ) ? (int) $info[1] : 0;
		return true;
	}
	public function dimensions(): array {
		return [ $this->w, $this->h ];
	}
	public function paint_logo( string $logoPath, \OrchardGrove\OgWatermark\Engine\Placement $p ): void {}
	public function paint_text( \OrchardGrove\OgWatermark\Engine\TextSpec $t, \OrchardGrove\OgWatermark\Engine\Placement $p ): void {}
	public function flatten( string $hexBg ): void {}
	public function save( string $dest, string $mime, int $quality ): bool {
		// Stage a real temp first (so there is something to clean up), then throw.
		file_put_contents( $dest, 'partial' );
		throw new \RuntimeException( 'save exploded mid-encode' );
	}
	public function free(): void {}

	private int $w = 0;
	private int $h = 0;
}
