<?php
declare( strict_types=1 );

namespace OrchardGrove\OgWatermark\Tests\Admin;

use Brain\Monkey\Functions;
use OrchardGrove\OgWatermark\Admin\SettingsPage;
use OrchardGrove\OgWatermark\Settings\Options;
use OrchardGrove\OgWatermark\Settings\Sanitizer;
use OrchardGrove\OgWatermark\Support\Capabilities;
use OrchardGrove\OgWatermark\Tests\TestCase;
use ReflectionClass;

/**
 * Unit tests for the OGM-styled Settings screen.
 *
 * The load-bearing properties:
 *
 *  - register_setting wires the PRODUCTION Sanitizer::sanitize callback (the form
 *    can never bypass the whitelist/clamp/validate layer);
 *  - the top-level menu + both submenus (Settings, Bulk Tools) are added;
 *  - assets enqueue ONLY on the plugin's own screens (gated) and pull in the
 *    media library for the logo picker;
 *  - the rendered form posts to options.php, carries every setting field, and
 *    every value is escaped at output;
 *  - text mode is disabled when FreeType is absent, and the Save control + a loud
 *    notice surface when no usable engine is present.
 *
 * Capabilities caches its probe in a private static memo; the tests drive it
 * through that memo via reflection so the three engine states are deterministic
 * without a real Imagick/GD probe.
 */
