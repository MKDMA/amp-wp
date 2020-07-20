<?php
/**
 * Class ReaderThemeLoader.
 *
 * @package AmpProject\AmpWP
 */

namespace AmpProject\AmpWP;

use AmpProject\AmpWP\Infrastructure\Registerable;
use AmpProject\AmpWP\Infrastructure\Service;
use AMP_Options_Manager;
use AMP_Theme_Support;
use AmpProject\AmpWP\Admin\ReaderThemes;
use WP_Theme;
use WP_Customize_Manager;

/**
 * Switches to the designated Reader theme when template mode enabled and when requesting an AMP page.
 *
 * This class does not implement Conditional because other services need to always be able to access this
 * service in order to determine whether or a Reader theme is loaded, and if so, what the previously-active theme was.
 *
 * @package AmpProject\AmpWP
 */
final class ReaderThemeLoader implements Service, Registerable {

	/**
	 * Reader theme.
	 *
	 * @var WP_Theme
	 */
	private $reader_theme;

	/**
	 * Active theme.
	 *
	 * Theme which was active before switching to the Reader theme.
	 *
	 * @var WP_Theme
	 */
	private $active_theme;

	/**
	 * Whether the active theme was overridden with the Reader theme.
	 *
	 * @var bool
	 */
	private $theme_overridden = false;

	/**
	 * Is Reader mode with a Reader theme selected.
	 *
	 * @return bool Whether new Reader mode.
	 */
	public function is_enabled() {
		// If the theme was overridden then we know it is enabled. We can't check get_template() at this point because
		// it will be identical to $reader_theme.
		if ( $this->is_theme_overridden() ) {
			return true;
		}

		// If Reader mode is not enabled, then a Reader theme is definitely not going to be served.
		if ( AMP_Theme_Support::READER_MODE_SLUG !== AMP_Options_Manager::get_option( Option::THEME_SUPPORT ) ) {
			return false;
		}

		// If the Legacy Reader mode is active, then a Reader theme is not going to be served.
		$reader_theme = AMP_Options_Manager::get_option( Option::READER_THEME );
		if ( ReaderThemes::DEFAULT_READER_THEME === AMP_Options_Manager::get_option( Option::READER_THEME ) ) {
			return false;
		}

		// Lastly, if the active theme is not the same as the reader theme, then we can switch to the reader theme.
		// Otherwise, the site should instead be in Transitional mode.
		return get_template() !== $reader_theme;
	}

	/**
	 * Whether the active theme was overridden with the reader theme.
	 *
	 * @return bool Whether theme overridden.
	 */
	public function is_theme_overridden() {
		return $this->theme_overridden;
	}

