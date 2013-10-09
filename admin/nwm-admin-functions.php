<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', 'nwm_output_buffer' );
add_action( 'admin_init', 'nwm_init' );
add_action( 'admin_menu', 'nwm_create_admin_menu');
add_action( 'wp_ajax_save_location', 'nwm_save_location' );
add_action( 'wp_ajax_delete_location', 'nwm_delete_location' );
add_action( 'wp_ajax_update_location', 'nwm_update_location' );
add_action( 'wp_ajax_update_order', 'nwm_update_order' );
add_action( 'wp_ajax_load_content', 'nwm_load_content' );
add_action( 'wp_ajax_load_map', 'nwm_load_map' );
add_action( 'wp_ajax_find_post_title', 'nwm_find_post_title' );
add_action( 'save_post', 'nwm_check_used_id' );
add_filter( 'wp_loaded', 'nwm_load_textdomain' );

/* Prevent headers already send after using wp_redirect */
function nwm_output_buffer() {
	ob_start();
}

function nwm_init() {
	
	if ( current_user_can( 'delete_posts' ) ) {
		 add_action( 'delete_post', 'nwm_sync_db' );
	}
	
	/* Include the required files */
	require_once (dirname (__FILE__) . '/nwm-check-upgrade.php');
	require_once (dirname (__FILE__) . '/nwm-map-editor.php');
	require_once (dirname (__FILE__) . '/nwm-manage-maps.php');
	require_once (dirname (__FILE__) . '/nwm-map-settings.php');
	
	register_setting( 'nwm_settings', 'nwm_settings', 'nwm_settings_check' );
	
}

function nwm_create_admin_menu() {
	add_menu_page( 'Nomad Map', 'Nomad Map', 'manage_options', 'nwm_map_editor', 'nwm_map_editor' );
	add_submenu_page( 'nwm_map_editor', 'Route Editor', 'Route Editor', 'manage_options', 'nwm_map_editor', 'nwm_map_editor' );
	add_submenu_page( 'nwm_map_editor', 'Manage Maps', 'Manage Maps', 'manage_options', 'nwm_manage_maps', 'nwm_manage_maps' );
	add_submenu_page( 'nwm_map_editor', 'Settings', 'Settings', 'manage_options', 'nwm_settings', 'nwm_settings_page' );	
	add_submenu_page( 'nwm_map_editor', 'FAQ', 'FAQ', 'manage_options', 'nwm_faq', 'nwm_faq' );	
}

/* Save a new location */
function nwm_save_location() {
		
	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_save' );
		
	$recieved_data = json_decode( stripslashes( $_POST['last_update'] ) );
	
	if ( ( $recieved_data->excerpt ) || ( $recieved_data->schedule ) ) {
		$last_id = nwm_save_location_excerpt( $recieved_data );
	} else {
		$last_id = nwm_save_location_custom( $recieved_data );
	}
	
	if ( $last_id ) {		
		$map_id = nwm_check_map_id( $recieved_data->map_id );
		
		/* Update the route order for the selected map */
		nwm_update_option_value( 'nwm_route_order', $map_id, $last_id );

		$last_post_id = ( int ) $recieved_data->post_id;
		
		if ( $last_post_id ) {
			/* Update the list of used post ids for the selected map */
			nwm_update_option_value( 'nwm_post_ids', $map_id, $last_post_id );
		}
		
		$response = array( 'success' => true,
						   'id' => $last_id, 
						   'delete_nonce' => wp_create_nonce( 'nwm_nonce_delete_'.$last_id ), 
						   'update_nonce' => wp_create_nonce( 'nwm_nonce_update_'.$last_id ),
						   'load_nonce' => wp_create_nonce( 'nwm_nonce_load_'.$last_id )
						  );
		
		nwm_delete_transients( $map_id );
		wp_send_json( $response );
	}
		
	die();
	
}