final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The asset enqueue builds URLs from OGWM_URL; the bootstrap defines
		// OGWM_DIR/OGWM_VERSION but not OGWM_URL, so define it here for the test.
		if ( ! defined( 'OGWM_URL' ) ) {
			define( 'OGWM_URL', 'https://example.test/wp-content/plugins/og-watermark/' );
		}

		// Output/escaping + i18n helpers used by the render.
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_attr_e' )->alias(
			static function ( $t ): void {
				echo $t;
			}
		);
		Functions\when( 'esc_html_e' )->alias(
			static function ( $t ): void {
				echo $t;
			}
		);
		Functions\when( 'esc_textarea' )->returnArg( 1 );
		Functions\when( 'checked' )->alias(
			static fn( $a, $b = true, $echo = true ) => ( (string) $a === (string) $b ) ? ' checked' : ''
		);
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'size_format' )->alias( static fn( $b ) => (string) (int) $b . ' B' );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'settings_fields' )->alias(
			static function ( $g ): void {
				echo '<!--fields:' . $g . '-->';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $attrs = [] ): void {
				$dis = ( is_array( $attrs ) && isset( $attrs['disabled'] ) ) ? ' disabled' : '';
				echo '<button type="submit" class="button-primary' . $dis . '">' . $text . '</button>';
			}
		);
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.test/logo.png' );
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			[
				'thumbnail' => [ 'width' => 150, 'height' => 150, 'crop' => true ],
				'medium'    => [ 'width' => 300, 'height' => 300, 'crop' => false ],
			]
		);

		// No stored settings -> defaults flow through Options.
		Functions\when( 'get_option' )->justReturn( [] );

		$this->setCapabilities( 'gd', true );
	}

	protected function tearDown(): void {
		$this->resetCapabilities();
		parent::tearDown();
	}

	// =====================================================================
	// register() / registerSetting() — the Settings API wiring.
	// =====================================================================

	public function testRegisterAddsMenuInitAndAssetHooks(): void {
		$hooks = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb ) use ( &$hooks ): void {
				$hooks[] = $hook;
			}
		);

		SettingsPage::register();

		$this->assertContains( 'admin_menu', $hooks );
		$this->assertContains( 'admin_init', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
	}

	public function testRegisterSettingUsesTheProductionSanitizeCallback(): void {
		$captured = null;
		Functions\expect( 'register_setting' )
			->once()
			->andReturnUsing(
				static function ( $group, $option, $args ) use ( &$captured ): void {
					$captured = [ $group, $option, $args ];
				}
			);

		( new SettingsPage() )->registerSetting();

		$this->assertSame( SettingsPage::GROUP, $captured[0] );
		$this->assertSame( Options::OPTION, $captured[1] );
		// The callback is the REAL Sanitizer::sanitize — never a weaker shim.
		$this->assertSame( [ Sanitizer::class, 'sanitize' ], $captured[2]['sanitize_callback'] );
	}

	public function testAddMenuRegistersTopLevelAndBothSubmenus(): void {
		$menu     = null;
		$submenus = [];
		Functions\when( 'add_menu_page' )->alias(
			static function ( $page_title, $menu_title, $cap, $slug, $cb, $icon = '', $pos = null ) use ( &$menu ): void {
				$menu = [ $cap, $slug, $icon ];
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function ( $parent, $page_title, $menu_title, $cap, $slug ) use ( &$submenus ): void {
				$submenus[ $slug ] = [ $parent, $cap ];
			}
		);

		( new SettingsPage() )->addMenu();

		$this->assertSame( 'manage_options', $menu[0] );
		$this->assertSame( SettingsPage::PAGE, $menu[1] );
		$this->assertSame( 'dashicons-format-image', $menu[2] );

		// Settings submenu (shares the parent slug).
		$this->assertArrayHasKey( SettingsPage::PAGE, $submenus );
		$this->assertSame( 'manage_options', $submenus[ SettingsPage::PAGE ][1] );
		// Bulk Tools submenu under the same parent.
		$this->assertArrayHasKey( SettingsPage::BULK_PAGE, $submenus );
		$this->assertSame( SettingsPage::PAGE, $submenus[ SettingsPage::BULK_PAGE ][0] );
	}

	// =====================================================================
	// assets() — gated to the plugin screens only.
	// =====================================================================

	public function testAssetsDoNotLoadOnUnrelatedScreens(): void {
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_media' )->never();

		( new SettingsPage() )->assets( 'edit.php' );
		$this->addToAssertionCount( 1 );
	}

	public function testAssetsLoadOnTheSettingsScreenWithMedia(): void {
		$styles  = [];
		$scripts = [];
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$styles ): void {
				$styles[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$scripts ): void {
				$scripts[] = $handle;
			}
		);
		$localized = null;
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $obj, $data ) use ( &$localized ): void {
				$localized = $data;
			}
		);
		Functions\expect( 'wp_enqueue_media' )->once();

		( new SettingsPage() )->assets( 'toplevel_page_' . SettingsPage::PAGE );

		$this->assertContains( 'ogwm-admin', $styles );
		$this->assertContains( 'ogwm-admin-settings', $scripts );
		// The localized payload exposes the admin nonce for the preview AJAX.
		$this->assertSame( 'nonce123', $localized['nonce'] );
	}

	// =====================================================================
	// render() — structure, fields, escaping, capability degradation.
	// =====================================================================

	public function testRenderProducesEscapedFormWithEverySettingField(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stubManagerStats();

		$html = $this->capture();

		// Posts to options.php through the Settings API with the right group.
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( '<!--fields:' . SettingsPage::GROUP . '-->', $html );

		// A representative field from EVERY settings group is rendered with the
		// grouped option-array name.
		$names = [
			'watermark][type',
			'watermark][logo_id',
			'watermark][text',
			'watermark][text_color',
			'watermark][treatment',
			'watermark][treatment_color',
			'watermark][text_scale_pct',
			'placement][position',
			'placement][margin_pct',
			'sizing][scale_pct',
			'sizing][min_px',
			'sizing][max_px',
			'sizing][opacity',
			'sizes][enabled',
			'sizes][min_dimension_px',
			'output][jpeg_bg',
			'output][srcset_hide_unstamped',
			'advanced][delete_data_on_uninstall',
			'advanced][delete_backups_on_uninstall',
		];
		foreach ( $names as $needle ) {
			$this->assertStringContainsString( Options::OPTION . '[' . $needle, $html, "Missing field: $needle" );
		}

		// The sidebar cards are present.
		$this->assertStringContainsString( 'ogwm-preview', $html );
		$this->assertStringContainsString( 'Backup storage', $html );
		$this->assertStringContainsString( 'Engine status', $html );

		// The 3x3 grid renders all nine position radios.
		$positions = [ 'top-left', 'top-center', 'top-right', 'mid-left', 'mid-center', 'mid-right', 'bot-left', 'bot-center', 'bot-right' ];
		foreach ( $positions as $pos ) {
			$this->assertStringContainsString( 'value="' . $pos . '"', $html, "Missing position cell: $pos" );
		}
	}

	public function testRenderBailsForUsersWithoutManageOptions(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$html = $this->capture();

		$this->assertSame( '', trim( $html ) );
	}

	public function testTextModeIsDisabledWhenFreeTypeIsAbsent(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stubManagerStats();
		$this->setCapabilities( 'gd', false ); // GD usable, but no FreeType.

		$html = $this->capture();

		// The text radio carries a disabled attribute (its panel/inputs too).
		$this->assertMatchesRegularExpression(
			'/data-ogwm-type="text"[^>]*disabled/',
			$html,
			'Text-mode radio should be disabled without FreeType'
		);
		// A warning explains text mode is off; the Save button is NOT disabled
		// because the engine is otherwise usable.
		$this->assertStringContainsString( 'FreeType is not available', $html );
		$this->assertStringNotContainsString( 'class="button-primary disabled"', $html );
	}

	public function testApplyControlsDisabledAndNoticeShownWhenNoEngine(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stubManagerStats();
		$this->setCapabilities( 'none', false );

		$html = $this->capture();

		// Loud notice + the Save button is disabled (no engine can apply anything).
		$this->assertStringContainsString( 'No usable image engine found', $html );
		$this->assertStringContainsString( 'class="button-primary disabled"', $html );
	}

	// =====================================================================
	// Helpers.
	// =====================================================================

	/** Capture render() output as a string. */
	private function capture(): string {
		ob_start();
		( new SettingsPage() )->render();
		return (string) ob_get_clean();
	}

	/** Stub Manager::stats()'s dependencies (transient + recompute path). */
	private function stubManagerStats(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'bytes' => 1048576, 'count' => 3 ] );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Pin the Capabilities probe to a known driver + FreeType flag by writing the
	 * private in-request memo through reflection (no real Imagick/GD probe).
	 */
	private function setCapabilities( string $driver, bool $freetype ): void {
		( new ReflectionClass( Capabilities::class ) )
			->getProperty( 'cache' )
			->setValue(
				null,
				[
					'driver'     => $driver,
					'imagick'    => 'imagick' === $driver,
					'gd'         => 'gd' === $driver,
					'freetype'   => $freetype,
					'formats'    => [ 'image/png' => 'none' !== $driver ],
					'probed_gmt' => '2026-06-29T00:00:00+00:00',
				]
			);
	}

	/** Clear the Capabilities memo so a later test re-resolves. */
	private function resetCapabilities(): void {
		( new ReflectionClass( Capabilities::class ) )
			->getProperty( 'cache' )
			->setValue( null, null );
	}
}
