<?php
/**
 * @wordpress-plugin
 *
 * WooCommerce REST API payment statistic
 *
 * @package           cf-geoplugin
 * @link              https://github.com/InfinitumForm/woocommerce-rest-statistic
 * @author            Ivijan-Stefan Stipic <ivijan.stefan@gmail.com>
 * @copyright         2014-2022 Ivijan-Stefan Stipic
 * @license           GPL v2 or later
 *
 * Plugin Name:       WooCommerce REST API payment statistic
 * Plugin URI:        https://infinitumform.com/
 * Description:       This plugin allows you to access payment statistics via REST api.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.0
 * Author:            Ivijan-Stefan Stipic
 * Author URI:        https://infinitumform.com/
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-statistic
 * Domain Path:       /languages
 * Network:           true
 * Update URI:        https://github.com/InfinitumForm/woocommerce-rest-statistic
 *
 * Copyright (C) 2022 Ivijan-Stefan Stipic
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
 
// If someone try to called this file directly via URL, abort.
if ( ! defined( 'WPINC' ) ) { die( "Don't mess with us." ); }
if ( ! defined( 'ABSPATH' ) ) { exit; }

if( ! class_exists('WC_REST_Payments') ) : class WC_REST_Payments {
	
	// PRIVATE: Class instance
	private static $instance;
	
	// PRIVATE: Plugin namespace
	private $namespace = 'wc-statistic/v1';
	
	// PRIVATE: Plugin available routes
	private $routes = [
		'completed',
		'refounded',
		'pending',
		'processing',
		'on-hold',
		'cancelled'
	];
	
	// PRIVATE: Constructor
	private function __construct () {
		add_action('plugins_loaded', [$this, 'register_textdomain']);
		add_action('rest_api_init', [$this, 'register_rest_routes']);
	}
	
	// Register REST routes
	public function register_rest_routes (){
		$routes = apply_filters('wc-statistic/register_rest_routes', $this->routes, $this->namespace);
		foreach($routes as $route){
			if(method_exists($this, "rest_callback__{$route}")) {
				register_rest_route( $this->namespace, "/{$route}", [
					'methods' => ['GET', 'POST'],
					'permission_callback' => '__return_true',
					'callback' => [$this, "rest_callback__{$route}"],
				], [], true );
			}
		}
	}
	
	// REST callback for the completed orders
	public function rest_callback__completed ( $rest_data ) {
		return new WP_REST_Response( $this->get_from_database($rest_data, 'wc-completed') );
	}
	
	// Get results from the database
	protected function get_from_database($rest_data, $type = 'wc-completed') {
		global $wpdb;
		
		$number_of_customers = absint($_GET['customers_per_page'] ?? 5);
		
		$currency = get_option('woocommerce_currency');
		
		// Get predefined data
		$results = [
			'today' => 0,
			'this_month' => [],
			'this_year' => [],
			'last_7_days' => [],
			'last_28_days' => [],
			'last_month' => [],
			'last_3_months' => [],
			'last_year' => [],
			'all_time' => 0,
			'range' => [],
			'customers' => [],
			'currency' => $currency
		];
		
		// Set filter
		$filter = explode(',', sanitize_text_field($_REQUEST['filter'] ?? ''));
		$filter = array_map('sanitize_text_field', $filter);
		$filter = array_map('trim', $filter);
		$filter = array_filter($filter);
		if( $filter ) {
			$allowed_results = $results;
			$results = [];
			foreach($filter as $filter){
				if( in_array($filter, $allowed_results) !== false ) {
					$results[$filter] = $allowed_results[$filter];
				}
			}
		}
		
		// Set return helper
		$return = isset($results['today']) || isset($results['all_time']);
		
		// Get customer names
		if($number_of_customers > 0 && isset($results['customers']) && ( $data = $wpdb->get_results( $wpdb->prepare( "
			SELECT
				CONCAT(`cl`.`first_name`, ' ', `cl`.`last_name`) AS `name`,
				`os`.`net_total` AS `total`,
				`os`.`date_created`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
				JOIN `{$wpdb->prefix}wc_customer_lookup` `cl` ON `cl`.`customer_id` = `os`.`customer_id`
			WHERE
				`os`.`status` = '{$type}'
			ORDER BY `os`.`order_id` DESC
			LIMIT %d
		", $number_of_customers ) ) ) ) {
			foreach( $data as $record ) {
				$results['customers'][]=(object)[
					'name' => $record->name,
					'date' => $record->date_created,
					'amount'=>(float)$record->total,
					'currency' => $currency
				];
			}
			$return = true;
		}
		
		// Get today's order
		if ( isset($results['today']) && $today = $wpdb->get_var( "
			SELECT
				SUM(`net_total`)
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				DATE(`os`.`date_created`) = DATE(NOW())
			AND
				`os`.`status` = '{$type}'
		" ) ) {
			$results['today']=(float)$today;
		}
		
		// Get all times's order
		if ( isset($results['all_time']) && $all_time = $wpdb->get_var( "
			SELECT
				SUM(`net_total`)
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				`os`.`status` = '{$type}'
		" ) ) {
			$results['all_time']=(float)$all_time;
		}
		
		// Get orders last 7 days
		if ( isset($results['last_7_days']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m-%d') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				`os`.`date_created` > DATE_SUB(DATE(NOW()), INTERVAL 7 DAY) 
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m-%d')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['last_7_days'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders last 28 days
		if ( isset($results['last_28_days']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m-%d') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				`os`.`date_created` >= DATE_SUB(DATE(NOW()), INTERVAL 28 DAY) 
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m-%d')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['last_28_days'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders last 1 month
		if ( isset($results['last_month']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m-%d') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				`os`.`date_created` > DATE_SUB(DATE(NOW()), INTERVAL 1 MONTH) 
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m-%d')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['last_month'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders last 3 months
		if ( isset($results['last_3_months']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				`os`.`date_created` > DATE_SUB(DATE(NOW()), INTERVAL 3 MONTH) 
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['last_3_months'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders last year
		if ( isset($results['last_year']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m-%d') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				YEAR(`os`.`date_created`) = YEAR(CURDATE()- INTERVAL 1 YEAR)
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m-%d')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['last_year'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders this year
		if ( isset($results['this_year']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m-%d') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				YEAR(`os`.`date_created`) = YEAR(CURDATE())
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m-%d')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['this_year'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders this_month
		if ( isset($results['this_month']) && $data = $wpdb->get_results( "
			SELECT
				SUM(`net_total`) AS `total`,
				DATE_FORMAT(`date_created_gmt`,'%Y-%m-%d') AS `date`
			FROM
				`{$wpdb->prefix}wc_order_stats` `os`
			WHERE
				MONTH(`os`.`date_created`) = MONTH(CURDATE())
			AND
				`os`.`status` = '{$type}'
			GROUP BY DATE_FORMAT(`os`.`date_created_gmt`, '%Y-%m-%d')
			ORDER BY `os`.`date_created_gmt`
		" ) ) {
			foreach( $data as $record ) {
				$results['this_month'][$record->date]=(float)$record->total;
			}
			$return = true;
		}
		
		// Get orders in range between 2 dates
		if ( isset($results['range']) && isset($_REQUEST['between']) ) {
			
			$between = explode(',', sanitize_text_field($_REQUEST['between'] ?? ''));
			$between = array_map('sanitize_text_field', $between);
			$between = array_map('trim', $between);
			$between = array_filter($between);
			
			if(isset($between[1])) {
				if ($data = $wpdb->get_results( "
					SELECT
						`net_total` AS `total`,
						`date_created_gmt` AS `date`
					FROM
						`{$wpdb->prefix}wc_order_stats` `os`
					WHERE
						" . $wpdb->prepare( "(DATE(`os`.`date_created`) BETWEEN DATE(%s) AND DATE(%s)) ", $between[0], $between[1]) . "
					AND
						`os`.`status` = '{$type}'
					GROUP BY `os`.`date_created_gmt`
					ORDER BY `os`.`date_created_gmt`
				") ) {
					foreach( $data as $record ) {
						$results['range'][$record->date]=(float)$record->total;
					}
					$return = true;
				}
			}
		}
		
		return array_merge(
			$results,
			['return'=>$return]
		);
	}
	
	// Register textdomain
	public function register_textdomain () {
		if ( is_textdomain_loaded( 'wc-statistic' ) ) {
			unload_textdomain( 'wc-statistic' );
		}
		
		// Get locale
		$locale = apply_filters( 'wc-statistic/locale', get_locale(), 'wc-statistic' );
		
		// We need standard file
		$mofile = sprintf( '%s-%s.mo', 'wc-statistic', $locale );
		
		// Check first inside `/wp-content/languages/plugins`
		$domain_path = path_join( WP_LANG_DIR, 'plugins' );
		$loaded = load_textdomain( 'wc-statistic', path_join( $domain_path, $mofile ) );
		
		// Or inside `/wp-content/languages`
		if ( ! $loaded ) {
			$loaded = load_textdomain( 'wc-statistic', path_join( WP_LANG_DIR, $mofile ) );
		}
		
		// Or inside `/wp-content/plugin/woocommerce-redirect-to-checkout/languages`
		if ( ! $loaded ) {
			$domain_path = __DIR__ . DIRECTORY_SEPARATOR . 'languages';
			$loaded = load_textdomain( 'wc-statistic', path_join( $domain_path, $mofile ) );
			
			// Or load with only locale without prefix
			if ( ! $loaded ) {
				$loaded = load_textdomain( 'wc-statistic', path_join( $domain_path, "{$locale}.mo" ) );
			}

			// Or old fashion way
			if ( ! $loaded && function_exists('load_plugin_textdomain') ) {
				load_plugin_textdomain( 'wc-statistic', false, $domain_path );
			}
		}
	}
	
	// Load plugin on the safe way
	public static function instance(){
		if( !self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
} endif;

// Load plugin
if(class_exists('WC_REST_Payments') && method_exists('WC_REST_Payments', 'instance')) {
	WC_REST_Payments::instance();
}