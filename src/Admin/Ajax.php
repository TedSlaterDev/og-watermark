<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Pipeline\Outcome;
use OrchardGrove\OgWatermark\Pipeline\Processor;
use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Support\Meta;

/**
 * The secured admin-AJAX layer (SPEC M7 "AJAX endpoints, caps, nonces" + the
 * "Security checklist").
 *
 * SECURITY IS THE WHOLE POINT of this class. Every handler enforces, in this
 * EXACT order:
 *
 *   1. VALIDATE THE ID  — absint + get_post_type === 'attachment' +
 *      wp_attachment_is_image (only for the per-image handlers that act on an id).
 *   2. CAPABILITY        — the per-object current_user_can('edit_post', $id) for
 *      apply/remove (this is the IDOR guard — an author who can upload still must
 *      not strip watermarks off ANOTHER author's image), and manage_options for
 *      the settings/bulk/preview handlers.
 *   3. NONCE             — check_ajax_referer('ogwm_admin','nonce').
 *
 * The cap is checked BEFORE the nonce on purpose (SPEC: "Cap check FIRST, then
 * nonce"): a logged-out / under-privileged caller is rejected without us doing
 * any nonce work, and the nonce then proves intent for an already-authorized
 * user. Putting the id-validation first means a malformed/foreign id is rejected
 * before we even ask "can this user edit it" — and crucially, the edit_post check
 * runs against the SUBMITTED id, so id 0 / another user's attachment fails the
 * capability gate (the IDOR is closed at step 2, not merely at step 1).
 *
 * ALL output is escaped: badge fragments go through {@see StatusBadge::kses()}
 * (a single <span class>), messages through esc_html, and NO handler ever echoes
 * a filesystem/temp path — the preview returns a base64 data URL and a path-free
 * reason code on failure.
 */
final class Ajax {

	/** The shared nonce action for every admin-AJAX call (also the JS localize key). */
	public const NONCE_ACTION = 'ogwm_admin';

	/** The POST field carrying the nonce (check_ajax_referer's $query_arg). */
	public const NONCE_FIELD = 'nonce';

	/**
	 * The bulk-run action names, shared with bulk.js via {@see BulkPage}'s localize
	 * payload so a rename here can never silently desync the poller (mirroring how
	 * NONCE_ACTION is already shared).
	 */
	public const ACTION_BULK_START    = 'ogwm_bulk_start';
	public const ACTION_BULK_PROGRESS = 'ogwm_bulk_progress';

	/** Preview rate-limit transient prefix; the per-user key appends the user id. */
	private const PREVIEW_RL_PREFIX = 'ogwm_preview_rl_';

	/** Minimum seconds between two preview renders for a single user (server-side throttle). */
	private const PREVIEW_RL_SECONDS = 2;

	/**
	 * Test seam mirroring {@see \OrchardGrove\OgWatermark\Queue\Scheduler::$processorFactory}:
	 * lets a test inject a fake Processor so the apply/remove handlers can be
	 * exercised (and the IDOR/nonce ordering asserted) without the real M2/M3
	 * engine + backup stack. Defaults to a real {@see Processor}. Typed `object`
	 * because Processor is `final` and cannot be subclassed; the fake duck-types
	 * process()/restore() returning an {@see Outcome}.
	 *
	 * @var callable():object|null
	 */
	public static $processorFactory = null;

