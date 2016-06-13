<?php

/**
 * Plugin Name: ElasticPress
 * Description: Integrate WordPress search with Elasticsearch
 * Version:     2.1
 * Author:      Aaron Holbrook, Taylor Lovett, Matt Gross, 10up
 * Author URI:  http://10up.com
 * License:     GPLv2 or later
 * Text Domain: elasticpress
 * Domain Path: /lang/
 * This program derives work from Alley Interactive's SearchPress
 * and Automattic's VIP search plugin:
 *
 * Copyright (C) 2012-2013 Automattic
 * Copyright (C) 2013 SearchPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'EP_URL', plugin_dir_url( __FILE__ ) );
define( 'EP_VERSION', '2.1' );
define( 'EP_MODULES_DIR', dirname( __FILE__ ) . '/modules' );

require_once( 'classes/class-ep-config.php' );
require_once( 'classes/class-ep-api.php' );
require_once( 'classes/class-ep-sync-manager.php' );
require_once( 'modules/abstract-ep-module.php' );
require_once( 'classes/class-ep-module-loader.php' );


// Define a constant if we're network activated to allow plugin to respond accordingly.
$network_activated = ep_is_network_activated( plugin_basename( __FILE__ ) );

if ( $network_activated ) {
	define( 'EP_IS_NETWORK', true );
}

/**
 * WP CLI Commands
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once 'bin/wp-cli.php';
}

add_action( 'plugins_loaded', 'ep_loader' );

/**
 * Loads GUI classes if needed.
 */
function ep_loader() {
	load_plugin_textdomain( 'elasticpress', false, basename( dirname( __FILE__ ) ) . '/lang' ); // Load any available translations first.
	
	// Load the settings page.
	require_once( dirname( __FILE__ ) . '/classes/class-ep-settings.php' );
	new EP_Settings();
	
	if ( is_user_logged_in() && ! defined( 'WP_EP_DEBUG' ) ) {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		define( 'WP_EP_DEBUG', is_plugin_active( 'debug-bar-elasticpress/debug-bar-elasticpress.php' ) );
	}
}
