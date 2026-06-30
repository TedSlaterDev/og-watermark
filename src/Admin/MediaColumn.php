<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Queue\BulkRunner;
use OrchardGrove\OgWatermark\Support\Meta;
use WP_Query;

/**
 * The "Watermark" surface in the Media library list table (SPEC "Admin UX →
 * per-image opt-in" + "Bulk tool"). Three responsibilities, all bound here:
 *
 *  1. A "Watermark" COLUMN showing each attachment's lifecycle status as an
 *     `.ogwm-badge` — Not flagged | Queued | Watermarked(date) | Out of date |
 *     Error | Skipped — plus an inline quick-flag/apply button media.js wires.
 *     {@see badgeHtml()} is the SINGLE source of badge markup, shared with the
 *     media-modal field ({@see MediaField}) so the two surfaces never drift.
 *
 *  2. The column is SORTABLE: it is declared sortable and a `pre_get_posts`
 *     handler rewrites an `orderby=ogwm` request into a meta-value sort on
 *     {@see Meta::STATUS}, so the admin can group flagged/watermarked rows.
 *
 *  3. A BULK ACTION ("Apply OG Watermark" / "Remove watermark") on the upload
 *     list. Its handler is deliberately THIN: it ONLY flags the selected ids and
 *     seeds the server-side queue via {@see BulkRunner::start()} (apply) or
 *     unflags them (remove), then redirects to the Bulk Tools page. It NEVER
 *     composites an image inline — the SPEC's "the browser only observes" applies
 *     to bulk actions too; all real work happens in the background runner.
 */
final class MediaColumn {

	/** The custom column key + the sortable orderby token. */
	public const COLUMN = 'ogwm';

	/** Bulk-action values on the upload list table. */
	public const ACTION_APPLY  = 'ogwm_apply';
	public const ACTION_REMOVE = 'ogwm_remove';

	/** Query-string flag set on the Bulk Tools redirect so the page can confirm. */
	public const NOTICE_ARG = 'ogwm_flagged';

	/** Query-string flag carrying the bulk-action kind ('flagged'|'removed'). */
	public const ACTION_ARG = 'ogwm_action';

	/** The media.js script handle + the wp_localize_script object root. */
	private const SCRIPT_HANDLE = 'ogwm-media';

	/** Wire the column, the sortable orderby, the bulk actions, and the per-image JS. */
	public static function register(): void {
		add_filter( 'manage_media_columns', [ self::class, 'addColumn' ] );
		add_action( 'manage_media_custom_column', [ self::class, 'renderColumn' ], 10, 2 );

		add_filter( 'manage_upload_sortable_columns', [ self::class, 'sortableColumn' ] );
		add_action( 'pre_get_posts', [ self::class, 'applySort' ] );

		add_filter( 'bulk_actions-upload', [ self::class, 'addBulkActions' ] );
		add_filter( 'handle_bulk_actions-upload', [ self::class, 'handleBulkAction' ], 10, 3 );

		// The per-image Apply/Remove buttons (this column, the media-modal field, and
		// the classic attachment-edit screen) are all wired by media.js. The media
		// frame can open on almost any admin screen via wp_enqueue_media(), so the
		// enqueue is broad rather than upload.php-only; on screens where no control is
		// present the script simply finds nothing to bind.
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
	}