/* Make sure we have a valid map_id, if not then set it to 1 */
function nwm_check_map_id ( $recieved_map_id ) {

	if ( !absint( $recieved_map_id ) ) {
		$map_id = 1;
	} else {
		$map_id = $recieved_map_id;
	}	
	
	return $map_id;
	
}

/* Save the new location */
function nwm_save_location_excerpt( $recieved_data ) {
	
	$thumb_id = absint( $recieved_data->thumb_id );
	
	if ( $recieved_data->schedule ) {
		$post_id = 0;
		$schedule = 1;
		$recieved_data = $recieved_data->schedule;
	} else {
		$post_id = absint( $recieved_data->post_id );
		$schedule = 0;
		$recieved_data = $recieved_data->excerpt;
	}
	
	$location = sanitize_text_field( $recieved_data->location );
	$latlng = nwm_check_latlng( $recieved_data->latlng );
	$travel_dates = nwm_check_travel_dates( $recieved_data );
	$last_id = nwm_insert_location( $post_id, $thumb_id, $latlng, $location, $travel_dates, $schedule );
	
	return $last_id;	
		
}

/*
Save the new location with just the custom text, latlng and location name. 
*/
function nwm_save_location_custom( $recieved_data ) {
	
	global $wpdb;
	
	$location = sanitize_text_field( $recieved_data->custom->location );
	$marker_title = sanitize_text_field( $recieved_data->custom->title );
	$marker_content = limit_words( sanitize_text_field( $recieved_data->custom->content ), 25 );
	$marker_url = esc_url_raw( $recieved_data->custom->url, array( 'http', 'https' ) );
	$latlng = nwm_check_latlng( $recieved_data->custom->latlng );
	$travel_dates = nwm_check_travel_dates( $recieved_data->custom );
	$thumb_id = absint( $recieved_data->thumb_id );
	
	/*
	0 indicates a custom locations, and will tell us to look for the data in the 
	nwm_custom table instead of trying to get the post excerpt and thumbnail
	*/
	$last_id = nwm_insert_location( $post_id = 0, $thumb_id, $latlng, $location, $travel_dates, $schedule = 0 );
	$result = $wpdb->query( 
					$wpdb->prepare( 
							"
							INSERT INTO $wpdb->nwm_custom
							(nwm_id, content, url, title)
							VALUES (%d, %s, %s, %s)
							", 
							$last_id,
							$marker_content,
							$marker_url,
							$marker_title
					)
			   );	
	
	if ( $result === false ) {
		wp_send_json_error();
	} else {	
		return $last_id;		
	}
	
}

/* Delete a single location */
function nwm_delete_location() {
	
	global $wpdb;
	
	$nwm_id = absint( $_POST['nwm_id'] );
	$post_id = absint( $_POST['post_id'] );
	$map_id = absint( $_POST['map_id'] );

	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_delete_'.$nwm_id );
				
	$result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->nwm_routes WHERE nwm_id = %d", $nwm_id ) );
	
	/* If the post id is false, there must also be custom content to delete from the nwm_custom table */
	if ( !$post_id  ) {
		 $custom_result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->nwm_custom WHERE nwm_id = %d", $nwm_id ) );
		
		if ( $custom_result === false ) {
			wp_send_json_error();
		}				
	}
							
	if ( $result === false ) {
		wp_send_json_error();
	} else {	
		nwm_remove_option_value( 'nwm_route_order', $map_id, $nwm_id );
		nwm_remove_option_value( 'nwm_post_ids', $map_id, $post_id );
		
		nwm_delete_transients( $map_id );		
		wp_send_json_success();
	}		
	
}

/* Add a route value to one of the option fields used by the map */
function nwm_update_option_value( $option_name, $map_id, $last_id ) {
	
	$option_values = get_option( $option_name );
	
	if ( ( !$option_values ) || ( !count( $option_values[$map_id] ) ) ) {
		$option_values[$map_id] = $last_id;
	} else {
		$option_values[$map_id] = $option_values[$map_id].','.$last_id;
	}
	
	update_option( $option_name, $option_values );		
	
}

