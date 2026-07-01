<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Admin;

defined( 'ABSPATH' ) || exit;

use OrchardGrove\OgWatermark\Backup\Manager;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Settings\Sanitizer;
use OrchardGrove\OgWatermark\Support\Capabilities;

/**
 * The OGM-styled Settings screen (SPEC M7 — "Admin UX", "Settings reference").
 *
 * Owns the top-level "OG Watermark" menu + its two submenus (Settings, Bulk
 * Tools — the Bulk Tools render is provided by {@see BulkPage}), registers the
 * single `ogwm_settings` option through `register_setting` with the production
 * {@see Sanitizer::sanitize} callback (so the form can NEVER bypass the
 * whitelist/clamp/validate layer), and enqueues the shared admin stylesheet +
 * the settings JS — gated strictly to the plugin's own screens.
 *
 * The form posts to options.php with settings_fields( self::GROUP ), so saving
 * is core's standard Settings API round-trip; nothing here writes the option
 * directly. Every rendered value is read through the typed {@see Options}
 * accessor and escaped at output. The class vocabulary (`.ogwm-*`) mirrors the
 * editorial-qa / heirloom-seo house style and is the shared design system the
 * sibling admin pieces (MediaField, MediaColumn, BulkPage) reuse.
 *
 * Capability degradation is first-class: when {@see Capabilities::isUsable()} is
 * false the apply controls are disabled and a prominent notice explains why;
 * when FreeType is absent the text-mode radio + its panel are disabled so the
 * user can't pick a mode that has no honest output.
 */
final class SettingsPage {

	/** Settings API group + the single option name. */
	public const GROUP = 'ogwm_settings_group';

	/** Top-level menu/page slug. */
	public const PAGE = 'ogwm';

	/** Legacy Bulk Tools slug — the submenu was folded into the "Apply" tab; kept only
	 * so a bookmark to the old page redirects to the tab. */
	public const BULK_PAGE = 'ogwm-bulk';

	/** Stylesheet/script handle base. */
	private const HANDLE = 'ogwm-admin';

	/**
	 * How stale (in seconds) the cached engine probe may be before the settings
	 * screen re-probes on render. Short enough that toggling an extension and
	 * reloading the page reflects it; long enough that a rapid reload doesn't
	 * re-probe every hit. The "Re-detect" button bypasses this entirely.
	 */
	private const CAPS_REFRESH_TTL = 60;

	/** Wire the menu, the setting, and the gated assets. */
	public static function register(): void {
		$page = new self();
		add_action( 'admin_menu', [ $page, 'addMenu' ] );
		add_action( 'admin_init', [ $page, 'registerSetting' ] );
		add_action( 'admin_init', [ self::class, 'redirectLegacyBulkPage' ] );
		add_action( 'admin_enqueue_scripts', [ $page, 'assets' ] );
	}

