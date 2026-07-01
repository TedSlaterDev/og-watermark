<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Small immutable status object returned by Compositor::stamp() for one file.
 *
 * The factories map onto the outcomes the processor (M4) records as the per-size
 * result: a successful stamp, a deliberate format-policy skip (with a human
 * reason), a memory pre-flight bail, an image too small to carry a mark, or a
 * hard failure. The reason string is safe to log/surface; it never contains a
 * filesystem path.
 */
final class Result {

	public const STAMPED   = 'stamped';
	public const SKIPPED   = 'skipped';
	public const TOO_LARGE = 'too_large';
	public const TOO_SMALL = 'too_small';
	public const FAILED    = 'failed';

	private function __construct(
		public readonly string $status,
		public readonly string $reason
	) {}

	/** The file was watermarked and written to the destination. */
	public static function stamped(): self {
		return new self( self::STAMPED, '' );
	}

	/** Intentionally not stamped per format policy (e.g. SVG, animated GIF). */
	public static function skipped( string $reason ): self {
		return new self( self::SKIPPED, $reason );
	}

	/** Skipped because the decode would exceed the memory budget for this host. */
	public static function tooLarge(): self {
		return new self( self::TOO_LARGE, 'image exceeds memory budget' );
	}

	/**
	 * Not stamped because the image is too small to carry the mark even after
	 * shrink-to-fit (a benign, expected outcome for tiny images — NOT a failure).
	 */
	public static function tooSmall( string $reason ): self {
		return new self( self::TOO_SMALL, $reason );
	}

	/** A real failure (bad decode, unwritable destination, driver error). */
	public static function failed( string $reason ): self {
		return new self( self::FAILED, $reason );
	}

	/** Whether the file was actually stamped. */
	public function isOk(): bool {
		return self::STAMPED === $this->status;
	}
}
