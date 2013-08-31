<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'nwm_map', 'nwm_show_full_map' );

/* show all routes on the map */
function nwm_show_full_map( $atts, $content = null ) {
	
	global $wpdb;
	
	extract( shortcode_atts( array (
	  "width" => '',
	  "height" => '',
	), $atts ) ); 
			
	/* Check if there is an existing transient we can use */
	if ( false === ( $frontend_data = get_transient( 'nwm_locations' ) ) ) {	
		$settings = get_option( 'nwm_settings' );
		$nwm_route_order = get_option( 'nwm_route_order' );
		$date_format = 'M j, Y';
		$i = 0;	
		$json_data = '';
		$zoom_to = '';
		
		$nwm_location_data = $wpdb->get_results("
												SELECT nwm_id, post_id, lat, lng, location, arrival, departure
												FROM $wpdb->nwm_routes
												ORDER BY field(nwm_id, $nwm_route_order)
												"
											    );	
																				
		foreach ( $nwm_location_data as $k => $nwm_location ) {	
			$future = '';
		
			/* If the date is in the future, then we need to change the line color on the map */		
			if ( strtotime( $nwm_location->arrival ) > time() ) {
				$future = true;
				
				/* Filter out the first arrival date that is set in the future */
				if ( empty( $first_future_date ) ) {
					$first_future_date = $nwm_location->arrival;
				}
				
				/* See if we need to zoom to the first item of the future route */
				if ( $settings['zoom_to'] == 'schedule_start' ) {
					if ( empty( $future_index ) ) {
						$future_index = $i - 1;
						$zoom_to = $nwm_location->lat.','.$nwm_location->lng;	
					}
				}
			}
			
			if ( ( $settings['zoom_to'] == 'first' ) && ( $i == 0 ) ) {
				$zoom_to = $nwm_location->lat.','.$nwm_location->lng;	
			}
		
			if ( !$nwm_location->post_id ) {
				$nwm_custom_data = $wpdb->get_results(" SELECT content, url, title FROM $wpdb->nwm_custom WHERE nwm_id = $nwm_location->nwm_id ");
				$custom_content = '';	
				$custom_url = '';
				$custom_title = '';	
				
				if ( count( $nwm_custom_data ) ) {
					$custom_content = esc_html( $nwm_custom_data[0]->content );
					$custom_url = esc_url( $nwm_custom_data[0]->url );
					$custom_title = esc_html( $nwm_custom_data[0]->title );
				}

				$post_data = array( 'nwm_id' => (int) $nwm_location->nwm_id,
								    'post_id' => 0,
								    'content' => $custom_content,
								    'title' => $custom_title,
								    'url' => $custom_url,
								    'location' => esc_html( $nwm_location->location ),
								    'thumb' => '',
								    'date' => '',
								    'arrival' => esc_html( nwm_convert_date_format( $date_format, $nwm_location->arrival ) ),
								    'departure' => esc_html( nwm_convert_date_format( $date_format, $nwm_location->departure ) ),
								    'future' => esc_html( $future )
								   );			   
								   
			} else {
				$publish_date = get_the_time( $date_format, $nwm_location->post_id );
				$post_data = nwm_collect_post_data( $nwm_location, $publish_date, $future, $date_format );
			}

			$data = array( 'lat' => $nwm_location->lat,
						   'lng' => $nwm_location->lng,
						   'data' => $post_data
						  );	
						
			$json_data .= json_encode( $data ).',' ;
			
			if ( $settings['zoom_to'] == 'last' ) {
				$zoom_to = $nwm_location->lat.','.$nwm_location->lng;	
			}
			
			$i++;
		} // end foreach
		
		if ( empty( $future_index ) ) {
			if( $settings['zoom_to'] == 'first' ) {
				$future_index = 0;
			} else {
				$future_index = $i - 1;
			}
		}
	
		$settings['future_index'] = $future_index; 
		$settings['zoom_to'] = $zoom_to; 
		$frontend_data = array( 'location_data' => rtrim( $json_data, "," ), 
					  		    'settings' => $settings
					 		   );
		
		/* Calculate the duration of the transient lifetime  */
		if ( !empty( $first_future_date ) ) {
			$current_epoch = time();
			$dt = new DateTime("@$current_epoch");
			$current_converted = $dt->format('Y-m-d'); 
			$arrival_epoch = strtotime( $first_future_date );
			$remaining_time = abs( $current_epoch - $arrival_epoch );
					
			if ( $remaining_time > 0 ) {
				$transient_lifetime = $remaining_time;
			}
		}
						 
		set_transient( 'nwm_locations', $frontend_data, $transient_lifetime ); 
	}

	/* Load the required front-end scripts and set the js data */
	nwm_frontend_scripts( $frontend_data );
		
	?>
    <!-- Nomad World Map - http://nomadworldmap.com -->
	<div class="nwm-wrap" <?php if ( ( int ) $width ) { echo 'style="width:'.$width.'px"'; } ?>>
		<div id="nomad-world-map" <?php if ( ( int ) $height ) { echo 'style="height:'.$height.'px"'; } ?>></div>
		<div id="nwm-destination-list">
			<div class="nwm-back nwm-control"></div>
			<ul></ul>
			<div class="nwm-forward nwm-control"></div>
		</div>   
	</div>	
	<?php
}

