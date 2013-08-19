<?php
if ( !defined( 'ABSPATH' ) && !defined( 'WP_UNINSTALL_PLUGIN ') ) {
	exit;
}

function nwm_uninstall() {
	
	global $wpdb;

	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nwm_routes' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nwm_custom' );

	delete_option( 'nwm_version' );
	delete_option( 'nwm_settings' );
	delete_option( 'nwm_post_ids' );
	delete_option( 'nwm_route_order' );
	
}

/* Delete the tables and options from the db  */
nwm_uninstall();
?>