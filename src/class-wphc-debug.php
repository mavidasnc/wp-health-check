<?php
/**
 * Rotta diagnostica GET /debug: callback registrati sugli hook dei transient di update.
 *
 * @package WP_Health_Check
 */

/**
 * Rotta diagnostica GET /debug (solo manage_options, vedi WPHC_Auth::debug_permission()): elenca in forma
 * leggibile i callback registrati sui filtri dei transient di update e mostra cosa vedrebbe la lettura
 * con e senza gli short-circuit "pre_site_transient_update_*", per capire perche' un sito mostra conteggi
 * di aggiornamenti sbagliati.
 *
 * Raccoglie la sezione P del file 1.38.1 (tranne il permission_callback, che sta in WPHC_Auth). Manipola
 * $wp_filter con `global`, come il codice originale: e' stato di WordPress, non del plugin.
 */
final class WPHC_Debug {

	// -----------------------------------------------------------------------
	// ROTTA DIAGNOSTICA: GET /debug
	// -----------------------------------------------------------------------

	/**
	 * Restituisce l'elenco leggibile dei callback registrati su un hook, con la
	 * loro priorita'. Serve a scoprire quali plugin modificano un filtro (es. il
	 * transient degli aggiornamenti plugin).
	 *
	 * @param string $hook Nome dell'hook/filtro.
	 * @return string[] Elenco "priorita': callback".
	 */
	private static function hook_callbacks( $hook ) {
		global $wp_filter;
		$out = array();
		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
			return $out;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn   = $cb['function'];
				$name = 'sconosciuto';
				if ( is_string( $fn ) ) {
					$name = $fn;
				} elseif ( is_array( $fn ) && isset( $fn[0], $fn[1] ) ) {
					$cls  = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
					$name = $cls . '::' . $fn[1];
				} elseif ( $fn instanceof Closure ) {
					$name = 'Closure';
				}
				$out[] = $priority . ': ' . $name;
			}
		}
		return $out;
	}

	/**
	 * Rotta diagnostica /debug: aiuta a capire perche' il conteggio degli
	 * aggiornamenti plugin visto in admin puo' differire da quello visto via REST
	 * (/health). Confronta il transient update_plugins FILTRATO (cio' che
	 * /health legge) con quello GREZZO memorizzato (senza i filtri di terze
	 * parti) ed elenca i callback registrati sui relativi filtri. Sola lettura.
	 *
	 * @param WP_REST_Request $request Richiesta REST corrente.
	 * @return WP_REST_Response Dati diagnostici.
	 */
	public static function route_debug( WP_REST_Request $request ) {
		unset( $request );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		// 1. Transient FILTRATO: e' esattamente cio' che legge /health.
		$filtered       = get_site_transient( 'update_plugins' );
		$filtered_slugs = ( is_object( $filtered ) && ! empty( $filtered->response ) ) ? array_keys( (array) $filtered->response ) : array();

		// 2. Transient GREZZO memorizzato: si rimuovono temporaneamente i filtri
		// di terze parti su questo transient, si rilegge e si ripristinano. Cosi'
		// si vede cosa il cron ha davvero PERSISTITO, al netto delle iniezioni/
		// rimozioni a runtime dipendenti dal contesto (admin vs REST).
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- muting/ripristino temporaneo e controllato dei filtri per una lettura diagnostica grezza.
		global $wp_filter;
		$hooks_to_mute = array( 'site_transient_update_plugins', 'pre_site_transient_update_plugins' );
		$saved         = array();
		foreach ( $hooks_to_mute as $h ) {
			if ( isset( $wp_filter[ $h ] ) ) {
				$saved[ $h ] = $wp_filter[ $h ];
				unset( $wp_filter[ $h ] );
			}
		}
		$raw = get_site_transient( 'update_plugins' );
		foreach ( $saved as $h => $obj ) {
			$wp_filter[ $h ] = $obj;
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		$raw_slugs = ( is_object( $raw ) && ! empty( $raw->response ) ) ? array_keys( (array) $raw->response ) : array();

		// 3. Cosa vede get_plugin_updates() (usata da /detail/plugins), con lo
		// stesso trattamento del fix (short-circuit "pre_" neutralizzato).
		$muted_fix      = WPHC_Util::mute_update_shortcircuit();
		$plugin_updates = get_plugin_updates();
		$health_tp      = get_site_transient( 'update_plugins' );
		WPHC_Util::restore_update_shortcircuit( $muted_fix );
		$health_plugins_updates = ( is_object( $health_tp ) && ! empty( $health_tp->response ) ) ? count( (array) $health_tp->response ) : 0;

		return rest_ensure_response(
			array(
				'context'                   => array(
					'is_admin'           => is_admin(),
					'rest_request'       => defined( 'REST_REQUEST' ) && REST_REQUEST,
					'doing_cron'         => defined( 'DOING_CRON' ) && DOING_CRON,
					'user_id'            => get_current_user_id(),
					'can_update_plugins' => current_user_can( 'update_plugins' ),
					'is_multisite'       => is_multisite(),
					'home_url'           => home_url(),
					'site_url'           => site_url(),
				),
				'update_plugins_filtered'   => array(
					'exists'         => is_object( $filtered ),
					'last_checked'   => ( is_object( $filtered ) && ! empty( $filtered->last_checked ) ) ? gmdate( 'c', (int) $filtered->last_checked ) : null,
					'response_count' => count( $filtered_slugs ),
					'response_slugs' => $filtered_slugs,
				),
				'update_plugins_raw_stored' => array(
					'response_count' => count( $raw_slugs ),
					'response_slugs' => $raw_slugs,
				),
				'get_plugin_updates'        => array(
					'count' => count( $plugin_updates ),
					'slugs' => array_keys( $plugin_updates ),
				),
				// Conteggio che /health riporta ORA (dalla 1.16.0), con lo
				// short-circuit "pre_site_transient_update_*" neutralizzato: deve
				// coincidere con quello visto in admin.
				'health_plugins_updates'    => $health_plugins_updates,
				'filters'                   => array(
					'site_transient_update_plugins'     => self::hook_callbacks( 'site_transient_update_plugins' ),
					'pre_set_site_transient_update_plugins' => self::hook_callbacks( 'pre_set_site_transient_update_plugins' ),
					'pre_site_transient_update_plugins' => self::hook_callbacks( 'pre_site_transient_update_plugins' ),
				),
			)
		);
	}
}
