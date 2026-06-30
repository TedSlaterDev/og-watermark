<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical post-meta keys and status values for watermarked attachments.
 *
 * Pure constants/helpers only — this class performs NO database access. Every
 * read/write of these keys happens through the processor/backup layers (M3+),
 * so the key names live in exactly one place and the rest of the plugin agrees.
 *
 * All keys are prefixed `_ogwm_` so WordPress treats them as protected meta
 * (hidden from the Custom Fields UI).
 */
final class Meta {

	/** Opt-in trigger flag. Stored as the string '1' when an attachment is flagged. */
	public const ENABLED = '_ogwm_enabled';

	/** Lifecycle status — one of the STATUS_* values below. */
	public const STATUS = '_ogwm_status';

	/** Canonical idempotency hash of the last applied stamp (see Signature). */
	public const SIGNATURE = '_ogwm_signature';

	/** Pristine backup path, relative to its storage base (portable across host moves). */
	public const BACKUP_REL = '_ogwm_backup_rel';

	/** sha1 of the pristine backup at creation time (integrity gate). */
	public const BACKUP_HASH = '_ogwm_backup_hash';

	/** Backup size in bytes (integrity sanity + storage reporting). */
	public const BACKUP_BYTES = '_ogwm_backup_bytes';

	/** Map of size-slugs actually stamped (audit + resume). */
	public const SIZES_DONE = '_ogwm_sizes_done';

	/** Timestamp (GMT) of the last successful apply. */
	public const APPLIED_GMT = '_ogwm_applied_gmt';

	/** Last failure message, surfaced in the Media list column. */
	public const LAST_ERROR = '_ogwm_last_error';

	/** Never processed / not flagged. */
	public const STATUS_NONE = 'none';

	/** Flagged and waiting on the bulk queue. */
	public const STATUS_QUEUED = 'queued';

	/** Successfully watermarked. */
	public const STATUS_WATERMARKED = 'watermarked';

	/** Processing failed (see LAST_ERROR). */
	public const STATUS_FAILED = 'failed';

	/** Restored from the pristine backup (watermark removed). */
	public const STATUS_RESTORED = 'restored';

	/** Original lives off-site (e.g. an offload plugin) — cannot be backed up safely. */
	public const STATUS_SKIPPED_OFFLOADED = 'skipped_offloaded';

	/** A backup was expected but is gone — refuse to (re)process. */
	public const STATUS_BACKUP_MISSING = 'backup_missing';

	/** Image exceeds the memory/size budget for this host. */
	public const STATUS_TOO_LARGE = 'too_large';

	/**
	 * Every meta key this plugin owns. Used by uninstall (LIKE cleanup is the
	 * primary path) and by audit/reporting code that needs to enumerate keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return [
			self::ENABLED,
			self::STATUS,
			self::SIGNATURE,
			self::BACKUP_REL,
			self::BACKUP_HASH,
			self::BACKUP_BYTES,
			self::SIZES_DONE,
			self::APPLIED_GMT,
			self::LAST_ERROR,
		];
	}

	/**
	 * Every valid value for the STATUS meta key.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return [
			self::STATUS_NONE,
			self::STATUS_QUEUED,
			self::STATUS_WATERMARKED,
			self::STATUS_FAILED,
			self::STATUS_RESTORED,
			self::STATUS_SKIPPED_OFFLOADED,
			self::STATUS_BACKUP_MISSING,
			self::STATUS_TOO_LARGE,
		];
	}
}
