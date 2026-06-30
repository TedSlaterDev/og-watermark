<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Backup;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Support\Meta;

/**
 * Pre-flight validation gate for the backup writer.
 *
 * This is the load-bearing offload/stub defense (SPEC "Pre-flight validation
 * before backup"). Before a single byte of a pristine original is copied into
 * the immutable backup, the resolved source path must be proven to be a genuine
 * local raster image whose dimensions agree with the attachment metadata — not
 * a zero-byte placeholder, a tiny offload stub, or a non-image. The cardinal
 * sin downstream is promoting a watermarked or fabricated file into the backup;
 * an equally bad failure mode is treating an offloaded stub as a clean master.
 *
 * Two distinct negative outcomes are reported so the orchestrator can react
 * correctly:
 *   - `skipped_offloaded` — ambiguous / probably-not-really-here (missing,
 *     zero-byte, too small, or dimensions that don't match the metadata). This
 *     is the canonical signature of an offloaded original whose on-disk file is
 *     a stub. We must NOT back it up and must NOT treat it as a hard error.
 *   - `failed` — the path is present but is provably NOT a usable image
 *     (getimagesize() rejects it, e.g. a text file masquerading as an image).
 *
 * No WordPress calls beyond the Meta constants — pure, real-FS testable.
 */
final class Preflight {

	/**
	 * Success status returned by validateLocalOriginal(): a "proceed" sentinel.
	 * It is intentionally NOT a Meta::STATUS_* — this gate only authorizes the
	 * copy; the orchestrator sets the attachment's real lifecycle status later.
	 */
	public const STATUS_OK = 'ok';

	/**
	 * Smallest plausible byte size for a real raster original. Anything at or
	 * below this is treated as a stub/placeholder rather than a genuine master.
	 * A handful of bytes can never be a legitimate JPEG/PNG/WebP, and offload
	 * plugins frequently leave a near-empty file behind.
	 */
	private const MIN_BYTES = 256;

	/**
	 * Allowed per-axis dimension drift between the on-disk file and the stored
	 * attachment metadata, as a fraction of the metadata value. A genuine
	 * original matches its metadata exactly; this small tolerance only absorbs
	 * off-by-one rounding, never the order-of-magnitude gap of a stub thumbnail.
	 */
	private const DIM_TOLERANCE = 0.02;

	/**
	 * Validate that $path is a genuine, locally-present original safe to back up.
	 *
	 * @param string               $path Absolute path to the resolved original.
	 * @param array<string, mixed> $meta `_wp_attachment_metadata` (or any array
	 *                                    carrying top-level `width`/`height`).
	 *
	 * @return array{ok: bool, reason: string, status: string} `status` is the
	 *         literal 'ok' on success (a proceed sentinel — the caller, not this
	 *         gate, decides the final attachment status), or one of
	 *         Meta::STATUS_SKIPPED_OFFLOADED / Meta::STATUS_FAILED on rejection.
	 */
	public static function validateLocalOriginal( string $path, array $meta ): array {
		if ( '' === $path ) {
			return self::offloaded( 'Empty original path; treating as offloaded/missing.' );
		}

		// realpath() resolves symlinks and confirms the file is actually present.
		// A missing file is the classic offload stub signature, not a hard error.
		$real = realpath( $path );
		if ( false === $real ) {
			return self::offloaded( 'Original path does not resolve on disk (offloaded or missing).' );
		}

		if ( ! is_file( $real ) ) {
			return self::offloaded( 'Resolved original is not a regular file.' );
		}

		if ( ! is_readable( $real ) ) {
			// Present but unreadable: we genuinely cannot derive a clean master.
			return self::failed( 'Original file exists but is not readable.' );
		}

		// A zero-byte / near-empty file cannot be a real image — offload stub.
		$bytes = @filesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false becomes a clean skip below.
		if ( false === $bytes || $bytes <= self::MIN_BYTES ) {
			return self::offloaded(
				sprintf(
					'Original is %s bytes — below the %d-byte floor for a real image (offloaded stub).',
					false === $bytes ? 'an unreadable number of' : (string) $bytes,
					self::MIN_BYTES
				)
			);
		}

		// getimagesize() is the authoritative "is this really a raster image?"
		// gate. A non-image (e.g. a text placeholder) returns false => failed.
		$info = @getimagesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false handled explicitly.
		if ( false === $info || ! isset( $info[0], $info[1] ) ) {
			return self::failed( 'getimagesize() rejected the file — not a decodable image.' );
		}

		$fileWidth  = (int) $info[0];
		$fileHeight = (int) $info[1];
		if ( $fileWidth <= 0 || $fileHeight <= 0 ) {
			return self::failed( 'Image reports non-positive dimensions.' );
		}

		// Dimension cross-check — THE key offloaded-stub defense. When the
		// attachment metadata records width/height, a genuine on-disk original
		// matches them; an offload stub (a tiny placeholder) does not.
		$metaWidth  = self::dimensionFromMeta( $meta, 'width' );
		$metaHeight = self::dimensionFromMeta( $meta, 'height' );

		if ( null !== $metaWidth && null !== $metaHeight ) {
			if (
				! self::dimensionsAgree( $fileWidth, $metaWidth )
				|| ! self::dimensionsAgree( $fileHeight, $metaHeight )
			) {
				return self::offloaded(
					sprintf(
						'On-disk dimensions %dx%d do not match metadata %dx%d (offloaded stub).',
						$fileWidth,
						$fileHeight,
						$metaWidth,
						$metaHeight
					)
				);
			}
		}

		return [
			'ok'     => true,
			'reason' => '',
			'status' => self::STATUS_OK,
		];
	}