/* Delete a route value from one of the option fields used by the map */
function nwm_remove_option_value( $option_name, $map_id, $target_id ) {
	
	$option_values = get_option( $option_name );	
	$exp_option_values  = explode( ',', $option_values[$map_id] );
	
	foreach ( $exp_option_values as $k => $id ) {
		if ( $id == $target_id ) {
			unset( $exp_option_values[$k] );
			break;
		}
	}
	
	/* 
	Either remove the map index from the order, or implode the updated route order.
	*/
	if ( !count( $exp_option_values ) ) {
		unset( $option_values[$map_id] );
	} else {
		$option_values[$map_id] = implode( ",", $exp_option_values );
	}
	
	update_option( $option_name, $option_values );	
	
}

/* Update the location data */
function nwm_update_location() {
	
	global $wpdb;
	
	$recieved_data = json_decode( stripslashes( $_POST['last_update'] ) );
	$nwm_id = absint( $recieved_data->nwm_id );
	$map_id = absint( $recieved_data->map_id );
	$thumb_id = absint( $recieved_data->thumb_id );

	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_update_'.$nwm_id );	
	
	/* Check if the received data is for a post excerpt */
	if ( $recieved_data->excerpt ) {
		$post_id = absint( $recieved_data->excerpt->post_id );
			
		if ( $post_id ) { 
			$location = sanitize_text_field( $recieved_data->excerpt->location );
			$latlng = nwm_check_latlng( $recieved_data->excerpt->latlng );
			$travel_dates = nwm_check_travel_dates( $recieved_data->excerpt );
			
			/* Check if we need to update the list of used post ids */
			nwm_check_post_ids( $recieved_data, $map_id );
			
			/* Check if this entry used to be a custom entry, if so we need to delete the data from the custom table */
			$delete_result = nwm_check_custom_delete( $recieved_data, $nwm_id );			
			
			/* Update the location table */
			$result = nwm_update_location_query( $post_id, $thumb_id, $latlng, $location, $nwm_id, $travel_dates, $schedule = 0 );
			
			if ( ( $result === false ) || ( $delete_result === false ) ) {	
				wp_send_json_error();
			} else {	
				nwm_delete_transients( $map_id );
				$response = array( 'success' => true, 
								   'type' => 'excerpt', 
								   'url' => esc_url( get_permalink( $post_id ) )
								  );
								  
				wp_send_json( $response );
			}
		}
	}
	
	/* Check if the recieved data contains custom content */
	if ( $recieved_data->custom ) {
		$location = sanitize_text_field( $recieved_data->custom->location );
		$latlng = nwm_check_latlng( $recieved_data->custom->latlng  );
		$marker_content = limit_words( sanitize_text_field( $recieved_data->custom->content ), 25 );
		$title = sanitize_text_field( $recieved_data->custom->title );
		$url = esc_url_raw( $recieved_data->custom->url );
		$travel_dates = nwm_check_travel_dates( $recieved_data->custom );
		
		/* Update the location table */
		$location_result = nwm_update_location_query( $post_id = 0, $thumb_id, $latlng, $location, $nwm_id , $travel_dates, $schedule = 0 );
		
		$result = $wpdb->query( 
				  		$wpdb->prepare( 
								"
								INSERT INTO $wpdb->nwm_custom (content, url, title, nwm_id)
									 VALUES (%s, %s, %s, %d)
								ON DUPLICATE KEY UPDATE content = VALUES(content), url = VALUES(url), title = VALUES(title)
								",
								$marker_content, 
								$url,
								$title,
								$nwm_id
						)
				  );		
		
		if ( ( $result === false ) || ( $location_result === false ) ) {
			wp_send_json_error();
		} else {	
			nwm_delete_transients( $map_id );
			$response = array( 'success' => true, 
							   'type' => 'custom', 
							   'url' => $url
							  );
			wp_send_json( $response );
		}
	}
	
	/* Check if the received data info about a travel schedule */
	if ( $recieved_data->schedule ) {
		$location = sanitize_text_field( $recieved_data->schedule->location );
		$latlng = nwm_check_latlng( $recieved_data->schedule->latlng );
		$travel_dates = nwm_check_travel_dates( $recieved_data->schedule );
		
		/* Check if there is an previous custom entry we need to delete */
		$delete_result = nwm_check_custom_delete( $recieved_data, $nwm_id );

		/* Update the location table */
		$result = nwm_update_location_query( $post_id = 0, $thumb_id, $latlng, $location, $nwm_id, $travel_dates, $schedule = 1 );
		
		if ( ( $result === false ) || ( $delete_result === false ) ) {
			wp_send_json_error();
		} else {	
			nwm_delete_transients( $map_id );
			$response = array( 'success' => true, 
							   'type' => 'schedule'
							  );
			wp_send_json( $response );
		}				
	}
		
	die();					
	
}