	/**
	 * Enqueue + localize media.js for the per-image Apply/Remove controls. The
	 * controls appear in the Media list column, the attachment-details sidebar, the
	 * grid modal, and the classic edit screen — and the media modal itself can be
	 * opened from many post-edit screens — so we load it on the upload list, the
	 * post editors, and any screen that has pulled in the media library.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public static function assets( string $hook ): void {
		if ( ! defined( 'OGWM_URL' ) || ! defined( 'OGWM_VERSION' ) ) {
			return;
		}

		if ( ! self::wantsMediaJs( $hook ) ) {
			return;
		}

		wp_enqueue_style( 'ogwm-admin', OGWM_URL . 'assets/css/admin.css', [], OGWM_VERSION );
		wp_enqueue_script( self::SCRIPT_HANDLE, OGWM_URL . 'assets/js/media.js', [], OGWM_VERSION, true );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'ogwmMedia',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE_ACTION ),
				'i18n'    => [
					'applying' => __( 'Applying…', 'og-watermark' ),
					'removing' => __( 'Removing…', 'og-watermark' ),
					'error'    => __( 'The request failed. Please try again.', 'og-watermark' ),
				],
			]
		);
	}

	/**
	 * Whether media.js should load on $hook. True on the Media library list table,
	 * the attachment-edit screen, and the post editors (where the media frame opens).
	 * The media frame can technically open elsewhere, but these cover every surface
	 * that renders our controls or a media button without loading the script site-wide.
	 */
	private static function wantsMediaJs( string $hook ): bool {
		return in_array(
			$hook,
			[ 'upload.php', 'post.php', 'post-new.php' ],
			true
		);
	}

	/**
	 * Append the "Watermark" column to the Media list table.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function addColumn( array $columns ): array {
		$columns[ self::COLUMN ] = esc_html__( 'Watermark', 'og-watermark' );
		return $columns;
	}

	/**
	 * Render the "Watermark" cell for one row: the status badge plus an inline
	 * quick-action button. Non-image attachments get a dash (nothing to watermark).
	 *
	 * Echoes (the `manage_*_custom_column` contract). All output is escaped — the
	 * badge HTML is built from a fixed vocabulary in {@see badgeHtml()}.
	 *
	 * @param string $column The column key being rendered.
	 * @param int    $postId The attachment id for this row.
	 */
	public static function renderColumn( string $column, int $postId ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$postId = absint( $postId );

		if ( ! wp_attachment_is_image( $postId ) ) {
			echo '<span class="ogwm-col-na" aria-hidden="true">&mdash;</span>';
			return;
		}

		// The badge vocabulary is fixed and escaped inside badgeHtml(); the inline
		// button text is escaped here. wp_kses confines the fragment to safe markup.
		$enabled = '1' === (string) get_post_meta( $postId, Meta::ENABLED, true );
		$label   = $enabled
			? esc_html__( 'Re-apply', 'og-watermark' )
			: esc_html__( 'Watermark', 'og-watermark' );

		echo '<span class="ogwm-col" data-id="' . esc_attr( (string) $postId ) . '">';
		echo wp_kses( self::badgeHtml( $postId ), self::badgeKses() );
		echo ' <button type="button" class="button button-small ogwm-apply" data-id="'
			. esc_attr( (string) $postId ) . '">' . $label . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label is built from esc_html__() above.
		echo '<span class="spinner ogwm-col-spinner"></span>';
		echo '</span>';
	}