	/**
	 * Back-compat: the Bulk Tools submenu became the "Apply" tab on this screen. A
	 * bookmark to the old ?page=ogwm-bulk URL (a page that no longer exists) is
	 * redirected to ?page=ogwm&tab=apply, carrying any query args (the media-library
	 * bulk-action redirect already targets the new URL directly).
	 */
	public static function redirectLegacyBulkPage(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page selector; no state mutation, we only redirect.
		if ( ! isset( $_GET['page'] ) || self::BULK_PAGE !== sanitize_key( (string) wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$args         = array_map( 'sanitize_text_field', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args['page'] = self::PAGE;
		$args['tab']  = 'apply';

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Top-level menu + the Settings and Bulk Tools submenus. The Bulk Tools
	 * render callback is owned by {@see BulkPage}; we register only its menu slug
	 * here when the class is available, so the two submenus always sit together
	 * under one parent regardless of load order.
	 */
	public function addMenu(): void {
		add_menu_page(
			__( 'OG Watermark', 'og-watermark' ),
			__( 'OG Watermark', 'og-watermark' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ],
			'dashicons-format-image',
			81
		);

		add_submenu_page(
			self::PAGE,
			__( 'OG Watermark Settings', 'og-watermark' ),
			__( 'Settings', 'og-watermark' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);

		// The former "Bulk Tools" submenu is now an "Apply" TAB on this same page
		// (admin.php?page=ogwm&tab=apply), rendered by render() → BulkPage::renderPanel().
	}

	/** Register the single option with the PRODUCTION sanitize callback. */
	public function registerSetting(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Sanitizer::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Enqueue the shared stylesheet + settings JS, gated to the OG Watermark
	 * screens only (never globally). wp_enqueue_media() is loaded so the logo
	 * picker can open the WP media frame.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function assets( string $hook ): void {
		if ( ! $this->isSettingsScreen( $hook ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			self::HANDLE,
			OGWM_URL . 'assets/css/admin.css',
			[],
			OGWM_VERSION
		);

		wp_enqueue_script(
			self::HANDLE . '-settings',
			OGWM_URL . 'assets/js/admin-settings.js',
			[ 'jquery' ],
			OGWM_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE . '-settings',
			'ogwmSettings',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'ogwm_admin' ),
				'option'          => Options::OPTION,
				'redetectAction'  => Ajax::ACTION_REDETECT,
				'driverLabels'    => [
					'imagick' => __( 'Imagick', 'og-watermark' ),
					'gd'      => __( 'GD', 'og-watermark' ),
					'none'    => __( 'None', 'og-watermark' ),
				],
				'i18n'            => [
					'selectLogo'     => __( 'Select watermark logo', 'og-watermark' ),
					'useImage'       => __( 'Use this image', 'og-watermark' ),
					'pngOnly'        => __( 'The watermark logo must be a PNG with transparency.', 'og-watermark' ),
					'previewError'   => __( 'Preview unavailable.', 'og-watermark' ),
					'previewWait'    => __( 'Rendering preview…', 'og-watermark' ),
					'redetecting'    => __( 'Re-detecting…', 'og-watermark' ),
					'redetectDone'   => __( 'Engine re-detected.', 'og-watermark' ),
					'redetectError'  => __( 'Could not re-detect the engine.', 'og-watermark' ),
					'textAvailable'  => __( 'Available', 'og-watermark' ),
					'textUnavailable' => __( 'Unavailable', 'og-watermark' ),
				],
			]
		);
	}

	/**
	 * Re-probe the host engine on the settings screen when the cached report is
	 * older than {@see CAPS_REFRESH_TTL} (or has no timestamp). Called ONLY from
	 * render(), which only runs for this plugin's settings page — so the re-probe
	 * stays scoped to the one screen and never hits the front end or other admin
	 * pages. Cheap: it reads the cached probed_gmt and skips the re-probe when the
	 * report is still fresh.
	 */
	private function maybeRefreshCapabilities(): void {
		$caps  = Capabilities::get();
		$probed = isset( $caps['probed_gmt'] ) && is_string( $caps['probed_gmt'] ) ? $caps['probed_gmt'] : '';
		$age    = '' === $probed ? PHP_INT_MAX : ( time() - (int) strtotime( $probed ) );

		if ( $age < 0 || $age >= self::CAPS_REFRESH_TTL ) {
			Capabilities::refresh();
		}
	}

	/** Whether $hook is the plugin's settings screen (which now also hosts the Apply tab). */
	private function isSettingsScreen( string $hook ): bool {
		return 'toplevel_page_' . self::PAGE === $hook;
	}

	/**
	 * Render the whole settings screen: branded header → tabs → cards → sidebar.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Re-probe the host so the Engine status card (and the stamp path) reflect an
		// image extension enabled/disabled since the probe was last cached — without
		// requiring a plugin version bump. Scoped strictly to THIS settings screen
		// render (never every admin page, never the front end); throttled to a short
		// TTL so a rapid reload doesn't re-probe on every hit. The "Re-detect" button
		// forces a refresh on demand via the redetect AJAX handler.
		$this->maybeRefreshCapabilities();

		$options = new Options();
		$caps    = Capabilities::get();
		$usable  = Capabilities::isUsable();
		$text    = Capabilities::hasFreeType();
		$type    = $options->str( 'watermark.type', 'text' );

		// Which tab is active. 'apply' shows the bulk panel (formerly the Bulk Tools
		// page); anything else is the settings form. Read-only navigation hint.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector; no state mutation.
		$isApply = 'apply' === $tab;
		?>
		<div class="wrap ogwm-screen">
			<div class="ogwm-header">
				<div class="ogwm-brand">
					<span class="ogwm-mark" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
					<div class="ogwm-brand-text">
						<h1><?php esc_html_e( 'OG Watermark', 'og-watermark' ); ?></h1>
						<p class="ogwm-tagline"><?php esc_html_e( 'Pixel-baked watermarks with safe, restorable originals — by Orchard Grove Media', 'og-watermark' ); ?></p>
					</div>
				</div>
				<span class="ogwm-version">v<?php echo esc_html( OGWM_VERSION ); ?></span>
			</div>
			<hr class="wp-header-end" />

			<nav class="ogwm-tabs" aria-label="<?php esc_attr_e( 'OG Watermark', 'og-watermark' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="ogwm-tab<?php echo $isApply ? '' : ' is-active'; ?>"<?php echo $isApply ? '' : ' aria-current="page"'; ?>>
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php esc_html_e( 'Settings', 'og-watermark' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=apply' ) ); ?>" class="ogwm-tab<?php echo $isApply ? ' is-active' : ''; ?>"<?php echo $isApply ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span><?php esc_html_e( 'Apply', 'og-watermark' ); ?>
				</a>
			</nav>

			<?php if ( ! $usable ) : ?>
				<div class="ogwm-notice ogwm-notice-error">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<p>
						<strong><?php esc_html_e( 'No usable image engine found.', 'og-watermark' ); ?></strong>
						<?php esc_html_e( 'Neither Imagick nor GD is available on this server, so OG Watermark cannot stamp images. Ask your host to enable the Imagick or GD PHP extension. Settings are saved but no watermark can be applied until an engine is present.', 'og-watermark' ); ?>
					</p>
				</div>
			<?php elseif ( ! $text ) : ?>
				<div class="ogwm-notice ogwm-notice-warning">
					<span class="dashicons dashicons-info" aria-hidden="true"></span>
					<p><?php esc_html_e( 'FreeType is not available, so text watermarks are disabled. Use a logo (PNG) instead. Everything else works normally.', 'og-watermark' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $isApply ) : ?>
				<?php BulkPage::renderPanel( $usable ); ?>
			<?php else : ?>
				<div class="ogwm-layout">
					<div class="ogwm-main">
						<form method="post" action="options.php" class="ogwm-form" id="ogwm-settings-form">
							<?php settings_fields( self::GROUP ); ?>

							<?php $this->cardType( $options, $type, $text ); ?>
							<?php $this->cardPlacement( $options ); ?>
							<?php $this->cardSizing( $options ); ?>
							<?php $this->cardSizes( $options ); ?>
							<?php $this->cardOutput( $options ); ?>
							<?php $this->cardAdvanced( $options ); ?>

							<p class="ogwm-submit">
								<?php
								$submit_attrs = $usable ? [] : [ 'disabled' => 'disabled' ];
								submit_button( __( 'Save settings', 'og-watermark' ), 'primary', 'submit', false, $submit_attrs );
								?>
							</p>
						</form>
					</div>

					<aside class="ogwm-sidebar">
						<?php $this->cardPreview( $usable ); ?>
						<?php $this->cardStorage(); ?>
						<?php $this->cardStatus( $caps ); ?>
					</aside>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Cards (main column).
	// ---------------------------------------------------------------------

	/**
	 * Watermark type: logo｜text radio + the two mode panels. The text radio +
	 * panel are disabled (and the radio forced to logo) when FreeType is absent.
	 */
	private function cardType( Options $options, string $type, bool $hasFreeType ): void {
		$logoId   = $options->int( 'watermark.logo_id' );
		$logoUrl  = $logoId > 0 ? (string) wp_get_attachment_image_url( $logoId, 'medium' ) : '';
		$textDis  = $hasFreeType ? '' : ' disabled';
		$logoOnly = ! $hasFreeType;
		?>
		<div class="ogwm-card">
			<h2 class="ogwm-card-title"><?php esc_html_e( 'Watermark type', 'og-watermark' ); ?></h2>
			<p class="ogwm-intro"><?php esc_html_e( 'Choose a logo image or rendered text. The mark is baked into every served image size.', 'og-watermark' ); ?></p>

			<div class="ogwm-typeswitch" role="radiogroup" aria-label="<?php esc_attr_e( 'Watermark type', 'og-watermark' ); ?>">
				<label class="ogwm-typeoption">
					<input type="radio" name="<?php echo esc_attr( $this->name( 'watermark.type' ) ); ?>" value="logo" <?php checked( $type, 'logo' ); ?> data-ogwm-type="logo" />
					<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
					<?php esc_html_e( 'Logo (PNG)', 'og-watermark' ); ?>
				</label>
				<label class="ogwm-typeoption<?php echo esc_attr( $logoOnly ? ' is-disabled' : '' ); ?>">
					<input type="radio" name="<?php echo esc_attr( $this->name( 'watermark.type' ) ); ?>" value="text" <?php checked( $logoOnly ? 'logo' : $type, 'text' ); ?> data-ogwm-type="text"<?php echo esc_attr( $textDis ); ?> />
					<span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span>
					<?php esc_html_e( 'Text', 'og-watermark' ); ?>
				</label>
			</div>

			<div class="ogwm-panel ogwm-panel-logo" data-ogwm-panel="logo">
				<table class="form-table ogwm-fields" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo image', 'og-watermark' ); ?></th>
						<td>
							<div class="ogwm-logo-pick">
								<div class="ogwm-logo-preview"<?php echo '' === $logoUrl ? ' hidden' : ''; ?>>
									<?php if ( '' !== $logoUrl ) : ?>
										<img src="<?php echo esc_url( $logoUrl ); ?>" alt="" />
									<?php endif; ?>
								</div>
								<input type="hidden" id="ogwm_logo_id" name="<?php echo esc_attr( $this->name( 'watermark.logo_id' ) ); ?>" value="<?php echo esc_attr( (string) $logoId ); ?>" />
								<button type="button" class="button" id="ogwm-logo-select"><?php esc_html_e( 'Select logo', 'og-watermark' ); ?></button>
								<button type="button" class="button-link ogwm-logo-clear" id="ogwm-logo-clear"<?php echo 0 === $logoId ? ' hidden' : ''; ?>><?php esc_html_e( 'Remove', 'og-watermark' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'PNG only — transparency is preserved. Re-verified each time it is used.', 'og-watermark' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="ogwm-panel ogwm-panel-text" data-ogwm-panel="text">
				<table class="form-table ogwm-fields" role="presentation">
					<tr>
						<th scope="row"><label for="ogwm_text"><?php esc_html_e( 'Text template', 'og-watermark' ); ?></label></th>
						<td>
							<input type="text" id="ogwm_text" class="regular-text ogwm-preview-input" name="<?php echo esc_attr( $this->name( 'watermark.text' ) ); ?>" value="<?php echo esc_attr( $options->str( 'watermark.text' ) ); ?>"<?php echo esc_attr( $textDis ); ?> />
							<p class="description">
								<?php esc_html_e( 'Tokens:', 'og-watermark' ); ?>
								<code>{site}</code> <code>{year}</code> <code>{copy}</code> <code>{url}</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ogwm_text_color"><?php esc_html_e( 'Text color', 'og-watermark' ); ?></label></th>
						<td><input type="color" id="ogwm_text_color" class="ogwm-preview-input" name="<?php echo esc_attr( $this->name( 'watermark.text_color' ) ); ?>" value="<?php echo esc_attr( $options->str( 'watermark.text_color', '#ffffff' ) ); ?>"<?php echo esc_attr( $textDis ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Legibility treatment', 'og-watermark' ); ?></th>
						<td>
							<?php
							$treatment = $options->str( 'watermark.treatment', 'stroke' );
							$choices   = [
								'stroke' => __( 'Outline / stroke', 'og-watermark' ),
								'shadow' => __( 'Drop shadow', 'og-watermark' ),
								'none'   => __( 'None', 'og-watermark' ),
							];
							foreach ( $choices as $value => $label ) :
								?>
								<label class="ogwm-inline-radio">
									<input type="radio" class="ogwm-preview-input" name="<?php echo esc_attr( $this->name( 'watermark.treatment' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $treatment, $value ); ?><?php echo esc_attr( $textDis ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ogwm_treatment_color"><?php esc_html_e( 'Treatment color', 'og-watermark' ); ?></label></th>
						<td><input type="color" id="ogwm_treatment_color" class="ogwm-preview-input" name="<?php echo esc_attr( $this->name( 'watermark.treatment_color' ) ); ?>" value="<?php echo esc_attr( $options->str( 'watermark.treatment_color', '#000000' ) ); ?>"<?php echo esc_attr( $textDis ); ?> /></td>
					</tr>
					<?php
					$this->sliderRow(
						'ogwm_text_scale_pct',
						$this->name( 'watermark.text_scale_pct' ),
						__( 'Text size', 'og-watermark' ),
						$options->int( 'watermark.text_scale_pct', 4 ),
						1,
						30,
						'%',
						$textDis
					);
					?>
				</table>
			</div>
		</div>
		<?php
	}

	/** Placement: 3×3 position grid radio + margin slider. */
	private function cardPlacement( Options $options ): void {
		$position = $options->str( 'placement.position', 'bot-right' );
		$cells    = [
			'top-left'   => __( 'Top left', 'og-watermark' ),
			'top-center' => __( 'Top center', 'og-watermark' ),
			'top-right'  => __( 'Top right', 'og-watermark' ),
			'mid-left'   => __( 'Middle left', 'og-watermark' ),
			'mid-center' => __( 'Middle center', 'og-watermark' ),
			'mid-right'  => __( 'Middle right', 'og-watermark' ),
			'bot-left'   => __( 'Bottom left', 'og-watermark' ),
			'bot-center' => __( 'Bottom center', 'og-watermark' ),
			'bot-right'  => __( 'Bottom right', 'og-watermark' ),
		];
		?>
		<div class="ogwm-card">
			<h2 class="ogwm-card-title"><?php esc_html_e( 'Placement', 'og-watermark' ); ?></h2>
			<table class="form-table ogwm-fields" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Position', 'og-watermark' ); ?></th>
					<td>
						<div class="ogwm-posgrid" role="radiogroup" aria-label="<?php esc_attr_e( 'Watermark position', 'og-watermark' ); ?>">
							<?php foreach ( $cells as $value => $label ) : ?>
								<label class="ogwm-poscell<?php echo $value === $position ? ' is-active' : ''; ?>" title="<?php echo esc_attr( $label ); ?>">
									<input type="radio" class="ogwm-preview-input" name="<?php echo esc_attr( $this->name( 'placement.position' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $position, $value ); ?> />
									<span class="ogwm-poscell-dot" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<?php
				$this->sliderRow(
					'ogwm_margin_pct',
					$this->name( 'placement.margin_pct' ),
					__( 'Margin', 'og-watermark' ),
					$options->int( 'placement.margin_pct', 3 ),
					0,
					25,
					'%',
					'',
					__( 'Distance from the edge, as a percentage of the image\'s shorter side.', 'og-watermark' )
				);
				?>
			</table>
		</div>
		<?php
	}

	/** Sizing & opacity: logo scale + min/max px clamps + opacity. */
	private function cardSizing( Options $options ): void {
		?>
		<div class="ogwm-card">
			<h2 class="ogwm-card-title"><?php esc_html_e( 'Sizing & opacity', 'og-watermark' ); ?></h2>
			<table class="form-table ogwm-fields" role="presentation">
				<?php
				$this->sliderRow(
					'ogwm_scale_pct',
					$this->name( 'sizing.scale_pct' ),
					__( 'Logo scale', 'og-watermark' ),
					$options->int( 'sizing.scale_pct', 18 ),
					1,
					100,
					'%',
					'',
					__( 'Logo width as a percentage of the image width (logo mode).', 'og-watermark' )
				);
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logo width clamps', 'og-watermark' ); ?></th>
					<td>
						<label class="ogwm-clamp"><?php esc_html_e( 'Min', 'og-watermark' ); ?>
							<input type="number" min="1" step="1" class="small-text" name="<?php echo esc_attr( $this->name( 'sizing.min_px' ) ); ?>" value="<?php echo esc_attr( (string) $options->int( 'sizing.min_px', 60 ) ); ?>" /> <?php esc_html_e( 'px', 'og-watermark' ); ?>
						</label>
						<label class="ogwm-clamp"><?php esc_html_e( 'Max', 'og-watermark' ); ?>
							<input type="number" min="1" step="1" class="small-text" name="<?php echo esc_attr( $this->name( 'sizing.max_px' ) ); ?>" value="<?php echo esc_attr( (string) $options->int( 'sizing.max_px', 600 ) ); ?>" /> <?php esc_html_e( 'px', 'og-watermark' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'The scaled logo width is clamped to this range.', 'og-watermark' ); ?></p>
					</td>
				</tr>
				<?php
				$this->sliderRow(
					'ogwm_opacity',
					$this->name( 'sizing.opacity' ),
					__( 'Opacity', 'og-watermark' ),
					$options->int( 'sizing.opacity', 70 ),
					0,
					100,
					'%'
				);
				?>
			</table>
		</div>
		<?php
	}

	/** Sizes: per-size checklist (full + registered subsizes) + skip-below input. */
	private function cardSizes( Options $options ): void {
		$enabled = $options->arr( 'sizes.enabled' );
		// Empty list = the default (full + all registered sizes are stamped). When
		// the user has explicitly chosen a subset the list is non-empty.
		$allOn = [] === $enabled;
		?>
		<div class="ogwm-card">
			<h2 class="ogwm-card-title"><?php esc_html_e( 'Image sizes', 'og-watermark' ); ?></h2>
			<p class="ogwm-intro"><?php esc_html_e( 'Choose which generated sizes get the watermark. Leave all checked to stamp every size, including the full-resolution file.', 'og-watermark' ); ?></p>
			<table class="form-table ogwm-fields" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Watermarked sizes', 'og-watermark' ); ?></th>
					<td>
						<div class="ogwm-sizes">
							<?php
							foreach ( $this->sizeChoices() as $slug => $label ) :
								$checked = $allOn || in_array( $slug, $enabled, true );
								?>
								<label class="ogwm-sizebox">
									<input type="checkbox" name="<?php echo esc_attr( $this->name( 'sizes.enabled' ) ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked, true ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'The "full" size is the attached file (the -scaled derivative for big images). The clean original is always backed up first.', 'og-watermark' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ogwm_min_dimension_px"><?php esc_html_e( 'Skip below', 'og-watermark' ); ?></label></th>
					<td>
						<input type="number" id="ogwm_min_dimension_px" min="0" step="1" class="small-text" name="<?php echo esc_attr( $this->name( 'sizes.min_dimension_px' ) ); ?>" value="<?php echo esc_attr( (string) $options->int( 'sizes.min_dimension_px', 300 ) ); ?>" /> <?php esc_html_e( 'px', 'og-watermark' ); ?>
						<p class="description"><?php esc_html_e( 'Sizes whose shorter edge is below this are left unmarked (thumbnails too small to carry a readable mark).', 'og-watermark' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/** Output: JPEG flatten background + srcset coherence toggle. */
	private function cardOutput( Options $options ): void {
		?>
		<div class="ogwm-card">
			<h2 class="ogwm-card-title"><?php esc_html_e( 'Output', 'og-watermark' ); ?></h2>
			<table class="form-table ogwm-fields" role="presentation">
				<tr>
					<th scope="row"><label for="ogwm_jpeg_bg"><?php esc_html_e( 'JPEG background', 'og-watermark' ); ?></label></th>
					<td>
						<input type="color" id="ogwm_jpeg_bg" name="<?php echo esc_attr( $this->name( 'output.jpeg_bg' ) ); ?>" value="<?php echo esc_attr( $options->str( 'output.jpeg_bg', '#ffffff' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Transparent areas are flattened onto this color when encoding JPEG.', 'og-watermark' ); ?></p>
					</td>
				</tr>
				<?php
				$this->checkboxRow(
					'ogwm_srcset_hide_unstamped',
					$this->name( 'output.srcset_hide_unstamped' ),
					__( 'srcset coherence', 'og-watermark' ),
					__( 'While an image is mid-processing, hide its not-yet-stamped sizes from srcset', 'og-watermark' ),
					$options->bool( 'output.srcset_hide_unstamped' ),
					__( 'Off by default — zero front-end overhead. Turn on for stricter coverage during the brief processing window.', 'og-watermark' )
				);
				?>
			</table>
		</div>
		<?php
	}

	/** Advanced: uninstall data + backup deletion toggles. */
	private function cardAdvanced( Options $options ): void {
		?>
		<div class="ogwm-card">
			<h2 class="ogwm-card-title"><?php esc_html_e( 'Advanced', 'og-watermark' ); ?></h2>
			<table class="form-table ogwm-fields" role="presentation">
				<?php
				$this->checkboxRow(
					'ogwm_delete_data_on_uninstall',
					$this->name( 'advanced.delete_data_on_uninstall' ),
					__( 'Uninstall', 'og-watermark' ),
					__( 'Delete plugin settings and per-image data when the plugin is deleted', 'og-watermark' ),
					$options->bool( 'advanced.delete_data_on_uninstall' )
				);
				$this->checkboxRow(
					'ogwm_delete_backups_on_uninstall',
					$this->name( 'advanced.delete_backups_on_uninstall' ),
					__( 'Delete backups too', 'og-watermark' ),
					__( 'Also delete the pristine original backups on uninstall (destructive — cannot be undone)', 'og-watermark' ),
					$options->bool( 'advanced.delete_backups_on_uninstall' ),
					__( 'The backups are your only clean masters. Removing them makes every watermark permanent.', 'og-watermark' )
				);
				?>
			</table>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Sidebar cards.
	// ---------------------------------------------------------------------

	/** Live-preview card (filled by admin-settings.js via the ogwm_preview AJAX). */
	private function cardPreview( bool $usable ): void {
		?>
		<div class="ogwm-card ogwm-aside ogwm-preview-card">
			<h2><?php esc_html_e( 'Live preview', 'og-watermark' ); ?></h2>
			<?php if ( $usable ) : ?>
				<div class="ogwm-preview" id="ogwm-preview" aria-live="polite">
					<span class="ogwm-preview-placeholder"><?php esc_html_e( 'Adjust a setting to render a preview.', 'og-watermark' ); ?></span>
				</div>
				<p class="ogwm-aside-foot"><?php esc_html_e( 'Rendered with the real engine on a sample image. Updates as you change settings.', 'og-watermark' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'The preview needs an image engine (Imagick or GD), which is not available on this server.', 'og-watermark' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Backup-storage card via Manager::stats(). */
	private function cardStorage(): void {
		$stats = Manager::stats();
		$bytes = isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0;
		$count = isset( $stats['count'] ) ? (int) $stats['count'] : 0;
		?>
		<div class="ogwm-card ogwm-aside">
			<h2><?php esc_html_e( 'Backup storage', 'og-watermark' ); ?></h2>
			<ul class="ogwm-glance">
				<li>
					<span class="label"><?php esc_html_e( 'Originals backed up', 'og-watermark' ); ?></span>
					<span class="value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
				</li>
				<li>
					<span class="label"><?php esc_html_e( 'Disk used', 'og-watermark' ); ?></span>
					<span class="value"><?php echo esc_html( size_format( $bytes ) ?: '0 B' ); ?></span>
				</li>
			</ul>
			<p class="ogwm-aside-foot"><?php esc_html_e( 'Each flagged image keeps one pristine master — the single source any watermark is rebuilt from.', 'og-watermark' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Engine status card: the active driver + FreeType availability, as dots.
	 *
	 * @param array<string,mixed> $caps Capabilities::get() report.
	 */
	private function cardStatus( array $caps ): void {
		$driver = isset( $caps['driver'] ) && is_string( $caps['driver'] ) ? $caps['driver'] : 'none';
		$labels = [
			'imagick' => __( 'Imagick', 'og-watermark' ),
			'gd'      => __( 'GD', 'og-watermark' ),
			'none'    => __( 'None', 'og-watermark' ),
		];
		$freetype = ! empty( $caps['freetype'] );
		?>
		<div class="ogwm-card ogwm-aside ogwm-engine-card" id="ogwm-engine-status">
			<h2><?php esc_html_e( 'Engine status', 'og-watermark' ); ?></h2>
			<ul class="ogwm-glance">
				<li>
					<span class="ogwm-dot<?php echo 'none' === $driver ? '' : ' is-on'; ?>" aria-hidden="true" data-ogwm-status="driver-dot"></span>
					<span class="label"><?php esc_html_e( 'Active driver', 'og-watermark' ); ?></span>
					<span class="value" data-ogwm-status="driver"><?php echo esc_html( $labels[ $driver ] ?? $labels['none'] ); ?></span>
				</li>
				<li>
					<span class="ogwm-dot<?php echo $freetype ? ' is-on' : ''; ?>" aria-hidden="true" data-ogwm-status="text-dot"></span>
					<span class="label"><?php esc_html_e( 'Text mode', 'og-watermark' ); ?></span>
					<span class="value" data-ogwm-status="text"><?php echo esc_html( $freetype ? __( 'Available', 'og-watermark' ) : __( 'Unavailable', 'og-watermark' ) ); ?></span>
				</li>
			</ul>
			<p class="ogwm-engine-actions">
				<button type="button" class="button button-secondary" id="ogwm-redetect">
					<span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Re-detect', 'og-watermark' ); ?>
				</button>
				<span class="ogwm-redetect-status" id="ogwm-redetect-status" role="status" aria-live="polite"></span>
			</p>
			<p class="ogwm-aside-foot">
				<?php
				/* translators: %s: version number. */
				printf( esc_html__( 'OG Watermark v%s', 'og-watermark' ), esc_html( OGWM_VERSION ) );
				?>
				<br />
				<?php esc_html_e( 'by Orchard Grove Media, LLC', 'og-watermark' ); ?>
			</p>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Field renderers.
	// ---------------------------------------------------------------------

	/**
	 * A range slider with a live numeric <output> mirror.
	 *
	 * @param string $id       Element id (also the <output> target).
	 * @param string $name     Submitted field name.
	 * @param string $label    Row label.
	 * @param int    $value    Current value.
	 * @param int    $min      Slider minimum.
	 * @param int    $max      Slider maximum.
	 * @param string $unit     Suffix shown beside the value (e.g. '%').
	 * @param string $disabled ' disabled' to disable the control, else ''.
	 * @param string $help     Optional description below the slider.
	 */
	private function sliderRow( string $id, string $name, string $label, int $value, int $min, int $max, string $unit = '', string $disabled = '', string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<span class="ogwm-slider">
					<input type="range" id="<?php echo esc_attr( $id ); ?>" class="ogwm-preview-input ogwm-range" name="<?php echo esc_attr( $name ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1" value="<?php echo esc_attr( (string) $value ); ?>" oninput="this.nextElementSibling.value=this.value"<?php echo esc_attr( $disabled ); ?> />
					<output for="<?php echo esc_attr( $id ); ?>" class="ogwm-output"><?php echo esc_html( (string) $value ); ?></output>
					<?php if ( '' !== $unit ) : ?>
						<span class="ogwm-unit"><?php echo esc_html( $unit ); ?></span>
					<?php endif; ?>
				</span>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A checkbox row with a hidden 0 companion so an unchecked box still posts a
	 * value (the Sanitizer coerces it to false).
	 *
	 * @param string $id      Element id.
	 * @param string $name    Submitted field name.
	 * @param string $label   Row label (the <th>).
	 * @param string $caption Inline label beside the checkbox.
	 * @param bool   $checked Whether the box is checked.
	 * @param string $help    Optional description below the control.
	 */
	private function checkboxRow( string $id, string $name, string $label, string $caption, bool $checked, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label for="<?php echo esc_attr( $id ); ?>" class="ogwm-checkbox">
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked, true ); ?> />
					<?php echo esc_html( $caption ); ?>
				</label>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * The size checklist: 'full' first, then every registered subsize with its
	 * dimensions. Falls back to a bare 'full' entry if the size API is missing.
	 *
	 * @return array<string,string> slug => label.
	 */
	private function sizeChoices(): array {
		$choices = [ 'full' => __( 'Full size (the attached file)', 'og-watermark' ) ];

		if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
			return $choices;
		}

		$subsizes = wp_get_registered_image_subsizes();
		if ( ! is_array( $subsizes ) ) {
			return $choices;
		}

		foreach ( $subsizes as $name => $info ) {
			$width  = isset( $info['width'] ) ? (int) $info['width'] : 0;
			$height = isset( $info['height'] ) ? (int) $info['height'] : 0;
			$crop   = ! empty( $info['crop'] );

			$choices[ (string) $name ] = sprintf(
				/* translators: 1: size name, 2: width, 3: height, 4: " (cropped)" or "". */
				__( '%1$s — %2$d×%3$d%4$s', 'og-watermark' ),
				(string) $name,
				$width,
				$height,
				$crop ? ' ' . __( '(cropped)', 'og-watermark' ) : ''
			);
		}

		return $choices;
	}

	/**
	 * Build the option-array field name for a dotted settings path, e.g.
	 * 'watermark.type' → 'ogwm_settings[watermark][type]'.
	 */
	private function name( string $path ): string {
		return Options::OPTION . '[' . implode( '][', explode( '.', $path ) ) . ']';
	}
}