/* Check if the post id has changed when the location data is updated, if so we update the option value */
function nwm_check_post_ids ( $excerpt_data, $map_id ) {

	if ( $excerpt_data->excerpt->post_id != $excerpt_data->excerpt->last_id ) {
		$option_values = get_option( 'nwm_post_ids' );
		$exp_option_values = explode( ',', $option_values[$map_id] );
		$match_found = false;
		
		/* If there is a matching last id, we replace it with the new id */
		foreach ( $exp_option_values as $k => $post_id ) {
			if ( $post_id == $excerpt_data->excerpt->last_id ) {
				$exp_option_values[$k] = $excerpt_data->excerpt->post_id;
				$option_values[$map_id] = implode( ",", $exp_option_values );
				$match_found = true;
				break;
			}
		}
		
		/* If no existing value matches with the last_id, we just add the new post id as a new value */
		if ( !$match_found ) {
			$option_values[$map_id] = implode( ",", $exp_option_values );
			$option_values[$map_id] = $option_values[$map_id].','.$excerpt_data->excerpt->post_id;
		}
		
		update_option( 'nwm_post_ids', $option_values );		
	}
	
}

/* 
Check if the previous entry for this location was a custom one, if so we remove that entry from the custom table.
*/
function nwm_check_custom_delete( $recieved_data, $nwm_id ) {
	
	global $wpdb;
		
	if ( $recieved_data->previous == 'custom' ) {
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->nwm_custom WHERE nwm_id = %d", $nwm_id ) );
		return $result;		   
	}

}

/* Update the option field for the route order and the used wp post ids */
function nwm_update_order() {
		
	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_sort' );
		
	$map_id = absint( $_POST['map_id'] );	
	$location_data = array( 'post_id' => $_POST['post_ids'], 
						    'route_order' => $_POST['route_order']
						   );
	
	/* Loop over the location data array and make sure that there is no , at the end. If so we remove it. */
	foreach ( $location_data as $key => $value ) {
		$lastchar = substr( $value, -1 );	
	
		if ( $lastchar == ',' ) {		
			$trimmed_value = rtrim( $value, ',' );
			$location_data[$key] = $trimmed_value ;
		} else {
			$location_data[$key] = $value;
		}
	}
	
	/* Update the list of used post ids for the selected map */
	if ( nwm_check_route_ids( $location_data['post_id'] ) ) {
		$nwm_post_ids = get_option( 'nwm_post_ids' );
		$nwm_post_ids[$map_id] = $location_data['post_id'];
		
		update_option( 'nwm_post_ids', $nwm_post_ids );
	}
	
	/* Update the route order for the selected map */
	if ( nwm_check_route_ids( $location_data['route_order'] ) ) {
		$nwm_route_order = get_option( 'nwm_route_order' );	
		$nwm_route_order[$map_id] = $location_data['route_order'];
		
		update_option( 'nwm_route_order', $nwm_route_order );
	}
	
	nwm_delete_transients( $map_id );
		
	die();
	
}