	/**
	 * Whether $dir's filesystem has room for a $bytes backup plus a safety
	 * margin. Conservatively returns true when free space is indeterminate
	 * (disk_free_space() false / unsupported) — we never block a backup just
	 * because we cannot measure the disk.
	 *
	 * @param int    $bytes Projected backup size in bytes.
	 * @param string $dir   Directory the backup will be written into.
	 */
	public static function hasDiskSpaceFor( int $bytes, string $dir ): bool {
		if ( $bytes <= 0 ) {
			return true;
		}

		// Some managed hosts (e.g. WordKeeper) DISABLE disk_free_space() via
		// disable_functions. A disabled function resolves as undefined and FATALS when
		// called — the @ operator does NOT suppress that. Guard with function_exists
		// and treat an unmeasurable disk as "room to spare" (same as a false result).
		if ( ! function_exists( 'disk_free_space' ) ) {
			return true;
		}

		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false handled below.
		if ( false === $free ) {
			// Cannot determine free space — do not block. The mandatory post-copy
			// sha1 verification in BackupWriter still catches a truncated write.
			return true;
		}

		// Reserve for the WORST-CASE peak of the transactional write. The work file
		// is always written at "<dest>.partial" on the DESTINATION filesystem; on
		// the cross-filesystem commit path the partial and the final dest coexist
		// on that volume for the whole copy (the partial is only unlinked AFTER the
		// dest is fully streamed), so peak usage is ~2x the file size — not ~1.1x.
		// We therefore reserve ~2x plus a fixed floor unconditionally (the same-FS
		// rename path simply has the headroom to spare). This keeps the disk-full
		// case an honest pre-copy "no" rather than a mid-stream short-write abort.
		$required = ( (float) $bytes * 2.1 ) + self::FREE_SPACE_FLOOR;

		return (float) $free > $required;
	}

	/** Fixed minimum free-space cushion (1 MiB) beyond the projected backup. */
	private const FREE_SPACE_FLOOR = 1048576;

	/**
	 * Pull a positive integer dimension from the metadata array. Returns null
	 * when absent or non-positive, so the dimension cross-check is simply skipped
	 * (we never invent a constraint we cannot trust).
	 *
	 * @param array<string, mixed> $meta
	 * @param string               $key  'width' or 'height'.
	 */
	private static function dimensionFromMeta( array $meta, string $key ): ?int {
		if ( ! isset( $meta[ $key ] ) || ! is_numeric( $meta[ $key ] ) ) {
			return null;
		}

		$value = (int) $meta[ $key ];

		return $value > 0 ? $value : null;
	}

	/**
	 * True when an on-disk axis length is within DIM_TOLERANCE of the metadata
	 * value (with a minimum 1px slack to absorb pure rounding on tiny images).
	 */
	private static function dimensionsAgree( int $fileDim, int $metaDim ): bool {
		$allowed = max( 1.0, (float) $metaDim * self::DIM_TOLERANCE );

		return abs( $fileDim - $metaDim ) <= $allowed;
	}

	/**
	 * @return array{ok: bool, reason: string, status: string}
	 */
	private static function offloaded( string $reason ): array {
		return [
			'ok'     => false,
			'reason' => $reason,
			'status' => Meta::STATUS_SKIPPED_OFFLOADED,
		];
	}

	/**
	 * @return array{ok: bool, reason: string, status: string}
	 */
	private static function failed( string $reason ): array {
		return [
			'ok'     => false,
			'reason' => $reason,
			'status' => Meta::STATUS_FAILED,
		];
	}
}
