<?php
/**
 * Bootstraps Global Styles.
 *
 * @package gutenberg
 */

/**
 * Register webfonts defined in theme.json.
 */
function gutenberg_register_webfonts_from_theme_json() {
	// Get settings from theme.json.
	$theme_settings = WP_Theme_JSON_Resolver_Gutenberg::get_theme_data()->get_settings();

	// Bail out early if there are no settings for webfonts.
	if ( empty( $theme_settings['typography'] ) || empty( $theme_settings['typography']['fontFamilies'] ) ) {
		return;
	}

	$webfonts = array();

	// Look for fontFamilies.
	foreach ( $theme_settings['typography']['fontFamilies'] as $font_families ) {
		foreach ( $font_families as $font_family ) {

			// Skip if fontFace is not defined.
			if ( empty( $font_family['fontFace'] ) ) {
				continue;
			}

			$font_family['fontFace'] = (array) $font_family['fontFace'];

			foreach ( $font_family['fontFace'] as $font_face ) {
				if ( isset( $font_face['origin'] ) && 'gutenberg_wp_webfonts_api' === $font_face['origin'] ) {
					// This webfont was already registered programmatically through the Webfonts API.
					continue;
				}

				// Convert keys to kebab-case.
				foreach ( $font_face as $property => $value ) {
					$kebab_case               = _wp_to_kebab_case( $property );
					$font_face[ $kebab_case ] = $value;
					if ( $kebab_case !== $property ) {
						unset( $font_face[ $property ] );
					}
				}

				$webfonts[] = $font_face;
			}
		}
	}
	foreach ( $webfonts as $webfont ) {
		wp_webfonts()->register_font( $webfont );
	}
}

/**
 * Add missing fonts data to the global styles.
 *
 * @param array $data The global styles.
 *
 * @return array The global styles with missing fonts data.
 */
function gutenberg_add_registered_webfonts_to_theme_json( $data ) {
	// font_families_registered = [ WP_Webfonts_Font_Families ]
	$font_families_registered = wp_webfonts()->get_all_webfonts();

	// Make sure the path to settings.typography.fontFamilies.theme exists
	// before adding missing fonts.
	if ( empty( $data['settings'] ) ) {
		$data['settings'] = array();
	}
	if ( empty( $data['settings']['typography'] ) ) {
		$data['settings']['typography'] = array();
	}
	if ( empty( $data['settings']['typography']['fontFamilies'] ) ) {
		$data['settings']['typography']['fontFamilies'] = array();
	}

	foreach ( $font_families_registered as $slug => $font_family ) {
		$family     = $font_family->get_font_family_name();
		$font_faces = array();

		foreach ( $font_family->get_font_faces() as $font_face ) {
			$camel_cased = array( 'origin' => 'gutenberg_wp_webfonts_api' );
			foreach ( $font_face->get_font() as $key => $value ) {
				$camel_cased[ lcfirst( str_replace( '-', '', ucwords( $key, '-' ) ) ) ] = $value;
			}
			$font_faces[] = $camel_cased;
		}

		$data['settings']['typography']['fontFamilies'][] = array(
			'fontFamily' => false !== strpos( $family, ' ' ) ? "'{$family}'" : $family,
			'name'       => $family,
			'slug'       => $slug,
			'fontFace'   => $font_faces,
		);
	}

	return $data;
}

add_action( 'wp_loaded', 'gutenberg_register_webfonts_from_theme_json' );