/* Update the location data */
function nwm_update_location_query( $post_id, $thumb_id, $latlng, $location, $nwm_id, $travel_dates, $schedule ) {
	
	global $wpdb;

	$result = $wpdb->query( 
					$wpdb->prepare( "UPDATE $wpdb->nwm_routes 
									 SET post_id = %d, thumb_id = %d, schedule = %d, lat = %s, lng = %s, location = %s, arrival = %s, departure = %s WHERE nwm_id = %d",
									 $post_id, 
									 $thumb_id, 
									 $schedule, 
									 $latlng[0], 
									 $latlng[1], 
									 $location, 
									 $travel_dates['arrival'], 
									 $travel_dates['departure'], 
									 $nwm_id
								   )
					);	
								
	return $result;
	
}

/* Load the custom content for the currently edited location */
function nwm_load_content() {
	
	global $wpdb;
	
	$nwm_id = absint( $_POST['nwm_id'] );
		
	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_load_'.$nwm_id );
						
	$result = $wpdb->get_results( $wpdb->prepare( "SELECT content, url, title FROM $wpdb->nwm_custom WHERE nwm_id = %d", $nwm_id, OBJECT ) );	
				
	if ( $wpdb->num_rows ) {
		$response = array( 'success' => true, 
						   'content' => esc_textarea( $result[0]->content ),
						   'url' => esc_url( $result[0]->url, array( 'http', 'https' ) ),
						   'title' => sanitize_text_field( $result[0]->title )
						  );
		wp_send_json( $response );						  
	} else {
		wp_send_json_error();
	}
		
}

/* 
Check if the new meta value matches with the saved data, if so we update it, else we create a new entry 
from: http://wp.smashingmagazine.com/2011/10/04/create-custom-post-meta-boxes-wordpress/
*/
function nwm_change_meta_data( $post_id, $nwm_post_meta_value, $meta_key ) {
					
	$meta_value = get_post_meta( $post_id, $meta_key, true );

	/* If a new meta value was added and there was no previous value, add it. */
	if ( $nwm_post_meta_value && '' == $meta_value )
		add_post_meta( $post_id, $meta_key, $nwm_post_meta_value, true );

	/* If the new meta value does not match the old value, update it. */
	elseif ( $nwm_post_meta_value && $nwm_post_meta_value != $meta_value )
		update_post_meta( $post_id, $meta_key, $nwm_post_meta_value );

	/* If there is no new meta value but an old value exists, delete it. */
	elseif ( '' == $nwm_post_meta_value && $meta_value )
		delete_post_meta( $post_id, $meta_key, $meta_value );	
}

/* Validate the supplied travel dates */
function nwm_check_travel_dates( $recieved_data ) {
	
	$response = array( 'arrival' => '',
					   'departure' => ''
					 );
	
	if ( $recieved_data->arrival ) {
		$arrival_date = nwm_check_date( $recieved_data->arrival );
		$departure_date = true;
		
		if ( $recieved_data->departure ) {
			$departure_date = nwm_check_date( $recieved_data->departure );
		}
		
		if ( ( !$arrival_date ) || ( !$departure_date ) ) {
			$response = array( 'arrival' => $arrival_date, 
							   'departure' => $departure_date
							 );
			wp_send_json_error( $response );				 
		} else {
			$response = array( 'arrival' => $recieved_data->arrival,
							   'departure' => $recieved_data->departure
							 );
		}
	}	
	
	return $response;					  
	
}

/* Save the location data */
function nwm_insert_location( $post_id, $thumb_id, $latlng, $location, $travel_dates, $schedule ) {
	
	global $wpdb; 

	$result = $wpdb->query( 
			  		$wpdb->prepare ( 
							"
							INSERT INTO $wpdb->nwm_routes
							(post_id, thumb_id, schedule, lat, lng, location, arrival, departure)
							VALUES (%d, %d, %d, %s, %s, %s, %s, %s)
							", 
							$post_id,
							$thumb_id,
							$schedule,
							$latlng[0],
							$latlng[1],
							$location,
							$travel_dates['arrival'],
							$travel_dates['departure']
						)
			  );	

	if ( $result === false ) {
		wp_send_json_error();
	} else {	
		return $wpdb->insert_id;		
	}
	
}

