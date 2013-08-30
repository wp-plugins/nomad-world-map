<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'nwm_create_admin_menu');
add_action( 'admin_init', 'nwm_init' );
add_action( 'wp_ajax_save_location', 'nwm_save_location' );
add_action( 'wp_ajax_delete_location', 'nwm_delete_location' );
add_action( 'wp_ajax_update_location', 'nwm_update_location' );
add_action( 'wp_ajax_update_order', 'nwm_update_order' );
add_action( 'wp_ajax_load_content', 'nwm_load_content' );
add_action( 'wp_ajax_find_post_title', 'nwm_find_post_title' );
add_action( 'save_post', 'nwm_check_used_id' );
add_filter( 'wp_loaded', 'nwm_load_textdomain' );

function nwm_create_admin_menu() {
	add_menu_page( 'Nomad Map', 'Nomad Map', 'manage_options', 'nwm_map_editor', 'nwm_map_editor' );
	add_submenu_page( 'nwm_map_editor', 'Map', 'Map', 'manage_options', 'nwm_map_editor', 'nwm_map_editor' );
	add_submenu_page( 'nwm_map_editor', 'Settings', 'Settings', 'manage_options', 'nwm_settings', 'nwm_settings_page' );	
	add_submenu_page( 'nwm_map_editor', 'FAQ', 'FAQ', 'manage_options', 'nwm_faq', 'nwm_faq' );	
}

function nwm_init() {
	register_setting( 'nwm_settings', 'nwm_settings', 'nwm_settings_check' );
	
	if ( current_user_can( 'delete_posts' ) ) {
		 add_action( 'delete_post', 'nwm_sync_db' );
	}
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
		$nwm_route_order = get_option( 'nwm_route_order' );	
		
		if ( !$nwm_route_order ) {
			$nwm_route_order = $last_id;
		} else {
			$nwm_route_order = $nwm_route_order.','.$last_id;
		}
		
		update_option( 'nwm_route_order', $nwm_route_order );	
		$last_post_id = ( int ) $recieved_data->id;
		
		if ( $last_post_id ) {
			$nwm_post_ids = get_option( 'nwm_post_ids' );
			
			if ( !$nwm_post_ids ) {
				$nwm_post_ids = $last_post_id;
			} else {
				$nwm_post_ids = $nwm_post_ids.','.$last_post_id;
			}
						
			update_option( 'nwm_post_ids', $nwm_post_ids );	
		}
		
		$response = array( 'success' => true,
						   'id' => $last_id, 
						   'delete_nonce' => wp_create_nonce( 'nwm_nonce_delete_'.$last_id ), 
						   'update_nonce' => wp_create_nonce( 'nwm_nonce_update_'.$last_id ),
						   'load_nonce' => wp_create_nonce( 'nwm_nonce_load_'.$last_id )
						  );
		
		delete_transient( 'nwm_locations' );
		wp_send_json( $response );
	}
		
	die();
	
}

/* Save the new location including the post excerpt text and thumbnail that belongs to the post id */
function nwm_save_location_excerpt( $recieved_data ) {
	
	if ( $recieved_data->schedule ) {
		$post_id = 0;
		$schedule = 1;
		$recieved_data = $recieved_data->schedule;
	} else {
		$post_id = absint( $recieved_data->id );
		$schedule = 0;
		$recieved_data = $recieved_data->excerpt;
	}
	
	$location = sanitize_text_field( $recieved_data->location );
	$latlng = nwm_check_latlng( $recieved_data->latlng );
	$travel_dates = nwm_check_travel_dates( $recieved_data );
	$last_id = nwm_insert_location( $post_id, $latlng, $location, $travel_dates, $schedule );
	
	return $last_id;	
		
}