	/**
	 * Register every wp_ajax_ handler. Wired only for logged-in users (no
	 * wp_ajax_nopriv_* — these are all privileged admin actions); the cap check
	 * inside each handler is the real authorization, the hook registration just
	 * routes the request.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_ogwm_apply_single', [ self::class, 'applySingle' ] );
		add_action( 'wp_ajax_ogwm_remove_single', [ self::class, 'removeSingle' ] );
		add_action( 'wp_ajax_' . self::ACTION_BULK_START, [ self::class, 'bulkStart' ] );
		add_action( 'wp_ajax_' . self::ACTION_BULK_PROGRESS, [ self::class, 'bulkProgress' ] );
		add_action( 'wp_ajax_ogwm_preview', [ self::class, 'preview' ] );
	}

	// =====================================================================
	// Per-image handlers — edit_post($id) cap (the IDOR guard).
	// =====================================================================

	/**
	 * Flag + apply a single attachment.
	 *
	 * Order: validate id → edit_post($id) → nonce → set the opt-in flag → run the
	 * Processor in the 'flag' context → return {status, badge_html, message}. The
	 * badge is wp_kses'd; the message is esc_html'd.
	 */
	public static function applySingle(): void {
		$id = self::requireImageId();
		self::requireEditPost( $id );
		self::requireNonce();

		// Set the opt-in flag FIRST so the Processor's flag-context run is allowed to
		// stamp a not-yet-flagged attachment (and so a no-JS bulk later finds it).
		update_post_meta( $id, Meta::ENABLED, '1' );

		$outcome = self::processor()->process( $id, 'flag' );

		wp_send_json_success(
			[
				'status'     => esc_html( $outcome->status ),
				'badge_html' => StatusBadge::kses( StatusBadge::forAttachment( $id ) ),
				'message'    => esc_html( self::applyMessage( $outcome ) ),
			]
		);
	}

	/**
	 * Remove the watermark from a single attachment (restore the pristine original).
	 *
	 * Order: validate id → edit_post($id) → nonce → Processor::restore → return
	 * {status, badge_html}. restore() also clears the opt-in flag.
	 */
	public static function removeSingle(): void {
		$id = self::requireImageId();
		self::requireEditPost( $id );
		self::requireNonce();

		$outcome = self::processor()->restore( $id );

		wp_send_json_success(
			[
				'status'     => esc_html( $outcome->status ),
				'badge_html' => StatusBadge::kses( StatusBadge::forAttachment( $id ) ),
				'message'    => esc_html( self::removeMessage( $outcome ) ),
			]
		);
	}

	// =====================================================================
	// Settings/bulk/preview handlers — manage_options cap.
	// =====================================================================

	/**
	 * Start (or resume) a bulk run over the requested scope.
	 *
	 * Order: cap manage_options → nonce → BulkRunner::start(scope). No id to
	 * validate (the scope is a whitelisted enum, normalized inside BulkRunner).
	 * Returns {ok,total} or {ok:false,reason}. NEVER stamps inline — start() only
	 * seeds the queue + kicks the background worker.
	 */
	public static function bulkStart(): void {
		self::requireManageOptions();
		self::requireNonce();

		$scope = isset( $_POST['scope'] ) ? sanitize_key( (string) wp_unslash( $_POST['scope'] ) ) : 'flagged'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce was already verified by requireNonce() above; PHPCS cannot see the extracted check.

		$result = BulkRunner::start( $scope );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error(
				[
					'reason'   => esc_html( isset( $result['reason'] ) ? (string) $result['reason'] : 'start-failed' ),
					'message'  => esc_html( self::startErrorMessage( $result ) ),
					'estimate' => isset( $result['estimate'] ) ? (int) $result['estimate'] : 0,
				]
			);
		}