/* Try to find a blog post that matches with the provided title */
function nwm_find_post_title() {
	
	global $wpdb;	
	
	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_search' );
							
	$result = $wpdb->get_results( 
			  		$wpdb->prepare(
							"
							SELECT id, post_title 
							FROM $wpdb->posts
							WHERE post_status = 'publish' 
							AND post_type = 'post'
							AND post_title = %s
							", 
							stripslashes( $_POST['post_title'] )
					 ), OBJECT
			   );	  
		
	if ( $result === false ) {
		wp_send_json_error();
	} else {	
		$post_thumbnail_id = get_post_thumbnail_id( $result[0]->id );
		$permalink = get_permalink( $result[0]->id );
		$thumb = wp_get_attachment_image_src( $post_thumbnail_id );
		$response = array( 'post' => 
						array( 'id' => $result[0]->id, 
							   'permalink' => $permalink,
							   'thumb_id' => $post_thumbnail_id,
							   'thumb' => $thumb[0]
						), 
					);
				
		wp_send_json( $response );	
	}
	
}

/* Load the route data for the selected map */
function nwm_load_map() {
	
	$nwm_map_id = absint( $_POST['map_id'] );
		
	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_map_list' );
	
	$map_data = nwm_map_editor_data( $nwm_map_id );
	
	if ( !$map_data ) {
		wp_send_json_error();
	} else {	
		$response = array( 'success' => true, 
						   'data' => nwm_build_tr_list( $map_data ), 
						 );
		wp_send_json( $response );
	}
	
}

/* Collect the map data */
function nwm_map_editor_data( $nwm_map_id ) {
	
	global $wpdb;

	$nwm_settings = get_option( 'nwm_settings' );
	$nwm_route_order = get_option( 'nwm_route_order' );
	$route_data = $wpdb->get_results( 
						$wpdb->prepare( "SELECT nwm_id, post_id, thumb_id, schedule, lat, lng, location, arrival, departure 
										 FROM $wpdb->nwm_routes 
										 WHERE nwm_id IN ( $nwm_route_order[$nwm_map_id] ) 
										 ORDER BY field(nwm_id, $nwm_route_order[$nwm_map_id])", 
										 $nwm_map_id 
									   ) 
						 );
		
	foreach ( $route_data as $k => $route_stop ) {	
		if ( !$route_stop->post_id ) {
			$custom_data = $wpdb->get_results( "SELECT url FROM $wpdb->nwm_custom WHERE nwm_id = $route_stop->nwm_id" );
			$post_id = 0;
			$url = '';
			
			if ( count( $custom_data ) ) {
				$url = $custom_data[0]->url;
			}

		} else {
			$post_id = $route_stop->post_id;
			$url = get_permalink( $route_stop->post_id );
		}
			
		if ( $route_stop->thumb_id ) {
			$thumb_url = wp_get_attachment_image_src( $route_stop->thumb_id );
		} else {
			$thumb_url = '';
		}
		
		$post_data = array( 'nwm_id' => $route_stop->nwm_id,
						    'post_id' => $post_id,
							'thumb_id' => $route_stop->thumb_id,
						    'schedule' => $route_stop->schedule,
						    'url' => $url,
							'thumb_url' => $thumb_url[0],
						    'location' => $route_stop->location,
						    'arrival' => $route_stop->arrival,
						    'arrival_formated' => nwm_date_format( $route_stop->arrival ),
						    'departure' => $route_stop->departure,
						    'departure_formated' => nwm_date_format( $route_stop->departure )
						   );
		
		$collected_destinations[] = array( 'lat' => $route_stop->lat,
										   'lng' => $route_stop->lng,
										   'data' => $post_data
								  	     );	
	}	
	
	return $collected_destinations;
	
}