/*
Save the new location with just the custom text, latlng and location name. 
No post excerpt or thumbnail exists here 
*/
function nwm_save_location_custom( $recieved_data ) {
	
	global $wpdb;
	
	$location = sanitize_text_field( $recieved_data->custom->location );
	$marker_title = sanitize_text_field( $recieved_data->custom->title );
	$marker_content = limit_words( sanitize_text_field( $recieved_data->custom->content ), 25 );
	$marker_url = esc_url_raw( $recieved_data->custom->url, array( 'http', 'https' ) );
	$latlng = nwm_check_latlng( $recieved_data->custom->latlng );
	$travel_dates = nwm_check_travel_dates( $recieved_data->custom );
	
	/*
	0 indicates a custom locations, and will tell us to look for the data in the 
	nwm_custom table instead of trying to get the post excerpt and thumbnail
	*/
	$last_id = nwm_insert_location( $post_id = 0, $latlng, $location, $travel_dates, $schedule = 0 );
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
	$nwm_post_id = absint( $_POST['post_id'] );

	if ( !current_user_can( 'manage_options' ) )
		die( '-1' );
	check_ajax_referer( 'nwm_nonce_delete_'.$nwm_id );
				
	$result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->nwm_routes WHERE nwm_id = %d", $nwm_id ) );
	
	/* If the post id is false, there must also be custom content to delete from the nwm_custom table */
	if ( !$nwm_post_id  ) {
		 $custom_result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->nwm_custom WHERE nwm_id = %d", $nwm_id ) );
		
		if ( $custom_result === false ) {
			wp_send_json_error();
		}				
	}
							
	if ( $result === false ) {
		wp_send_json_error();
	} else {	
		delete_transient( 'nwm_locations' );	
		
		/* Get the current route order and check if the id from the deleted stop exists, if so remove it and update the current route order */
		$nwm_route_order = get_option( 'nwm_route_order' );	
		$nwm_route_order = explode( ',', $nwm_route_order );
		
		foreach( $nwm_route_order as $k => $id ) {
			if( $id == $nwm_id ) {
				unset( $nwm_route_order[$k] );
				break;
			}
		}

		$nwm_route_order = implode( ",", $nwm_route_order );
		update_option( 'nwm_route_order', $nwm_route_order );

		/* Get the list of current used post_ids and check if the id from the deleted stop exists, if so remove it and update the post_id list */
		$nwm_post_ids = get_option( 'nwm_post_ids' );	
		$nwm_post_ids  = explode( ',', $nwm_post_ids );
		
		foreach ( $nwm_post_ids as $k => $id ) {
			if ( $id == $nwm_post_id ) {
				unset( $nwm_post_ids[$k] );
				break;
			}
		}
		
		$nwm_post_ids = implode( ",", $nwm_post_ids );
		update_option( 'nwm_post_ids', $nwm_post_ids );
		
		wp_send_json_success();
	}		
	
}

/* Update the location data */
function nwm_update_location() {
	
	global $wpdb;
	
	$recieved_data = json_decode( stripslashes( $_POST['last_update'] ) );
	$nwm_id = absint( $recieved_data->id );

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
			
			/* Check if this entry used to be a custom entry, if so we need to delete the data from the custom table */
			$delete_result = nwm_check_custom_delete( $recieved_data, $nwm_id );			
			
			/* Update the location table */
			$result = nwm_update_location_query( $post_id, $latlng, $location, $nwm_id, $travel_dates, $schedule = 0 );
			
			if ( ( $result === false ) || ( $delete_result === false ) ) {	
				wp_send_json_error();
			} else {	
				delete_transient( 'nwm_locations' );
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
		$location_result = nwm_update_location_query( $post_id = 0, $latlng, $location, $nwm_id , $travel_dates, $schedule = 0 );
									
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
			delete_transient( 'nwm_locations' );
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
		$result = nwm_update_location_query( $post_id = 0, $latlng, $location, $nwm_id, $travel_dates, $schedule = 1 );
		
		if ( ( $result === false ) || ( $delete_result === false ) ) {
			wp_send_json_error();
		} else {	
			delete_transient( 'nwm_locations' );
			$response = array( 'success' => true, 
							   'type' => 'schedule'
							  );
			wp_send_json( $response );
		}				
	}
		
	die();					
	
}

/* 
Check if the previous entry for this location was a custom one, if so we remove the enry from the custom table.
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
	
	/* Check if the post id's and routs id's collection only contains numbers and ',' if so we update both values */
	if ( nwm_check_route_ids( $location_data['post_id'] ) ) {
		update_option( 'nwm_post_ids', $location_data['post_id'] );
	}
	
	if ( nwm_check_route_ids( $location_data['route_order'] ) ) {
		update_option( 'nwm_route_order', $location_data['route_order'] );
	}
	
	delete_transient( 'nwm_locations' );
		
	die();
	
}