	/**
	 * Is an AMP request.
	 *
	 * @return bool Whether AMP request.
	 */
	public function is_amp_request() {
		return isset( $_GET[ amp_get_slug() ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Register the service with the system.
	 *
	 * @return void
	 */
	public function register() {
		// The following needs to run at plugins_loaded because that is when _wp_customize_include runs. Otherwise, the
		// most logical action would be setup_theme.
		add_action( 'plugins_loaded', [ $this, 'override_theme' ], 9 );

		add_filter( 'wp_prepare_themes_for_js', [ $this, 'filter_wp_prepare_themes_to_indicate_reader_theme' ] );
	}

	/**
	 * Filter themes for JS to remove action to delete the selected Reader theme and show a notice.
	 *
	 * @param array $prepared_themes Array of theme data.
	 * @return array Themes.
	 */
	public function filter_wp_prepare_themes_to_indicate_reader_theme( $prepared_themes ) {
		if ( AMP_Theme_Support::READER_MODE_SLUG !== AMP_Options_Manager::get_option( Option::THEME_SUPPORT ) ) {
			return $prepared_themes;
		}

		$reader_theme = AMP_Options_Manager::get_option( Option::READER_THEME );
		if ( isset( $prepared_themes[ $reader_theme ] ) ) {
			// Prevent theme from being deleted.
			unset( $prepared_themes[ $reader_theme ]['actions']['delete'] );

			$notice = sprintf(
				'<div class="notice notice-info notice-alt inline"><p>%s</p></div>',
				sprintf(
					wp_kses(
						/* translators: placeholder is link to AMP settings screen */
						__( 'This has been <a href="%s">selected</a> as the AMP Reader theme.', 'amp' ),
						[
							'a' => [ 'href' => true ],
						]
					),
					esc_url( add_query_arg( 'page', AMP_Options_Manager::OPTION_NAME, admin_url( 'admin.php' ) ) )
				)
			);

			$prepared_themes[ $reader_theme ]['description'] = $notice . $prepared_themes[ $reader_theme ]['description'];
		}

		return $prepared_themes;
	}

	/**
	 * Get reader theme.
	 *
	 * If the Reader template mode is enabled
	 *
	 * @return WP_Theme|null Theme if selected and no errors.
	 */
	public function get_reader_theme() {
		$reader_theme_slug = AMP_Options_Manager::get_option( Option::READER_THEME );
		if ( ! $reader_theme_slug ) {
			return null;
		}

		$reader_theme = wp_get_theme( $reader_theme_slug );
		if ( $reader_theme->errors() ) {
			return null;
		}

		return $reader_theme;
	}

	/**
	 * Get active theme.
	 *
	 * The theme that was active before switching to the Reader theme.
	 *
	 * @return WP_Theme|null
	 */
	public function get_active_theme() {
		return $this->active_theme;
	}

	/**
	 * Switch theme if in Reader mode, a Reader theme was selected, and the AMP query var is present.
	 *
	 * Note that AMP_Theme_Support will redirect to the non-AMP version if AMP is not available for the query.
	 *
	 * @see WP_Customize_Manager::start_previewing_theme() which provides for much of the inspiration here.
	 */
	public function override_theme() {
		if ( ! $this->is_enabled() || ! $this->is_amp_request() ) {
			return;
		}

		$theme = $this->get_reader_theme();
		if ( ! $theme instanceof WP_Theme ) {
			return;
		}

		$this->active_theme     = wp_get_theme();
		$this->reader_theme     = $theme;
		$this->theme_overridden = true;

		$get_template   = function () {
			return $this->reader_theme->get_template();
		};
		$get_stylesheet = function () {
			return $this->reader_theme->get_stylesheet();
		};

		add_filter( 'stylesheet', $get_stylesheet );
		add_filter( 'template', $get_template );
		add_filter(
			'pre_option_current_theme',
			function () {
				return $this->reader_theme->display( 'Name' );
			}
		);

		// @link: https://core.trac.wordpress.org/ticket/20027
		add_filter( 'pre_option_stylesheet', $get_stylesheet );
		add_filter( 'pre_option_template', $get_template );

		// Handle custom theme roots.
		add_filter(
			'pre_option_stylesheet_root',
			function () {
				return get_raw_theme_root( $this->reader_theme->get_stylesheet(), true );
			}
		);
		add_filter(
			'pre_option_template_root',
			function () {
				return get_raw_theme_root( $this->reader_theme->get_template(), true );
			}
		);

		$this->disable_widgets();
		add_filter( 'customize_previewable_devices', [ $this, 'customize_previewable_devices' ] );
		add_action( 'customize_register', [ $this, 'remove_customizer_themes_panel' ], 11 );
	}

	/**
	 * Disable widgets.
	 */
	public function disable_widgets() {
		add_filter( 'sidebars_widgets', '__return_empty_array', PHP_INT_MAX );
		add_filter(
			'customize_loaded_components',
			static function( $components ) {
				return array_diff( $components, [ 'widgets' ] );
			}
		);
	}

	/**
	 * Make tablet (smartphone) the default device when opening AMP Customizer.
	 *
	 * @param array $devices Devices.
	 * @return array Devices.
	 */
	public function customize_previewable_devices( $devices ) {
		if ( isset( $devices['tablet'] ) ) {
			unset( $devices['desktop']['default'] );
			$devices['tablet']['default'] = true;
		}
		return $devices;
	}

	/**
	 * Remove themes panel from AMP Customizer.
	 *
	 * @param WP_Customize_Manager $wp_customize Customize manager.
	 */
	public function remove_customizer_themes_panel( WP_Customize_Manager $wp_customize ) {
		$wp_customize->remove_panel( 'themes' );
	}
}