/* Show the FAQ content */
function nwm_faq() {
	?>
    <div class="wrap">
        <h2><?php _e( 'FAQ', 'nwm' ); ?></h2>
        <div id="nwm-faq">
            <dl>
                <dt><?php _e( 'How do I show the map on my page?', 'nwm') ; ?></dt>
                <dd><?php _e( 'Add this shortcode <code>[nwm_map]</code> to the page where you want to show the map.', 'nwm' ); ?></dd>
            </dl>
            <dl>   
                <dt><?php _e( 'Can I specify the dimensions of the map?', 'nwm' ); ?></dt>
                <dd><?php _e( 'Yes, just add the width and height as an attribute to the shortcode. For example <code>[nwm_map height="500" width="500"]</code>.' , 'nwm' ); ?></dd>
            </dl>
            <dl>   
                <dt><?php _e( 'How do I specify which map is shown?', 'nwm' ); ?></dt>
                <dd><?php _e( 'You can add the id attribute to the <code>[nwm_map]</code> shortcode. This will show the map with ID 3 on your page. <code>[nwm_map id="3"]</code>. <br> The map ID can be found on the "Manage Maps" page. If no ID is set it will show the default map with ID 1.' , 'nwm' ); ?></dd>
            </dl>
            <dl>   
                <dt><?php _e( 'Can I show a list of all the destination on the map?', 'nwm' ); ?></dt>
                <dd><?php _e( 'Yes, this shortcode <code>[nwm_list id="1"]</code> will show the destination list for the map with id 1. If no ID is set, it will default to 1. <br><br> Other shortcode options for the list: <br><br> <code>[nwm_list id="1" dates="all"]</code> Shows both the arrival and departure dates <br> <code>[nwm_list id="1" dates="arrival"]</code> Only show the arrival dates <br> <code>[nwm_list id="1" dates="departure"]</code> Only show the departure dates' , 'nwm' ); ?></dd>
            </dl>   
            <dl>   
                <dt><?php _e( 'When I search for a blog post title it returns no results?', 'nwm' ); ?></dt>
                <dd><?php _e( 'Make sure the blog post you search for is published, and that the search input matches exactly with the title you see in the blog post editor. Otherwise please open a support request in the <a href="http://wordpress.org/support/plugin/nomad-world-map">support form</a>.' , 'nwm' ); ?></dd>
            </dl>              
            <dl>
                <dt><?php _e( 'Where can I suggest new features?', 'nwm' ) ; ?></dt>
                <dd><?php _e( 'You can suggest new features <a href="http://nomadworldmap.uservoice.com/">here</a>, or vote for existing suggestions from others.', 'nwm' ); ?></dd>
            </dl>
        </div>
    </div>    
    <?php
}

/* Delete all map transients */
function nwm_delete_all_transients() {

	$option_values = get_option( 'nwm_post_ids' );

	foreach ( $option_values as $map_id => $post_sids )	{
		nwm_delete_transients( $map_id );
	}	
	
}

/* Delete all transients used by the plugin */
function nwm_delete_transients( $map_id ) {
	delete_transient( 'nwm_locations_'.$map_id );
	delete_transient( 'nwm_route_list_'.$map_id );	
}

/* Make sure the text is limited to x amount of words */
function limit_words( $string, $word_limit ) {
    $words = explode( " ",$string );
    return implode( " ", array_splice( $words, 0, $word_limit ) );
}

/* Validate the date format */
function nwm_check_date( $date ) {
	
	$date = date( 'Y-m-d', $date );
	
	if ( !$date ) {
		return false;
	} else {
		return true;
	}
	
}

/* Change the date format into month, day, year */
function nwm_date_format( $route_date ) {
				
	if ( $route_date != '0000-00-00 00:00:00' ) {
		$date = new DateTime( $route_date );
		return $date->format( 'F d, Y' );
	}
		
}

/* Check if there are only digits with possibly a "," behind them in the data */
function nwm_check_route_ids( $nwm_route_data ) {
	return preg_match( '/^\d+(,\d+)*$/' , $nwm_route_data );
}

