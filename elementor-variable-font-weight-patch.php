<?php
/**
 * Plugin Name: Elementor Variable Font Weight Patch
 * Description: Temporary compatibility patch that outputs missing Elementor global typography font-weight CSS custom properties for presets built on registered variable-weight fonts. Works around elementor/elementor#29496 and #36009. Remove once Elementor ships an upstream fix.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 *
 * -----------------------------------------------------------------------
 * WHY THIS PLUGIN EXISTS
 * -----------------------------------------------------------------------
 * Elementor's Global Fonts (Site Settings > Global Fonts) let you assign a
 * font-weight to each typography preset. When the assigned font is a
 * registered *variable-weight* font, Elementor's own typography controls
 * add a second "weight" slider control (for the variable font-weight axis)
 * and, due to a bug in Elementor core, the resulting
 * `--e-global-typography-{id}-font-weight` CSS custom property referenced
 * by widgets on the front end is never actually defined anywhere. The
 * configured weight is saved correctly in the database, and Elementor
 * *references* the variable correctly - it just never *defines* it.
 *
 * This plugin does not touch Elementor core. It reads the already-saved,
 * correct values from the active Elementor Site Kit at render time and
 * prints the missing CSS custom properties so the browser can resolve
 * them.
 *
 * DETECTING AN UPSTREAM FIX
 * -----------------------------------------------------------------------
 * If Elementor fixes this upstream by also sourcing the "weight" slider
 * value (the setting this plugin reads), any duplicate
 * `--e-global-typography-{id}-font-weight` declaration will resolve to the
 * same value - CSS custom properties are idempotent when redeclared
 * identically, so there is no functional conflict. Note this assumes
 * Elementor's fix reads the same field this plugin does; if a preset has a
 * stale/unused legacy "font_weight" value left over from before it was
 * switched to a variable font, and Elementor's fix surfaces that value
 * instead, the two declarations could differ for that preset specifically
 * - last one in the cascade wins, which is a display difference, not an
 * error.
 *
 * A more precise fix would skip output entirely once Elementor defines the
 * variable natively. That would require parsing Elementor's generated CSS
 * (which may live in a static file, an inline block, or be missing
 * entirely depending on caching settings) on every page load just to
 * answer a yes/no question - that filesystem/string-parsing cost, and its
 * fragility against caching layers and Elementor internals, isn't
 * justified for a handful of duplicate `:root` declarations. Continuing to
 * emit the fallback and removing this plugin once the upstream bug is
 * closed is the simpler and more robust choice.
 *
 * REMOVAL
 * -----------------------------------------------------------------------
 * Once the linked Elementor issues are resolved in a released version,
 * this entire plugin can simply be deactivated and deleted from the
 * Plugins screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Opt-out switch. Define as true in wp-config.php (or elsewhere loaded
 * before 'plugins_loaded') to disable all output from this plugin without
 * deactivating it, e.g.:
 *   define( 'ELEMENTOR_VARIABLE_FONT_WEIGHT_PATCH_DISABLE', true );
 */
if ( defined( 'ELEMENTOR_VARIABLE_FONT_WEIGHT_PATCH_DISABLE' ) && ELEMENTOR_VARIABLE_FONT_WEIGHT_PATCH_DISABLE ) {
	return;
}

/**
 * Decide whether this request is an eligible front-end page view.
 *
 * Deliberately excludes admin, AJAX, REST, cron, WP-CLI and the login
 * screen - there is no styled front-end output to patch in any of those
 * contexts, so there is nothing to gain from running there.
 *
 * @return bool
 */
function evfwp_is_frontend_request() {
	if ( is_admin() ) {
		return false;
	}

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return false;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}

	global $pagenow;

	if ( ! empty( $pagenow ) && 'wp-login.php' === $pagenow ) {
		return false;
	}

	return true;
}

/**
 * Safely fetch the active Elementor Site Kit for the current front-end
 * request, without assuming any particular Elementor version keeps the
 * same class/property/method names.
 *
 * @return object|null
 */
function evfwp_get_active_kit() {
	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		return null;
	}

	try {
		$elementor = \Elementor\Plugin::$instance;

		if ( empty( $elementor ) || empty( $elementor->kits_manager ) ) {
			return null;
		}

		$kits_manager = $elementor->kits_manager;

		if ( ! method_exists( $kits_manager, 'get_active_kit_for_frontend' ) ) {
			return null;
		}

		$kit = $kits_manager->get_active_kit_for_frontend();

		if ( empty( $kit ) || ! is_object( $kit ) || ! method_exists( $kit, 'get_settings' ) ) {
			return null;
		}

		return $kit;
	} catch ( \Throwable $e ) {
		if (
			defined( 'WP_DEBUG' ) && WP_DEBUG &&
			defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG
		) {
			error_log(
				'Elementor Variable Font Weight Patch: ' . $e->getMessage()
			);
		}
		return null;
	}
}