	/**
	 * The single source of status-badge markup, shared by this column and the
	 * media-modal field. Maps the {@see Meta} lifecycle status to an `.ogwm-badge`
	 * with a stable modifier class and an i18n label. Every dynamic value (the
	 * applied date) is escaped here, so callers may `wp_kses` the result with
	 * {@see badgeKses()} without losing anything.
	 *
	 * @param int $id Attachment id.
	 * @return string Safe HTML fragment.
	 */
	public static function badgeHtml( int $id ): string {
		$status = self::of( $id );

		switch ( $status ) {
			case Meta::STATUS_WATERMARKED:
				// "Out of date": still watermarked, but the stored signature no longer
				// matches the current settings/logo/font/version — surfaced as its own
				// pill with a re-apply affordance.
				if ( StatusBadge::isStale( $id ) ) {
					return self::badge( 'stale', esc_html__( 'Out of date', 'og-watermark' ), 'info' );
				}

				$applied = (string) get_post_meta( $id, Meta::APPLIED_GMT, true );
				$label   = esc_html__( 'Watermarked', 'og-watermark' );
				if ( '' !== $applied ) {
					$label .= ' <span class="ogwm-badge-date">'
						. esc_html( self::formatDate( $applied ) ) . '</span>';
				}
				return self::badge( 'watermarked', $label, 'good' );

			case Meta::STATUS_QUEUED:
				return self::badge( 'queued', esc_html__( 'Queued', 'og-watermark' ), 'info' );

			case Meta::STATUS_RESTORED:
				return self::badge( 'restored', esc_html__( 'Not flagged', 'og-watermark' ), 'muted' );

			case Meta::STATUS_FAILED:
				$err = (string) get_post_meta( $id, Meta::LAST_ERROR, true );
				return self::badge(
					'failed',
					esc_html__( 'Error', 'og-watermark' ),
					'bad',
					'' !== $err ? esc_attr( $err ) : ''
				);

			case Meta::STATUS_BACKUP_MISSING:
				return self::badge( 'failed', esc_html__( 'Backup missing', 'og-watermark' ), 'bad' );

			case Meta::STATUS_TOO_LARGE:
				return self::badge( 'failed', esc_html__( 'Too large', 'og-watermark' ), 'bad' );

			case Meta::STATUS_SKIPPED_OFFLOADED:
				return self::badge( 'skipped', esc_html__( 'Skipped', 'og-watermark' ), 'muted' );

			default:
				// 'none' / unset — a flagged-but-unprocessed attachment reads "Queued",
				// an unflagged one reads "Not flagged".
				$enabled = '1' === (string) get_post_meta( $id, Meta::ENABLED, true );
				return $enabled
					? self::badge( 'queued', esc_html__( 'Queued', 'og-watermark' ), 'info' )
					: self::badge( 'none', esc_html__( 'Not flagged', 'og-watermark' ), 'muted' );
		}
	}