/* 
Check if the latlng data is in the correct format 

NOTE: In rare cases 0,0 is set as the value for the hidden input field that holds the latlng value in the editor.
This will obviously place the marker in the wrong location (in the ocean near Gabon).
I have no idea where the 0,0 comes from, I can't imagine the Maps API returning that value, 
but untill I find out where it comes from, the extra check will have to prevent it from being saved.
*/
function nwm_check_latlng( $latlng ) {
	
	$latlng_exp = explode( ",", $latlng );
	
	if ( !is_numeric( $latlng_exp[0] ) || ( !is_numeric( $latlng_exp[1] )  || ( $latlng == '0,0' ) ) )  {
		$response = array( 'success' => false, 
						   'msg' => 'Invalid coordinates, please set the city / country again.'
						  );
		wp_send_json_error( $response );	
	} else {	
		return $latlng_exp;
	}
	
}

/* 
When a post is saved, check if the post_id is used on the map.
If so we delete the transient, this forces the cache to be rebuild and makes sure we show the correct post data.
*/
function nwm_check_used_id( $post_id ) {
	
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;
	
	if ( 'post' == $_POST['post_type'] ) {
		if ( !current_user_can( 'edit_post', $post_id ) )
			return;
				
		if ( $nwm_post_ids = get_option( 'nwm_post_ids' ) ) {			
			foreach ( $nwm_post_ids as $map_id => $used_post_ids )	{
				$used_post_ids = explode( ',', $used_post_ids );
				
				if ( in_array( $post_id, $used_post_ids ) ) {
					nwm_delete_transients( $map_id );
				}
			}
		} 
	}
	
}

/* 
When a post is deleted, we check if the post_id exists in the options fields for the plugin. 
If so we remove them and delete the transient.
*/
function nwm_sync_db( $post_id ) {
	
	global $wpdb;
	
	$post_id = wp_is_post_revision( $post_id );
	
	if ( $nwm_post_ids = get_option( 'nwm_post_ids' ) ) {
		foreach ( $nwm_post_ids as $map_id => $used_post_ids )	{
			$used_post_ids = explode( ',', $used_post_ids );
			
			/* If there is a match, then remove the post_id from the nwm_post_ids option list */
			if ( in_array( $post_id, $used_post_ids ) ) {
				nwm_remove_option_value( 'nwm_post_ids', $map_id, $post_id ); 
				nwm_delete_transients( $map_id );
			}
		}
	}

} 

/* Either show an error or update message */
function nwm_show_msg( $msg, $type ) {
	
	if ( $type == 'error' ) {
		return '<div id="error" class="error below-h2"><p>' . $msg . '</p></div>';
	} else {
		return '<div id="message" class="message updated"><p>' . $msg . '</p></div>';
	}
	
}

function nwm_load_textdomain() {
	load_plugin_textdomain( 'nwm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function nwm_admin_scripts() {	

	wp_enqueue_media();
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script( 'jquery-ui-datepicker' );
	wp_enqueue_script( 'jquery-ui-dialog' );
	wp_enqueue_style( 'jquery-style', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/themes/smoothness/jquery-ui.css' );
	wp_enqueue_script( 'json2' );
	wp_enqueue_style( 'nwm-admin-css', plugins_url( '/css/style.css', __FILE__ ), false );
	wp_enqueue_script( 'nwm-gmap', ( "http://maps.google.com/maps/api/js?sensor=false" ), false );
	wp_enqueue_script( 'nwm-admin-js', plugins_url( '/js/nwm-admin.js', __FILE__ ), array('jquery', 'wp-color-picker'), false );
	wp_enqueue_script( 'jquery-queue', plugins_url( '/js/ajax-queue.js', __FILE__ ), array('jquery'), false );
	
	$nwm_marker = array( 'path' => NWM_URL. 'img/marker.png' );
    wp_localize_script( 'nwm-admin-js', 'nwmMarker', $nwm_marker );
		
}