/**
 * Reduce an Elementor typography item's raw "_id" to a safe token for use
 * inside a CSS custom property name.
 *
 * @param mixed $id
 * @return string
 */
function evfwp_sanitize_id( $id ) {
	if ( ! is_string( $id ) && ! is_numeric( $id ) ) {
		return '';
	}

	$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id );

	return substr( $id, 0, 64 );
}

/**
 * Validate and normalise a font-weight value pulled from Elementor's saved
 * "typography_weight" (variable font-weight slider) setting. Only accepts
 * the CSS-safe keywords "normal"/"bold" or a numeric weight from 1-1000,
 * matching the CSS font-weight spec's valid variable range.
 *
 * @param mixed $raw
 * @return string|null
 */
function evfwp_sanitize_weight( $raw ) {
	// Elementor's slider control saves { unit, size, sizes }.
	if ( is_array( $raw ) ) {
		$raw = $raw['size'] ?? null;
	}

	if ( null === $raw || '' === $raw ) {
		return null;
	}

	if ( is_string( $raw ) ) {
		$raw = trim( $raw );
	}

	if ( in_array( $raw, [ 'normal', 'bold' ], true ) ) {
		return $raw;
	}

	if ( is_numeric( $raw ) ) {
		$weight = (int) $raw;

		if ( $weight >= 1 && $weight <= 1000 ) {
			return (string) $weight;
		}
	}

	return null;
}

/**
 * Read the active Kit's global typography settings and collect a
 * sanitised id => weight map for every item that has a usable variable
 * font-weight value.
 *
 * Only items with a "typography_weight" value are considered. Elementor
 * only populates that field for presets using a registered variable-weight
 * font, so this naturally and automatically limits output to exactly the
 * presets affected by the bug - no font list or font-name matching needed,
 * and no per-site hardcoding of preset ids or titles.
 *
 * @param object $kit
 * @return array<string, string>
 */
function evfwp_collect_weight_variables( $kit ) {
	$entries = [];

	$groups = [ 'system_typography', 'custom_typography' ];

	foreach ( $groups as $group_key ) {
		try {
			$items = $kit->get_settings( $group_key );
		} catch ( \Throwable $e ) {
			if (
				defined( 'WP_DEBUG' ) && WP_DEBUG &&
				defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG
			) {
				error_log(
					'Elementor Variable Font Weight Patch: ' . $e->getMessage()
				);
			}
			continue;
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			continue;
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! isset( $item['_id'] ) || ! isset( $item['typography_weight'] ) ) {
				continue;
			}

			$id = evfwp_sanitize_id( $item['_id'] );

			if ( '' === $id ) {
				continue;
			}

			$weight = evfwp_sanitize_weight( $item['typography_weight'] );

			if ( null === $weight ) {
				continue;
			}

			$entries[ $id ] = $weight;
		}
	}

	return $entries;
}

/**
 * Build the compact :root CSS block for the given id => weight entries.
 *
 * Both the standard "-font-weight" variable (the name Elementor's widget
 * CSS actually references) and a "-weight" variant (seen referenced by
 * some existing manual workarounds/customisations) are emitted for each
 * entry, so this plugin is a drop-in replacement for either naming
 * convention.
 *
 * @param array<string, string> $entries
 * @return string
 */
function evfwp_build_css( array $entries ) {
	if ( empty( $entries ) ) {
		return '';
	}

	$declarations = '';

	foreach ( $entries as $id => $weight ) {
		$declarations .= sprintf(
			'--e-global-typography-%1$s-font-weight:%2$s;--e-global-typography-%1$s-weight:%2$s;',
			$id,
			$weight
		);
	}

	return ':root{' . $declarations . '}';
}

/**
 * Entry point: compute the missing CSS variables for the current request
 * and attach them to the page.
 */
function evfwp_output_missing_font_weight_vars() {
	if ( ! evfwp_is_frontend_request() ) {
		return;
	}

	$kit = evfwp_get_active_kit();

	if ( null === $kit ) {
		return;
	}

	$entries = evfwp_collect_weight_variables( $kit );

	if ( empty( $entries ) ) {
		return;
	}

	$css = evfwp_build_css( $entries );

	if ( '' === $css ) {
		return;
	}

	if ( wp_style_is( 'elementor-frontend', 'registered' ) || wp_style_is( 'elementor-frontend', 'enqueued' ) ) {
		wp_add_inline_style( 'elementor-frontend', $css );
		return;
	}

	// Fallback: the expected Elementor stylesheet handle isn't available
	// (e.g. an Elementor version that renamed it). Print directly in
	// <head> instead of silently doing nothing.
	add_action(
		'wp_head',
		function () use ( $css ) {
			echo '<style id="evfwp-font-weight-fix">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $css is built exclusively from whitelisted characters/values in evfwp_sanitize_id() and evfwp_sanitize_weight().
		}
	);
}

add_action( 'wp_enqueue_scripts', 'evfwp_output_missing_font_weight_vars', 20 );