		wp_send_json_success(
			[
				'ok'    => true,
				'total' => isset( $result['total'] ) ? (int) $result['total'] : 0,
			]
		);
	}

	/**
	 * READ-ONLY bulk progress poll for bulk.js.
	 *
	 * Order: cap manage_options → nonce → BulkRunner::progress() (a pure read; it
	 * never writes or locks). Returns the counters the progress bar fills.
	 */
	public static function bulkProgress(): void {
		self::requireManageOptions();
		self::requireNonce();

		$p = BulkRunner::progress();

		wp_send_json_success(
			[
				'running' => ! empty( $p['running'] ),
				'total'   => isset( $p['total'] ) ? (int) $p['total'] : 0,
				'done'    => isset( $p['done'] ) ? (int) $p['done'] : 0,
				'failed'  => isset( $p['failed'] ) ? (int) $p['failed'] : 0,
			]
		);
	}

	/**
	 * Live settings preview.
	 *
	 * Order: cap manage_options → nonce → per-user rate-limit → render with the
	 * REAL engine on a GENERATED capped sample using the POSTed (unsaved) settings
	 * run through Sanitizer FIRST ({@see Preview}). Returns {img_url} as a base64
	 * data URL; on failure a path-free reason code (NO server path ever echoed).
	 */
	public static function preview(): void {
		self::requireManageOptions();
		self::requireNonce();

		// Server-side throttle (the client also debounces). A second rapid call
		// within the window is refused so a direct-POST loop can't pin the CPU.
		if ( self::previewRateLimited() ) {
			wp_send_json_error(
				[
					'reason'  => 'rate-limited',
					'message' => esc_html__( 'Slow down a moment — the preview is rate-limited.', 'og-watermark' ),
				]
			);
		}
		self::markPreviewRendered();

		// The settings-page JS posts the WHOLE settings form, whose fields are named
		// `ogwm_settings[group][key]` (Options::OPTION), so the unsaved values arrive
		// under $_POST['ogwm_settings']. We accept a bare 'settings' key too as a
		// convenience for a direct caller. Either way the raw array is untrusted and
		// is run through the production Sanitizer inside Preview::render() before the
		// engine ever sees it.
		$raw = self::rawSettingsPost();

		$result = Preview::render( $raw );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error(
				[
					'reason'  => esc_html( isset( $result['reason'] ) ? (string) $result['reason'] : 'preview-failed' ),
					'message' => esc_html__( 'Could not render a preview with these settings.', 'og-watermark' ),
				]
			);
		}

		// The data URL is "data:image/png;base64,<…>" — wholly plugin-generated, no
		// path. esc_url keeps it safe to drop straight into an <img src>.
		wp_send_json_success(
			[
				'img_url' => esc_url_raw( isset( $result['data_url'] ) ? (string) $result['data_url'] : '' ),
			]
		);
	}

	// =====================================================================
	// Guard primitives — each emits a JSON error + dies on failure.
	// =====================================================================

	/**
	 * VALIDATE THE ID: absint the POSTed id and require it to be a real image
	 * attachment. Returns the validated id, or sends a JSON error and dies.
	 *
	 * This runs FIRST so a malformed/foreign id is rejected before any other work;
	 * the edit_post check that follows then runs against THIS id, closing the IDOR.
	 */
	private static function requireImageId(): int {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is checked AFTER the cap, per the SPEC ordering; this is pure input validation.

		if ( $id <= 0
			|| 'attachment' !== get_post_type( $id )
			|| ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error(
				[
					'reason'  => 'invalid-attachment',
					'message' => esc_html__( 'That is not a valid image attachment.', 'og-watermark' ),
				]
			);
		}

		return $id;
	}

	/**
	 * CAPABILITY (per-object): the current user must be able to edit THIS specific
	 * attachment. This is the IDOR guard — edit_post($id) maps an author to only
	 * their own uploads, so POSTing someone else's id (or 0) is rejected here even
	 * though they hold upload_files. Sends a JSON error + dies on failure.
	 */
	private static function requireEditPost( int $id ): void {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			self::denyForbidden();
		}
	}

	/** CAPABILITY (global): manage_options for settings/bulk/preview. */
	private static function requireManageOptions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::denyForbidden();
		}
	}

	/**
	 * NONCE: check_ajax_referer('ogwm_admin','nonce'). Runs AFTER the cap check.
	 * The third arg false makes it return rather than die, so we emit a UNIFORM
	 * JSON error shape on a bad/missing nonce instead of WP's bare -1.
	 */
	private static function requireNonce(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error(
				[
					'reason'  => 'bad-nonce',
					'message' => esc_html__( 'Your session expired. Reload the page and try again.', 'og-watermark' ),
				]
			);
		}
	}

	/** Uniform 403 forbidden response. */
	private static function denyForbidden(): void {
		wp_send_json_error(
			[
				'reason'  => 'forbidden',
				'message' => esc_html__( 'You are not allowed to do that.', 'og-watermark' ),
			]
		);
	}

	// =====================================================================
	// Preview rate-limit (short per-user transient).
	// =====================================================================

	/**
	 * The untrusted, unsaved settings array POSTed for a preview. Reads the form's
	 * native `ogwm_settings` key first (the settings page submits the whole form),
	 * then a bare `settings` key as a fallback. NEVER trusted here — the caller
	 * hands it straight to Preview::render(), which runs the production Sanitizer.
	 *
	 * @return array<string,mixed>
	 */
	private static function rawSettingsPost(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by requireNonce() upstream; Sanitizer::sanitize() runs on this array inside Preview::render() before the engine sees it.
		if ( isset( $_POST[ Options::OPTION ] ) ) {
			$raw = wp_unslash( $_POST[ Options::OPTION ] );
		} elseif ( isset( $_POST['settings'] ) ) {
			$raw = wp_unslash( $_POST['settings'] );
		} else {
			$raw = [];
		}
		// phpcs:enable

		return is_array( $raw ) ? $raw : [];
	}

	/** Whether THIS user rendered a preview within the throttle window. */
	private static function previewRateLimited(): bool {
		return false !== get_transient( self::previewRlKey() );
	}

	/** Stamp the throttle window for THIS user. */
	private static function markPreviewRendered(): void {
		set_transient( self::previewRlKey(), 1, self::PREVIEW_RL_SECONDS );
	}

	/** Per-user rate-limit transient key. */
	private static function previewRlKey(): string {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return self::PREVIEW_RL_PREFIX . $uid;
	}

	// =====================================================================
	// Processor construction (test seam) + message mapping.
	// =====================================================================

	/** Build the Processor via the injectable factory (real one in production). */
	private static function processor(): object {
		$factory = self::$processorFactory;
		if ( is_callable( $factory ) ) {
			return $factory();
		}
		return new Processor();
	}

	/** A short, human, translated message for an apply outcome. */
	private static function applyMessage( Outcome $outcome ): string {
		switch ( $outcome->status ) {
			case Outcome::WATERMARKED:
				return __( 'Watermark applied.', 'og-watermark' );
			case Outcome::NOOP:
				return __( 'Already up to date.', 'og-watermark' );
			case Outcome::LOCKED:
				return __( 'This image is being processed — try again shortly.', 'og-watermark' );
			case Outcome::TOO_LARGE:
				return __( 'This image is too large to watermark on this server.', 'og-watermark' );
			case Outcome::BACKUP_MISSING:
				return __( 'No clean backup is available, so this image cannot be watermarked.', 'og-watermark' );
			case Outcome::SKIPPED:
				return __( 'This attachment was skipped.', 'og-watermark' );
			case Outcome::FAILED:
			default:
				return __( 'Watermarking failed. See the status column for details.', 'og-watermark' );
		}
	}

	/** A short, human, translated message for a remove/restore outcome. */
	private static function removeMessage( Outcome $outcome ): string {
		switch ( $outcome->status ) {
			case Outcome::RESTORED:
				return __( 'Watermark removed; the original was restored.', 'og-watermark' );
			case Outcome::LOCKED:
				return __( 'This image is being processed — try again shortly.', 'og-watermark' );
			case Outcome::BACKUP_MISSING:
				return __( 'No backup is available to restore from.', 'og-watermark' );
			default:
				return __( 'Could not remove the watermark.', 'og-watermark' );
		}
	}

	/**
	 * A human message for a failed bulk start.
	 *
	 * @param array{ok:bool,reason?:string,estimate?:int} $result
	 */
	private static function startErrorMessage( array $result ): string {
		$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
		switch ( $reason ) {
			case 'already-running':
				return __( 'A bulk run is already in progress.', 'og-watermark' );
			case 'insufficient-disk':
				return __( 'Not enough disk space for the backups this run would create.', 'og-watermark' );
			default:
				return __( 'Could not start the bulk run.', 'og-watermark' );
		}
	}
}
