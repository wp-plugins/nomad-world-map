jQuery(document).ready(function($) {
	
if($('#nomad-world-map').length) {
	var zoomControlPosition, zoomControlStyle,
		flightPath = [],
		futureFlightPath = [],
		loadedImageList = [],
		placeholder = nwmSettings.path + 'img/spacer.gif',
		streetViewVisible = (nwmSettings.streetView == 1) ? true : false,
		newMarker = new google.maps.MarkerImage(nwmSettings.path + '/img/marker.png',
			new google.maps.Size(30,30),
			new google.maps.Point(0,0),
			new google.maps.Point(8,8)
		);	
	
	if ( nwmSettings.controlPosition == 'right' ) {
		zoomControlPosition = google.maps.ControlPosition.RIGHT_TOP
	} else {
		zoomControlPosition = google.maps.ControlPosition.LEFT_TOP
	}
	
	if ( nwmSettings.controlStyle == 'small' ) {
		zoomControlStyle = google.maps.ZoomControlStyle.SMALL
	} else {
		zoomControlStyle = google.maps.ZoomControlStyle.LARGE
	}
		  
	$('#nomad-world-map').gmap3({
		map:{
			options:{
			  center: [33.867489, 151.206985],
			  scrollwheel: false,
			  mapTypeControl: false,
			  navigationControl: false,
			  panControl: false,
			  zoom: 2,
			  streetViewControl: streetViewVisible,
			  zoomControlOptions: {
					style: zoomControlStyle,
					position: zoomControlPosition
				}
			},
			callback: function(map) {
				if ( map.getBounds() ) {
					generateMarkers( $(this), map );
				} else {
					$(this).gmap3({
						map:{
							onces:{
								bounds_changed: function( map ){
									generateMarkers( $(this), map );
								}
							}
						}
					});
				}
			},
		},
	});
}

function generateMarkers( $this, map, bounds ){
	var futureLocation,
		futurePathCount = 0,
		i = 0;

	for ( var key in nwmDestinations ) {
		futureLocation = false;
		
		if ( nwmDestinations.hasOwnProperty( key ) ) {
			if ( ( nwmDestinations[i].data.future ) || ( futurePathCount > 0 ) ) {	

				if ( futurePathCount == 0 ) {
					if( i > 1 ) {
						futureFlightPath.push( [nwmDestinations[i-1].lat, nwmDestinations[i-1].lng] );
					}
				}
			
				if ( i == 1 ) {
					flightPath.push( [nwmDestinations[i].lat, nwmDestinations[i].lng] );
				}
					
				futureFlightPath.push( [nwmDestinations[i].lat, nwmDestinations[i].lng] );	
				futureLocation = true;
				futurePathCount++;
			} else {
				flightPath.push( [nwmDestinations[i].lat, nwmDestinations[i].lng] );
			}
			
			addDestination( $this, i, nwmDestinations[i], futureLocation );
		}
		i++;
	}
	
	if ( nwmSettings.lines == 1 ) {
		if ( flightPath.length ) {
			$this.gmap3({ 
				polyline:{
					options:{
					  strokeColor: nwmSettings.pastLineColor,
					  strokeOpacity: 1.0,
					  strokeWeight: 2,
					  path: flightPath
					}
				}
			});
		}
		
		if ( futureFlightPath.length ) {
			$this.gmap3({ 
				polyline:{
					options:{
					  strokeColor: nwmSettings.futureLineColor,
					  strokeOpacity: 1.0,
					  strokeWeight: 2,
					  path: futureFlightPath
					}
				}
			});
		}
	}
}
   
function addDestination( $this, i, destination, futureLocation ) {
	$this.gmap3({ 
		marker:{
			latLng: [destination.lat, destination.lng],
			options: {
				icon: newMarker,
			},
			events:{
			click: function( marker ){
					$(this).gmap3({clear:"overlay"})
				},
			},
			callback: function( marker ) {
				var circleClass, thumb, title, $destination;

				if ( nwmSettings.thumbCircles == 1 ) {
					circleClass = 'nwm-circle';	
				} else {
					circleClass = '';	
				}
				
				if ( destination.data.thumb ) {
					thumb = '<img class="nwm-thumb ' + circleClass + '" data-src="' + destination.data.thumb + '" src="' + placeholder + '" width="64" height="64" />';
				} else {
					thumb = '<div><span class="nwm-thumb ' + circleClass + '" /></span></div>';
				}
				
				/* Check which title structure we should use */
				title = checkHeaderFormat( destination.data.url, destination.data.title, destination.data.location, futureLocation );

				if ( destination.data.arrival ) {
					if ( destination.data.departure ) {	
					$destination = '<li data-id="' + destination.data.nwm_id + '">' + thumb + '<h2>' + title + '</h2><p class="nwm-travel-schedule"><span>' + destination.data.arrival + '</span><span> - ' + destination.data.departure + '</span></p><p>'+ destination.data.content + '</p></li>';
					} else {
						$destination = '<li data-id="' + destination.data.nwm_id + '">' + thumb + '<h2>' + title + '</h2><p class="nwm-travel-schedule"><span>' + destination.data.arrival + '</span></p><p>'+ destination.data.content + '</p></li>';
					}
				} else {
					$destination = '<li data-id="' + destination.data.nwm_id + '">' + thumb + '<h2>' + title + '<span>' + destination.data.date + '</span></h2><p>'+ destination.data.content + '</p></li>';
				}
														
				$("#nwm-destination-list ul").append( $destination );
				
				/* On mouseover we move the map to the corresponding location */
				$("#nwm-destination-list li").eq(i).bind( 'mouseover', function( ) {
					$this.gmap3("get").panTo(marker.position);
					
					if ( !$(".marker-" + i + "").length ) {
						$this.gmap3(
						  {clear:"overlay"},
							 {
								overlay:{  /* Show the overlay with the location name at the marker location */
									latLng: marker.position,
									options:{
									  content: "<div class='marker-style marker-" + i + "'>" + destination.data.location + "</div>",
									  offset: {
										x:11,
										y:-15
									  }
									}
								},
							});
						}
				});
				
				/* 
				Check which marker we need to set active on page load, either the first / last one, 
				or the one just before the future route starts
				*/				
				if ( i == nwmSettings.activeMarker ) {
					$('#nwm-destination-list li:eq("' + nwmSettings.activeMarker + '")').addClass('nwm-active-destination').mouseover();
					
					/* If the active destination contains a span, it means it's a custom / future date and we don't need to load any thumbnails */
					if ( !$('#nwm-destination-list .nwm-active-destination span.nwm-thumb').length ) {
						imageLoader();
					}
				}
			},
			events:{
			  mouseover: function( marker ) {
				$("#nwm-destination-list li").removeClass(); 
				$("#nwm-destination-list li").eq(i).addClass('nwm-active-destination');
				$('#nwm-destination-list .nwm-active-destination img').attr('src', placeholder);

				if ( !$('#nwm-destination-list .nwm-active-destination span.nwm-thumb').length ) {
					imageLoader();
				}
				
				if ( destination.data.arrival ) {
					var spanData =  '<span style="display:block;">' + destination.data.arrival + ' - ' + destination.data.departure + '</span>';
				} else {
					var spanData =  '<span style="display:block;">' + destination.data.date + '</span>';
				}
				
				$(this).gmap3(
				  {clear:"overlay"},
					{
					  overlay:{
						latLng: marker.getPosition(),
						options:{
						  content:  "<div class='marker-style marker-" + i + "'>" + destination.data.location + "</div>",
						  offset: {
							x:11,
							y:-15
						  }
						}
					  }				  
				});
			  },
			},
		}
	});	
}	

/* Load the required image for the route location */
function imageLoader() {
	var li = $('#nwm-destination-list .nwm-active-destination'),
		img = li.find('.nwm-thumb'),
		imgSrc = li.find('.nwm-thumb').data('src'),
		id = li.data('id'),
		preloader = '<img class="nwm-preloader" id="nwm-preload-img-' + id + '" src="' + nwmSettings.path + 'admin/img/ajax-loader.gif" />';
	
	/* 
	Check if we have loaded the thumbnail before, 
	if not then we make an ajax request todo so and show a preloader. Otherwise we just change the src attr.
	*/
	if ( $.inArray( id, loadedImageList ) === -1 ) {
		$('#nwm-destination-list .nwm-active-destination').append(preloader);
		$.ajax({
			url: imgSrc,
			cache: true,
			statusCode: {
				404: function() {
					alert("Image not found");
					$("#nwm-preload-img-" + id).remove();
				}
			}
		}).done( function( ) {
			/*
			Remove the preloaders, set the correct src attribute and push the value to 
			the array where we keep track of the already loaded thumbs 
			 */
			$("#nwm-preload-img-" + id ).remove();
			img.attr('src', imgSrc);
			loadedImageList.push( id );
		});	
	} else {
		img.attr('src', imgSrc);
	}

}

function checkHeaderFormat( markerUrl, markerTitle, destination, futureLocation ) {
	var title;
	
	if ( markerUrl ) {
		title = '<a href="' + markerUrl + '">' + markerTitle + '</a>';
	} else if ( futureLocation ) {
		title = destination;	
	} else {
		title = markerTitle;
	}	
	
	return title;
}
			
$(".nwm-forward").on( 'click', function () {
	var currentDestination = $(".nwm-active-destination");	
	
	if ( currentDestination.next().length ){
		currentDestination.removeClass()
						  .next()
						  .addClass('nwm-active-destination')
						  .mouseover();
	} else {
		currentDestination.removeClass('nwm-active-destination');
		$('#nwm-destination-list li:first-child').addClass('nwm-active-destination')
												 .mouseover();									 
	}
	
	if ( !$('#nwm-destination-list .nwm-active-destination span.nwm-thumb').length ) {
		imageLoader();
	}		
});

$(".nwm-back").on( 'click', function() {
	var currentDestination = $(".nwm-active-destination");
	
	if ( currentDestination.prev().length ){
		currentDestination.removeClass()
						  .prev()
						  .addClass('nwm-active-destination')
						  .mouseover();
	} else {
		currentDestination.removeClass('nwm-active-destination');
		$('#nwm-destination-list li:last-child').addClass('nwm-active-destination')
												.mouseover();
	}
	
	if ( !$('#nwm-destination-list .nwm-active-destination span.nwm-thumb').length ) {
		imageLoader();
	}	
});	

$(document).keydown( function( eventObject ) {
     if ( eventObject.which == 37 ) {
		$(".nwm-back").trigger( 'click' );
     } else if ( eventObject.which == 39 ) {
		$(".nwm-forward").trigger( 'click' ); 
     }
});
		 
});