<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of a Processor::process()/restore() run for one attachment.
 *
 * Mirrors the small-value-object pattern of {@see \OrchardGrove\OgWatermark\Engine\Result},
 * but at the orchestration level: where Result describes ONE file's stamp,
 * Outcome describes the WHOLE per-attachment operation. The status maps onto the
 * SPEC's lifecycle terminals so M5/M6/M7 (and the Media-column UI) can branch on
 * a single value without re-reading meta.
 *
 * Statuses:
 *  - watermarked    — the full/-scaled (and original, when present) actually stamped.
 *  - noop           — signature matched and every recorded size still exists; nothing rebuilt.
 *  - skipped        — not an image, or a regenerate/settings run on an unflagged attachment.
 *  - failed         — a hard error (regenerate failure, or the highest-value file failed to stamp).
 *  - backup_missing — no valid pristine backup, so nothing may be (re)derived.
 *  - too_large      — the full/-scaled file exceeded the memory budget; not watermarked.
 *  - locked         — another worker holds the per-attachment lock; try later.
 *  - restored       — the pristine original + clean sizes were put back and meta cleared.
 *
 * The reason string is human-/log-safe and never carries a filesystem path.
 * sizesStamped maps a size slug (incl. the 'full'/'original' pseudo-slugs) to the
 * unix time it was committed, for audit/resume.
 */
final class Outcome {

	public const WATERMARKED    = 'watermarked';
	public const NOOP           = 'noop';
	public const SKIPPED        = 'skipped';
	public const FAILED         = 'failed';
	public const BACKUP_MISSING = 'backup_missing';
	public const TOO_LARGE      = 'too_large';
	public const LOCKED         = 'locked';
	public const RESTORED       = 'restored';

	/**
	 * @param string             $status       One of the class constants above.
	 * @param string             $reason       Human-/log-safe reason code (no paths).
	 * @param array<string,int>  $sizesStamped slug => committed-at unix time.
	 */
	private function __construct(
		public readonly string $status,
		public readonly string $reason,
		public readonly array $sizesStamped
	) {}

	/**
	 * Every required target stamped; the attachment is watermarked.
	 *
	 * @param array<string,int> $sizesStamped slug => committed-at unix time.
	 */
	public static function watermarked( array $sizesStamped ): self {
		return new self( self::WATERMARKED, '', $sizesStamped );
	}

	/** Signature matched and recorded sizes still exist — no work was done. */
	public static function noop(): self {
		return new self( self::NOOP, 'signature-match', [] );
	}

	/** Deliberately not processed (non-image, or unflagged on a regenerate/settings run). */
	public static function skipped( string $reason ): self {
		return new self( self::SKIPPED, $reason, [] );
	}

	/**
	 * A hard failure. Any sizes that DID stamp before the failure are reported so
	 * a resume can finish only the missing targets.
	 *
	 * @param array<string,int> $sizesStamped slug => committed-at unix time.
	 */
	public static function failed( string $reason, array $sizesStamped = [] ): self {
		return new self( self::FAILED, $reason, $sizesStamped );
	}

	/** No valid pristine backup — refuse to (re)process. */
	public static function backupMissing( string $reason = 'no-valid-backup' ): self {
		return new self( self::BACKUP_MISSING, $reason, [] );
	}

	/**
	 * The full/-scaled (highest-value) file exceeded the memory budget, so the
	 * attachment must NOT be reported watermarked.
	 *
	 * @param array<string,int> $sizesStamped Any lesser sizes that did stamp.
	 */
	public static function tooLarge( array $sizesStamped = [] ): self {
		return new self( self::TOO_LARGE, 'full-image-too-large', $sizesStamped );
	}

	/** Another worker holds the per-attachment lock. */
	public static function locked(): self {
		return new self( self::LOCKED, 'locked-by-another-worker', [] );
	}

	/** The pristine original + clean sizes were restored and lifecycle meta cleared. */
	public static function restored(): self {
		return new self( self::RESTORED, '', [] );
	}

	/** Whether the operation reached a "good" terminal (watermarked, noop, or restored). */
	public function isOk(): bool {
		return in_array( $this->status, [ self::WATERMARKED, self::NOOP, self::RESTORED ], true );
	}
}