/* Collect the excerpt, thumbnail and permalink that belongs to the $post_id */
function nwm_collect_post_data( $nwm_location, $publish_date, $future, $date_format ) {	
	
	$thumb = wp_get_attachment_image_src( get_post_thumbnail_id( $nwm_location->post_id ), 'thumbnail' );
	$excerpt = nwm_get_post_excerpt( $nwm_location->post_id );
	$permalink = get_permalink( $nwm_location->post_id );
	$title = get_the_title( $nwm_location->post_id );
	
	$nwm_post_data = array( 'nwm_id' => ( int ) $nwm_location->nwm_id,
							'thumb' => esc_url( $thumb[0] ),
							'url' => esc_url( $permalink ),
						    'content' => esc_html( $excerpt ),
						    'title' => esc_html( $title ),
						    'location' => esc_html( $nwm_location->location ),
						    'date' => esc_html( $publish_date ),
						    'arrival' => esc_html( nwm_convert_date_format( $date_format, $nwm_location->arrival ) ),
						    'departure' => esc_html( nwm_convert_date_format( $date_format, $nwm_location->departure ) ),
						    'future' => esc_html( $future )
						  );
	
	return $nwm_post_data;
	
}

/* Change the date format from example 2013-06-28 00:00:00 into M j, Y */
function nwm_convert_date_format( $date_format, $route_date ) {
	if ( $route_date != '0000-00-00 00:00:00' ) {
		$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $route_date );
		return $date->format( $date_format );
	}
}

/* 
Get the post excerpt outside of the loop 
from http://www.uplifted.net/programming/wordpress-get-the-excerpt-automatically-using-the-post-id-outside-of-the-loop/
*/
function nwm_get_post_excerpt ( $post_id ) {

	$the_post = get_post( $post_id );
	$the_excerpt = $the_post->post_content;
	$excerpt_length = 25;
	$the_excerpt = strip_tags( strip_shortcodes( $the_excerpt ) );
	$words = explode( ' ', $the_excerpt, $excerpt_length + 1 );
	
	if( count ( $words ) > $excerpt_length ) :
		array_pop( $words );
		array_push( $words, '…' );
		$the_excerpt = implode( ' ', $words ) ;
	endif;
		$the_excerpt = $the_excerpt;
	return $the_excerpt;	
	
}

/* Load the front-end scripts and localize the required js data */
function nwm_frontend_scripts( $frontend_data ) {
	
	wp_enqueue_style( 'nwm', NWM_URL.'css/styles.css', false );
	wp_enqueue_script( 'nwm-gmap', ( "http://maps.google.com/maps/api/js?sensor=false" ),'' ,'' ,true );
	wp_enqueue_script( 'nwm-gmap3', NWM_URL.'js/gmap3.min.js', array('jquery') ); 	/* the not minified version of gmap3 library is in the js folder -> gmap3.js */
	wp_enqueue_script( 'nwm-gmap-markers', NWM_URL.'js/nwm-gmap3.js' );

	$params = array(
		'lines' => $frontend_data['settings']['flightpath'],
		'thumbCircles' => $frontend_data['settings']['round_thumbs'],
		'zoomLevel' => $frontend_data['settings']['zoom_level'],
		'zoomTo' => $frontend_data['settings']['zoom_to'],
		'pastLineColor' => $frontend_data['settings']['past_color'],
		'futureLineColor' => $frontend_data['settings']['future_color'],
		'path' => NWM_URL,
		'streetView' => $frontend_data['settings']['streetview'],
		'controlPosition' => $frontend_data['settings']['control_position'],
		'controlStyle' => $frontend_data['settings']['control_style'],
		'activeMarker' => $frontend_data['settings']['future_index'],
		'l10n_print_after' => 'nwmDestinations = ' . '[' . $frontend_data['location_data'] . ']'
	);
	 
	wp_localize_script( 'nwm-gmap-markers', 'nwmSettings', $params );

}

?>