/* Update the location data */
function nwm_update_location_query( $post_id, $latlng, $location, $nwm_id, $travel_dates, $schedule ) {
	
	global $wpdb;

	$result = $wpdb->query( 
					$wpdb->prepare( 
							"
							UPDATE $wpdb->nwm_routes 
							SET post_id = %d, 
								schedule = %d,
								lat = %s, 
								lng = %s,
								location = %s,
								arrival = %s,
								departure = %s
							WHERE nwm_id = %d 
							",
							$post_id,
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

/* Load the custom content for the location that is edited */
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
function nwm_insert_location( $post_id, $latlng, $location, $travel_dates, $schedule ) {
	
	global $wpdb; 

	$result = $wpdb->query( 
			  		$wpdb->prepare ( 
							"
							INSERT INTO $wpdb->nwm_routes
							(post_id, schedule, lat, lng, location, arrival, departure)
							VALUES (%d, %d, %s, %s, %s, %s, %s)
							", 
							$post_id,
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

/* Try to find a post that matches with the provided title */
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
							$_POST['post_title']
					 ), OBJECT
			   );	  
	
	if ( $result === false ) {
		wp_send_json_error();
	} else {	
		$permalink = get_permalink( $result[0]->id );
		$response = array( 'post' => 
						array( 'id' => $result[0]->id, 
							   'permalink' => $permalink
						), 
					);
				
		wp_send_json( $response );	
	}
	
}

/* Build the edit page with the map and show a list of the existing routes */
function nwm_map_editor() {
	
	global $wpdb;
	
	$i = 1;
	$nwm_settings = get_option( 'nwm_settings' );
	$nwm_route_order = get_option( 'nwm_route_order' );
	$route_data = $wpdb->get_results( "SELECT nwm_id, post_id, schedule, lat, lng, location, arrival, departure FROM $wpdb->nwm_routes ORDER BY field(nwm_id, $nwm_route_order) ");
	
	foreach( $route_data as $k => $route_stop ) {	
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
		
		$post_data = array( 'nwm_id' => $route_stop->nwm_id,
						    'post_id' => $post_id,
						    'schedule' => $route_stop->schedule,
						    'url' => $url,
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
								   
	?>
    <div id="nwm-wrap" class="wrap">
        <h2>Nomad World Map</h2>
		
        <div class="nwn-new-destination-wrap">
           <ul id="nwm-menu">
                <li class="nwm-active-item"><a href="#nwm-add-destination"><?php _e( 'Add location', 'nwm' ); ?></a></li>
                <li><a href="#nwm-edit-destination"><?php _e( 'Edit location', 'nwm' ); ?></a></li>                    
            </ul>
                
            <div id="nwm-destination-wrap" class="destination postbox"> 
                <div id="nwm-add-destination" class="nwm-tab nwm-active inside" data-nonce-save="<?php echo wp_create_nonce('nwm_nonce_save'); ?>">
                    <form id="nwm-form">
                        <p><label for="nwm-searched-location"><?php _e( 'City / Country:', 'nwm' ); ?></label> 
                           <input id="nwm-searched-location" class="textinput" type="text" name="nwm-searched-location" value="" />
                           <input id="find-nwm-location" class="button-primary" type="button" name="text" value="Set" />
                           <input id="nwm-latlng" type="hidden" name="nwm-latlng" value="" />
                           <em class="nwm-desc"><?php _e( 'You can drag the marker to a specific location', 'nwm' ); ?></em>
                        </p>
                        
                        <div id="nwm-marker-content">
                            <p class="nwm-marker-wrap"><label for="nwm-marker-content-option"><?php _e( 'Location content:', 'nwm' ); ?></label> 
                               <select id="nwm-marker-content-option">
                                    <option selected="selected" value="nwm-blog-excerpt"><?php _e( 'Blog post excerpt', 'nwm' ); ?></option> 
                                    <option value="nwm-custom-text"><?php _e( 'Custom content', 'nwm' ); ?></option> 
                                    <option value="nwm-travel-schedule"><?php _e( 'Travel schedule', 'nwm' ); ?></option> 
                               </select>
                            </p>
                            <div id="nwm-blog-excerpt" class="nwm-blog-title nwm-marker-option">
                            	<label for="nwm-post-title"><?php _e( 'Title of the blog post you want to link to:', 'nwm' ); ?></label> 
                                <input id="nwm-post-title" type="text" class="textinput"> <input id="find-nwm-title" class="button-primary" type="button" name="text" value="Search" />
                            	<div id="nwm-search-link"><?php _e( 'Linked post: ', 'nwm' ); ?><span></span></div>
                                 <input id="nwm-search-nonce" type="hidden" value="<?php echo wp_create_nonce('nwm_nonce_search'); ?>"  />
                            </div>
                            
                            <div id="nwm-custom-text" class="nwm-marker-option nwm-hide">
                                <p><label for="nwm-custom-title"><?php _e( 'Title:', 'nwm' ); ?></label><input id="nwm-custom-title" type="text" class="textinput"></p>
                                <p><label for="nwm-custom-url"><?php _e( 'Link:', 'nwm' ); ?></label><input id="nwm-custom-url" type="url" placeholder="http://" class="textinput"></p>
                            	<p class="nwm-textarea-wrap">
                                	<label for="nwm-custom-desc"><?php _e( 'Description:', 'nwm' ); ?></label>
                                	<textarea id="nwm-custom-desc" data-length="25" cols="5" rows="5"></textarea>
                                    <em id="char-limit" class="nwm-desc"><?php _e( 'Keep it short, 25 words remaining.', 'nwm' ); ?></em>
                                </p>
                            </div>
                        </div>
                        
                        <div class="nwm-dates">
                            <p><strong><?php _e( 'Travel dates', 'nwm' ); ?></strong></p>
                            <div>
                                <label for="nwm-from-date"><?php _e( 'Arrival:', 'nwm' ); ?></label>
                                <input type="text" placeholder="optional" id="nwm-from-date" />
                                <input type="hidden" name="from_date" />
                            </div>
                            <div>
                                <label for="nwm-till-date"><?php _e( 'Departure:', 'nwm' ); ?></label>
                                <input type="text" placeholder="optional" id="nwm-till-date" />
                                <input type="hidden" name="till_date" />
                            </div>
                        </div>
                        <p class="nwm-date-desc"><em class="nwm-desc"><?php _e( 'If no dates are set, then the publish date of the linked blog post is shown as the travel date.', 'nwm' ); ?></em></p>
                        <p><input id="nwm-add-trip" type="submit" name="nwm-add-trip" class="button-primary" value="Save" /></p>
                        <input id="nwm-post-id" type="hidden" name="nwm-post-id" value="" />
                        <input id="nwm-post-type" type="hidden" name="nwm-post-type" value="" />
                    </form>      
                </div>   
                <div id="nwm-edit-destination" class="nwm-tab inside">
                  <p>
                  	<select id="nwm-edit-list">
                     	<option selected="selected"><?php _e( '- Select destination to edit -', 'nwm' ); ?></option>
                        <?php 
						$x = 1;
						if ( $collected_destinations ) {
							foreach ( $collected_destinations as $k => $nwm_destination ) {
								echo '<option value ="' . esc_attr( $nwm_destination['data']['nwm_id'] ) . '"> ' . $x . ' - ' . esc_html( $nwm_destination['data']['location'] ) . '</option>';
								$x++;
							}
						}
						?>	
                     </select>
                  </p>
               </div>
            	        
        	</div>
            <div class="gmap-wrap">
            	<div id="gmap-nwm"></div>
            </div>
            <div id="nwm-preload-img" class="nwm-hide"><img class="nwm-preloader" alt="preloader" src="<?php echo plugins_url( '/img/ajax-loader.gif', __FILE__ ); ?>"/></div>
    	</div>
        
        <div class="nwn-current-destinations-wrap postbox">            
        	<table id="nwm-destination-list" width="100%" border="0" cellspacing="0" data-nonce-sort="<?php echo wp_create_nonce( 'nwm_nonce_sort' ); ?>">
            	<thead>
                    <th scope="col" class="nwm-order"><?php _e( 'Order', 'nwm' ); ?></th>
                    <th scope="col"><?php _e( 'Location', 'nwm' ); ?></th>
                    <th scope="col"><?php _e( 'Url', 'nwm' ); ?></th>
                    <th scope="col"><?php _e( 'Arrival', 'nwm' ); ?></th>
                    <th scope="col"><?php _e( 'Departure', 'nwm' ); ?></th>
                    <th scope="col"></th>
                </thead>
                <tbody>
                <?php
				if ( $collected_destinations ) {
					foreach ( $collected_destinations as $k => $nwm_location ) {
						if ( !$nwm_location['data']['post_id'] ) {
							$nwm_load_nonce = '<input type="hidden" name="load_nonce" value="'. wp_create_nonce( 'nwm_nonce_load_' . $nwm_location['data']['nwm_id'] ) .'" />';
						} else {
							$nwm_load_nonce = '';	
						}
						
						if ( $nwm_location['data']['arrival'] != '0000-00-00 00:00:00' ) {
							$arrival_date = '<input type="hidden" name="arrival_date" value="'. esc_attr( trim( str_replace("00:00:00", '', $nwm_location['data']['arrival'] ) ) ) .'" />';
						} else {
							$arrival_date = '';
						}
						
						if ( $nwm_location['data']['departure'] != '0000-00-00 00:00:00' ) {
							$departure_date = '<input type="hidden" name="departure_date" value="'. esc_attr( trim( str_replace("00:00:00", '', $nwm_location['data']['departure'] ) ) ) .'" />';
						} else {
							$departure_date = '';
						}	
						
						if ( $nwm_location['data']['schedule'] ) {
							$travel_schedule = "data-travel-schedule='1'";
						} else {
							$travel_schedule = '';	
						}
						
						if ( $nwm_location['data']['url'] ) {
							$url = '<a href="'. esc_url( $nwm_location['data']['url'] ) .'" title="'. esc_url( $nwm_location['data']['url'] ) .'">' . esc_url( $nwm_location['data']['url']  ) .'</a>';
						} else {
							$url = '';
						}
												
						echo '<tr '. $travel_schedule .' data-nwm-id="'. esc_attr( $nwm_location['data']['nwm_id'] ) . '" data-latlng="' . esc_attr( $nwm_location['lat'] ) . ',' . esc_attr( $nwm_location['lng'] ) . '" data-post-id="' . esc_attr( $nwm_location['data']['post_id'] ) .'">'."\n"; 	
						echo '<td class="nwm-order"><span>' . $i .'</span></td>'."\n";
						echo '<td class="nwm-location">'. esc_html( $nwm_location['data']['location'] ) .'</td>'."\n";
						echo '<td class="nwm-url">'. $url .'</td>'."\n";
						echo '<td class="nwm-arrival">'. $arrival_date .' <span>'. esc_html( $nwm_location['data']['arrival_formated'] ) .'</span></td>'."\n";
						echo '<td class="nwm-departure">'. $departure_date .' <span>'. esc_html( $nwm_location['data']['departure_formated'] ) .'</span></td>'."\n";						
						echo '<td class="nwm-btn">
								<input class="delete-nwm-destination button" type="button" name="text" value="Delete" /> 
								<input type="hidden" name="delete_nonce" value="'. wp_create_nonce( 'nwm_nonce_delete_'.$nwm_location['data']['nwm_id'] ) .'" />
								<input type="hidden" name="update_nonce" value="'. wp_create_nonce( 'nwm_nonce_update_'.$nwm_location['data']['nwm_id'] ) .'" /> ' 
								.$nwm_load_nonce. 
							'</td>'."\n";
						echo '</tr>'."\n";
						$i++;
					}
				}
				?>
            	</tbody>
            </table>
        </div>
    </div>
    <?php	
	
}


function nwm_settings_page() {
		
	$options = get_option( 'nwm_settings' );
	
	if ( ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == 'true' ) ) {
		?>
		<div id="message" style="width:90%;" class="message updated"><p><strong><?php _e( 'Settings updated', 'nwm' ) ?></strong></p></div>
        <?php
	}

	?>
    <div class="wrap">
        <div id="nwm-wrap">
            <h2><?php _e( 'Nomad World Map Settings', 'nwm' ); ?></h2>
            <form id="nwm-settings-form" action="options.php" method="post">
                <div class="postbox-container">
                    <div class="metabox-holder">
                        <div class="postbox">
                            <div title="Click to toggle" class="handlediv"><br></div>
                            <h3 class="hndle"><span><?php _e( 'General', 'nwm' ); ?></span></h3>
                            <div class="inside">
                                <p>
                                   <label for="nwm-flightpath"><?php _e( 'Draw lines between the markers?', 'nwm' ); ?></label> 
                                   <input id="nwm-flightpath" type="checkbox" name="nwm-flightpath" value="" <?php checked( $options['flightpath'] == '1', true ); ?> />
                                </p>
                                <p>
                                   <label for="nwm-round-thumbs"><?php _e( 'Show the post thumbnails in a circle?', 'nwm' ); ?></label> 
                                   <input id="nwm-round-thumbs" type="checkbox" name="nwm-round-thumbs" value="" <?php checked( $options['round_thumbs'] == '1', true ); ?> />
                                </p>            
                                <p>
                                    <label for="nwm-zoom-to"><?php _e( 'On pageload zoom to:', 'nwm' ); ?></label> 
                                    <?php echo nwm_zoom_to( $options ); ?>
                                </p>
                                <p>
                                    <label for="nwm-zoom-level"><?php _e( 'Zoom level:', 'nwm' ); ?></label> 
                                    <?php echo nwm_zoom_level( $options ); ?>
                                </p>
                                <div class="nwm-marker-lines">
                                    <label for="nwm-past-color"><?php _e( 'Past route color:', 'nwm' ); ?></label> 
                                    <input type="text" name="nwm-past-color" value="<?php echo esc_attr( $options['past_color'] ); ?>" id="nwm-past-color" />
                                </div>
                                <div class="nwm-marker-lines">
                                    <label for="nwm-future-color"><?php _e( 'Future route color:', 'nwm' ); ?></label> 
                                    <input type="text" name="nwm-future-color" value="<?php echo esc_attr( $options['future_color'] ); ?>" id="nwm-future-color" />
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="metabox-holder">
                        <div class="postbox">
                            <div title="Click to toggle" class="handlediv"><br></div>
                            <h3 class="hndle"><span><?php _e( 'Map Controls', 'nwm' ); ?></span></h3>
                            <div class="inside">
                                <p>
                                   <label for="nwm-streetview"><?php _e( 'Show the street view controls?', 'nwm' ); ?></label> 
                                   <input id="nwm-streeview" type="checkbox" name="nwm-streetview" value="" <?php checked( $options['streetview'] == '1', true ); ?> />
                                </p> 
                                <p>
                                    <label><?php _e( 'Position of the map controls', 'nwm' ); ?></label>
                                    <span class="nwm-radioboxes">
                                        <input type="radio" id="nwm-control-left" name="nwm-control-position" <?php checked( 'left', $options['control_position'], true ); ?> value="left" />
                                        <label for="nwm-control-left"><?php _e( 'Left', 'nwm' ); ?></label>
                                        <input type="radio" id="nwm-control-right" name="nwm-control-position" <?php checked( 'right', $options['control_position'], true ); ?> value="right" />
                                        <label for="nwm-control-right"><?php _e( 'Right', 'nwm' ); ?></label>
                                    </span>
                                </p>
                                <p>
                                    <label><?php _e( 'Zoom control style', 'nwm' ); ?></label>
                                    <span class="nwm-radioboxes">
                                        <input type="radio" id="nwm-small-style" name="nwm-control-style" <?php checked( 'small', $options['control_style'], true ); ?> value="small" />
                                        <label for="nwm-small-style"><?php _e( 'Small', 'nwm' ); ?></label>
                                        <input type="radio" id="nwm-large-style" name="nwm-control-style" <?php checked( 'large', $options['control_style'], true ); ?> value="large" />
                                        <label for="nwm-large-style"><?php _e( 'Large', 'nwm' ); ?></label>
                                    </span>
                                </p>
                            </div>        
                        </div>   
                    </div>  
                </div>   
                
                <div class="postbox-container side">
                	<div class="metabox-holder">
                        <div class="postbox">
                            <div title="Click to toggle" class="handlediv"><br></div>
                            <h3 class="hndle"><span><?php _e( 'About', 'nwm' ); ?></span><span style="float:right;">Version <?php echo NWN_VERSION_NUM; ?></span></h3>
                            <div class="inside">
                                <p><strong>Nomad World Map</strong> by <a href="http://twitter.com/tijmensmit">Tijmen Smit</a>.</p>
                                <p>If you like this plugin, please rate it <strong>5 stars</strong> on <a href="http://wordpress.org/plugins/nomad-world-map/">WordPress.org</a>.
                            </div>
                        </div>
                	</div>        
                </div>
 
                <p class="nwm-update-btn"><input id="nwm-add-trip" type="submit" name="nwm-add-trip" class="button-primary" value="<?php _e( 'Update Settings', 'nwm' ); ?>" /></p>
                <?php settings_fields( 'nwm_settings' ); ?>
            </form>
        </div>
    </div>    
    <?php
	
}

/* Show the FAQ content */
function nwm_faq() {
	?>
    <div class="wrap">
        <h2><?php _e( 'FAQ', 'nwm' ); ?></h2>
        <div id="nwm-faq">
            <dl>
                <dt><?php _e( 'How do I add the map to my page?', 'nwm') ; ?></dt>
                <dd><?php _e( 'Add this shortcode <code>[nwm_map]</code> to the page where you want to display the map.', 'nwm' ); ?></dd>
            </dl>
            <dl>   
                <dt><?php _e( 'Can I specify the dimensions of the map?', 'nwm' ); ?></dt>
                <dd><?php _e( 'Yes, just add the width and height as an attribute to the shortcode. For example <code>[nwm_map height="500" width="500"]</code>.' , 'nwm' ); ?></dd>
            </dl>
            <dl>
                <dt><?php _e( 'Where can I suggest new features?', 'nwm' ) ; ?></dt>
                <dd><?php _e( 'You can suggest new features <a href="http://nomadworldmap.uservoice.com/">here</a>, or vote for existing suggestions from others.', 'nwm' ); ?></dd>
            </dl>
        </div>
    </div>    
    <?php
}

/* Process the map settings */
function nwm_settings_check() {
	
	$output = array();
	$zoom_options = array( 'first', 'schedule_start', 'last' );
	
	/* Check if we have a valid zoom-to option, otherwise set it to last */
	if ( in_array( $_POST['nwm-zoom-to'], $zoom_options ) ) {
		$output['zoom_to'] = wp_filter_nohtml_kses( $_POST['nwm-zoom-to'] );
	} else {
		$output['zoom_to'] = 'last';
	}
	
	/* Check if we have a valid zoom level, it has to be between 1 or 12. If not set it to the default of 3 */
	if ( $_POST['nwm-zoom-level'] >= 1 || $_POST['nwm-zoom-level'] <= 12 ) {
		$output['zoom_level'] = $_POST['nwm-zoom-level'];
	} else {
		$output['zoom_level'] = 3;	
	}	

	$output['flightpath'] = isset( $_POST['nwm-flightpath'] ) ? 1 : 0;	
	$output['round_thumbs'] = isset( $_POST['nwm-round-thumbs'] ) ? 1 : 0;	
	$output['past_color'] = sanitize_text_field( $_POST['nwm-past-color'] );
	$output['future_color'] = sanitize_text_field( $_POST['nwm-future-color'] );
	$output['streetview'] = isset( $_POST['nwm-streetview'] ) ? 1 : 0;	
	$output['control_position'] = ( wp_filter_nohtml_kses( $_POST['nwm-control-position']  == 'left') ) ? 'left' : 'right';	
	$output['control_style'] = ( wp_filter_nohtml_kses( $_POST['nwm-control-style'] == 'small' ) ) ? 'small' : 'large';
	
	delete_transient( 'nwm_locations' );
	
	return $output;
	
}

/* Create the dropdown to select which marker is active when the page first loads */
function nwm_zoom_to( $options ) {
	
	$items = array( 'first' => 'The first location (default)', 
				    'schedule_start' => 'The last location before your scheduled route starts',
				    'last' => 'The last location',
				   );
				   
	$dropdown = '<select id="nwm-zoom-to" name="nwm-zoom-to">';
	
	foreach ( $items as $item => $value ) {
		$selected = ( $options['zoom_to'] == $item ) ? 'selected="selected"' : '';
		$dropdown .= "<option value='$item' $selected>$value</option>";
	}
	
	$dropdown .= "</select>";
	
	return $dropdown;
	
}

/* Create the dropdown to select the zoom level */
function nwm_zoom_level( $options ) {
					   
	$dropdown = '<select id="nwm-zoom-level" name="nwm-zoom-level">';
	
	for ( $i = 1; $i < 13; $i++ ) {
        $selected = ( $options['zoom_level'] == $i ) ? 'selected="selected"' : '';
		
		switch ( $i ) {
			case '1':
				$zoom_desc = '- World view';
				break;
			case '3':
				$zoom_desc = '- Default';
				break;
			case '12':
				$zoom_desc = '- Roadmap view';
				break;	
			default:
				$zoom_desc = '';		
		}

		$dropdown .= "<option value='$i' $selected>$i $zoom_desc</option>";	
    }
		
	$dropdown .= "</select>";
		
	return $dropdown;
	
}

/* Make sure the text is limited to x amount of words */
function limit_words( $string, $word_limit ) {
    $words = explode( " ",$string );
    return implode( " ", array_splice( $words, 0, $word_limit ) );
}

/* Validate the date format */
function nwm_check_date( $date ) {
	
	$date = DateTime::createFromFormat( 'Y-m-d', $date );
	$date_errors = DateTime::getLastErrors();
	
	if ( $date_errors['warning_count'] + $date_errors['error_count'] > 0 ) {
		return false;
	} else {
		return true;
	}
	
}

/* Change the date format into month, day, year */
function nwm_date_format( $route_date ) {
	
	if ( $route_date != '0000-00-00 00:00:00' ) {
		$date = date_create_from_format( 'Y-m-d H:i:s', $route_date );
		$formated_date = date_format( $date, 'F d, Y' );	
		
		return $formated_date;
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
If so we delete the transient, this forces the cache to be rebuild with the updated data.
*/
function nwm_check_used_id( $post_id ) {
	
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		return;
	
	if ( 'post' == $_POST['post_type'] ) {
		if ( !current_user_can( 'edit_post', $post_id ) )
			return;
				
		if ( $nwm_post_ids = get_option( 'nwm_post_ids' ) ) {
			$nwm_post_ids = explode( ",", $nwm_post_ids );
	
			if ( in_array( $post_id, $nwm_post_ids ) ) {
				delete_transient( 'nwm_locations' );	
			}
		} 
	}
	
}

/* 
When a post is deleted, we check if the post_id exists in the options fields for the plugin. 
If so we remove them and delete the transient and other route data. 
*/
function nwm_sync_db( $post_id ) {
	
	global $wpdb;
	
	$post_id = wp_is_post_revision( $post_id );
	
	if ( $nwm_post_ids = get_option( 'nwm_post_ids' ) ) {
		$nwm_post_ids = explode( ",", $nwm_post_ids );
	
		/* Check if the post_id exists in the options field for the plugin */
		if ( in_array( $post_id, $nwm_post_ids ) ) {	
				
			/* Remove the post_id from the nwm_post_ids option list */
			foreach ( $nwm_post_ids as $k => $nwm_post_id ) {
				if ( $nwm_post_id == $post_id ) {
					unset( $nwm_post_ids[$k] );
					break;
				}
			}
			
			$nwm_updated_ids = implode( ",", $nwm_post_ids );
			update_option( 'nwm_post_ids', $nwm_updated_ids );
	
			delete_transient( 'nwm_locations' );
		}
	}
	
} 

function nwm_load_textdomain() {
	load_plugin_textdomain( 'nwm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function nwm_admin_scripts() {	

	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script( 'jquery-ui-datepicker' );
	wp_enqueue_style( 'jquery-style', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.10.3/themes/smoothness/jquery-ui.css' );
	wp_enqueue_script( 'json2' );
	wp_enqueue_style( 'nwm-admin-css', plugins_url( '/css/style.css', __FILE__ ), false );
	wp_enqueue_script( 'nwm-gmap', ( "http://maps.google.com/maps/api/js?sensor=false" ), false );
	wp_enqueue_script( 'nwm-admin-js', plugins_url( '/js/nwm-admin.js', __FILE__ ), array('jquery', 'wp-color-picker'), false );
	
}