	/**
	 * Declare the column sortable (its orderby token is the column key).
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function sortableColumn( array $columns ): array {
		$columns[ self::COLUMN ] = self::COLUMN;
		return $columns;
	}

	/**
	 * Rewrite an `orderby=ogwm` main-query request into a meta-value sort on the
	 * lifecycle status, so the admin can group rows by watermark state. Touches
	 * ONLY the admin main query asking for our token; every other query is left
	 * exactly as-is.
	 *
	 * @param WP_Query $query The query being prepared.
	 */
	public static function applySort( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::COLUMN !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', Meta::STATUS );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC' );
	}

	/**
	 * Add the OG Watermark bulk actions to the upload list-table dropdown.
	 *
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function addBulkActions( array $actions ): array {
		$actions[ self::ACTION_APPLY ]  = esc_html__( 'Apply OG Watermark', 'og-watermark' );
		$actions[ self::ACTION_REMOVE ] = esc_html__( 'Remove watermark', 'og-watermark' );
		return $actions;
	}

	/**
	 * Handle the OG Watermark bulk actions. THIN BY DESIGN: it ONLY mutates
	 * opt-in meta and seeds the background queue — it composites NOTHING inline.
	 *
	 *  - Apply  → flag every selected image ({@see Meta::ENABLED} + queued status),
	 *             then {@see BulkRunner::start()} with the explicit id set so the
	 *             server-side runner does the real work, and redirect to Bulk Tools.
	 *  - Remove → drop the flag on every selected image (the actual un-watermark is
	 *             an explicit per-image Remove / the queue; a bulk un-flag never
	 *             touches pixels in the request).
	 *
	 * Anything other than our two actions is passed through untouched (return the
	 * incoming $redirect so other handlers in the chain still run).
	 *
	 * @param string         $redirect The redirect URL accumulated by the chain.
	 * @param string         $action   The bulk action being handled.
	 * @param array<int,int> $ids      The selected attachment ids.
	 * @return string The redirect URL (to Bulk Tools for our actions).
	 */
	public static function handleBulkAction( string $redirect, string $action, array $ids ): string {
		if ( self::ACTION_APPLY !== $action && self::ACTION_REMOVE !== $action ) {
			return $redirect;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect;
		}

		// Coerce to positive image-attachment ids only — never trust the raw set.
		$ids = self::imageIds( $ids );

		if ( self::ACTION_REMOVE === $action ) {
			foreach ( $ids as $id ) {
				delete_post_meta( $id, Meta::ENABLED );
			}
			return self::bulkRedirect( count( $ids ), 'removed' );
		}

		// Apply: flag + seed the queued status, then hand the explicit id set to the
		// background runner. NO inline compositing happens here.
		foreach ( $ids as $id ) {
			update_post_meta( $id, Meta::ENABLED, '1' );
			update_post_meta( $id, Meta::STATUS, Meta::STATUS_QUEUED );
		}

		if ( [] !== $ids ) {
			BulkRunner::start( 'ids', [ 'ids' => $ids ] );
		}

		return self::bulkRedirect( count( $ids ), 'flagged' );
	}

	// ---------------------------------------------------------------------
	// Internal helpers.
	// ---------------------------------------------------------------------

	/** The lifecycle status for an attachment, defaulting to STATUS_NONE. */
	private static function of( int $id ): string {
		$status = (string) get_post_meta( $id, Meta::STATUS, true );
		return '' !== $status ? $status : Meta::STATUS_NONE;
	}

	/**
	 * Assemble one badge span. The inner $label is ALREADY escaped by the caller
	 * (and may carry a small fixed `.ogwm-badge-date` span); the modifier + state
	 * are from a fixed vocabulary, and $title is pre-`esc_attr`'d.
	 */
	private static function badge( string $state, string $label, string $tone, string $title = '' ): string {
		$titleAttr = '' !== $title ? ' title="' . $title . '"' : '';
		return '<span class="ogwm-badge ogwm-badge-' . esc_attr( $tone )
			. '" data-status="' . esc_attr( $state ) . '"' . $titleAttr . '>'
			. $label . '</span>';
	}

	/**
	 * The `wp_kses` whitelist for a rendered badge fragment — exactly the tags the
	 * badge vocabulary uses, nothing more. Public so {@see StatusBadge} (which
	 * delegates to this single source) shares the exact same allowlist across the
	 * initial column render and the AJAX swap.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function badgeKses(): array {
		return [
			'span' => [
				'class'       => true,
				'data-status' => true,
				'title'       => true,
			],
			'button' => [
				'type'    => true,
				'class'   => true,
				'data-id' => true,
			],
		];
	}

	/** Format a stored ISO-8601 GMT timestamp for the "Watermarked" badge. */
	private static function formatDate( string $iso ): string {
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}
		return function_exists( 'date_i18n' )
			? date_i18n( 'Y-m-d', $ts )
			: gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Build the redirect to the Bulk Tools page, carrying a confirmation count so
	 * the page can render an admin notice. On an APPLY the queue was already seeded
	 * server-side, so we also set ogwm_resume=1 — BulkPage emits window.ogwmAutoStart
	 * from it and bulk.js begins polling the running job immediately (no manual reload).
	 */
	private static function bulkRedirect( int $count, string $kind ): string {
		$base = admin_url( 'admin.php?page=ogwm-bulk' );
		$args = [
			self::NOTICE_ARG  => $count,
			self::ACTION_ARG  => $kind,
		];

		if ( 'flagged' === $kind ) {
			$args['ogwm_resume'] = 1;
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * Coerce a raw id list to positive, de-duplicated image-attachment ids.
	 *
	 * @param array<int,int|string> $ids
	 * @return array<int,int>
	 */
	private static function imageIds( array $ids ): array {
		$clean = [];
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				$clean[ $id ] = $id;
			}
		}
		return array_values( $clean );
	}
}
