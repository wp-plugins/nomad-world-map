jQuery(document).ready(function($) {
	
if ( $('#nomad-world-map').length ) {
	var zoomControlPosition, zoomControlStyle, zoomTo, mapType,
		flightPath = [],
		futureFlightPath = [],
		loadedImageList = [],
		zoomLevel = parseInt( nwmSettings.zoomLevel ),
		placeholder = nwmSettings.path + 'img/spacer.gif',
		streetViewVisible = ( nwmSettings.streetView == 1 ) ? true : false,
		newMarker = new google.maps.MarkerImage(nwmSettings.path + '/img/marker.png',
			new google.maps.Size(30,30),
			new google.maps.Point(0,0),
			new google.maps.Point(8,8)
		);	
		
	/* Check if we need to remove the slider, and show the content in the tooltip instead */
	if ( nwmSettings.contentLocation == 'tooltip' ) {
		$(".nwm-wrap").addClass('nwm-no-slider');
	}
	
	/* Set correct postion of the controls */		
	if ( nwmSettings.controlPosition == 'right' ) {
		zoomControlPosition = google.maps.ControlPosition.RIGHT_TOP
	} else {
		zoomControlPosition = google.maps.ControlPosition.LEFT_TOP
	}
	
	/* Set correct control style */	
	if ( nwmSettings.controlStyle == 'small' ) {
		zoomControlStyle = google.maps.ZoomControlStyle.SMALL
	} else {
		zoomControlStyle = google.maps.ZoomControlStyle.LARGE
	}
	
	/* Set the selected map type */
	switch( nwmSettings.mapType) {
		case 'roadmap':
		  mapType = google.maps.MapTypeId.ROADMAP
		  break;
		case 'satellite':
		  mapType = google.maps.MapTypeId.SATELLITE
		  break;
		case 'hybrid':
		  mapType = google.maps.MapTypeId.HYBRID
		  break;
		case 'terrain':
		  mapType = google.maps.MapTypeId.TERRAIN
		  break;		  
		default:
		  mapType = google.maps.MapTypeId.ROADMAP
	}
	
	/* Initialize the map */			
	$('#nomad-world-map').gmap3({
		map:{
			options:{
			  center: [zoomTo],
			  scrollwheel: false,
			  mapTypeControl: false,
			  navigationControl: false,
			  panControl: false,
			  zoom: zoomLevel,
			  mapTypeId: mapType,
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

/* Create the markers */
function generateMarkers( $this, map, bounds ){
	var futureLocation,
		futurePathCount = 0,
		i = 0,
		curvedLines = ( nwmSettings.curvedLines == 1 ) ? true : false;

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
	
	/* Check if we need to draw lines between the markers */
	if ( nwmSettings.lines == 1 ) {
		
		/* Check if we need to draw a line for past locations */
		if ( flightPath.length ) {
			$this.gmap3({ 
				polyline:{
					options:{
					  strokeColor: nwmSettings.pastLineColor,
					  strokeOpacity: 1.0,
					  strokeWeight: 2,
					  geodesic: curvedLines,
					  path: flightPath
					}
				}
			});
		}
		
		/* Check if we need to draw a line for future locations */
		if ( futureFlightPath.length ) {
			$this.gmap3({ 
				polyline:{
					options:{
					  strokeColor: nwmSettings.futureLineColor,
					  strokeOpacity: 1.0,
					  strokeWeight: 2,
					  geodesic: curvedLines,
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
				var markerData = {};
				
				if ( !$(".nwm-wrap").hasClass('nwm-no-slider') ) {	
					var destinationHtml,
						content = destination.data.content;
					
					markerData = getMarkerData( destination, nwmSettings, 'slider' );
					content = content + markerData.readMore;
						
					if ( destination.data.arrival ) {
						if ( destination.data.departure ) {	
							destinationHtml = '<li data-id="' + destination.data.nwm_id + '">' + markerData.thumb + '<h2>' + markerData.title + '</h2><p class="nwm-travel-schedule"><span>' + destination.data.arrival + '</span><span> - ' + destination.data.departure + '</span></p><p>' + content + '</p></li>';
						} else {
							destinationHtml = '<li data-id="' + destination.data.nwm_id + '">' + markerData.thumb + '<h2>' + markerData.title + '</h2><p class="nwm-travel-schedule"><span>' + destination.data.arrival + '</span></p><p>' + content + '</p></li>';
						}
					} else {
						destinationHtml = '<li data-id="' + destination.data.nwm_id + '">' + markerData.thumb + '<h2>' + markerData.title + '<span>' + destination.data.date + '</span></h2><p>' + content + '</p></li>';
					}
															
					$("#nwm-destination-list ul").append( destinationHtml );
					
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
					
					/* 
					This fixes a case where the settings are set to focus on the last marker before the future route starts, 
					but no previous entry exist before the future route starts. So instead we just focus on the first marker we find (the first future entry). 
					Not what the user selected, but no other way to fix it?
					 */
					if ( ( nwmSettings.activeMarker == -1 ) && ( i == 0 ) ) {
						$('#nwm-destination-list li:first-child').addClass('nwm-active-destination').mouseover();					
					}
				} else {
					if ( i == nwmSettings.activeMarker ) {
						markerData = getMarkerData( destination, nwmSettings, 'tooltip' );
						markerContent = '<div class="marker-style marker-' + i + '"><div class="nwm-marker-wrap">' + markerData.thumb + '<div class="marker-txt"><h2>' + markerData.title + '</h2><p>' + markerData.date + markerData.readMore + '</p></div></div></div>';
				
						$this.gmap3("get").panTo( marker.position );					
						$this.gmap3(
						  {clear:"overlay"},
							 {
								overlay:{  /* Show the overlay with the location name at the marker location */
									latLng: marker.position,
									options:{
									content: markerContent,
									  offset: {
										x:11,
										y:-15
									  }
									}
								},
							});
					}
				}
			},
			events:{
			  mouseover: function( marker ) {
				var markerContent;
									  
				if ( !$(".nwm-wrap").hasClass('nwm-no-slider') ) {  
					$("#nwm-destination-list li").removeClass(); 
					$("#nwm-destination-list li").eq(i).addClass('nwm-active-destination');
					$('#nwm-destination-list .nwm-active-destination img').attr( 'src', placeholder );
	
					if ( !$('#nwm-destination-list .nwm-active-destination span.nwm-thumb').length ) {
						imageLoader();
					}
										
					markerContent = "<div class='marker-style marker-" + i + "'>" + destination.data.location + "</div>";
				} else {
					markerData = getMarkerData( destination, nwmSettings, 'tooltip' );
					markerContent = '<div class="marker-style marker-' + i + '"><div class="nwm-marker-wrap">' + markerData.thumb + '<div class="marker-txt"><h2>' + markerData.title + '</h2><p>' + markerData.date + markerData.readMore + '</p></div></div></div>';
				}
								
				$(this).gmap3(
				  {clear:"overlay"},
					{
					  overlay:{
						latLng: marker.getPosition(),
						options:{
						  content: markerContent,
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

/* Collect the required marker data */
function getMarkerData( destination, nwmSettings, contentType ) {
	var contentType, 
		markerData = {},
		circleClass = checkCircleClass( nwmSettings.thumbCircles ), 
		thumbHtml = '', 
		titleHtml = '',
		spanDate = '', 
		readMoreHtml = '';
				
	if ( destination.data.arrival ) {
		spanDate = '<span>' + destination.data.arrival + ' - ' + destination.data.departure + '</span>';
	} else {
		spanDate = '<span>' + destination.data.date + '</span>';
	}
	
	/* Check which thumb format to use, and whether we should show the placeholder. */
	if ( contentType == 'tooltip' ) {
		if ( destination.data.thumb != null ) {		
			thumbHtml = '<img class="nwm-thumb nwm-marker-img ' + circleClass + '" src="' + destination.data.thumb + '" width="64" height="64" />';
		} else {
			thumbHtml = '';	
		}
	} else {
		if ( destination.data.thumb != null ) {
			thumbHtml = '<img class="nwm-thumb ' + circleClass + '" data-src="' + destination.data.thumb + '" src="' + placeholder + '" width="64" height="64" />';
		} else {
			thumbHtml = '<div><span class="nwm-thumb ' + circleClass + '" /></span></div>';
		}
	}
	
	titleHtml = checkHeaderFormat( destination.data.url, destination.data.title, destination.data.location );
	
	/* Check if we should show the read more link */
	if ( ( destination.data.url.length > 0 ) && ( nwmSettings.readMore == 1 ) ) {
		readMoreHtml = '<a class="nwm-read-more" href="' + destination.data.url + '">Read more</a>';
	}
	
	/* Check if we should show the location name under the header */
	if ( ( destination.data.url.length > 0 ) && ( nwmSettings.locationHeader == 1 ) ) {
		titleHtml = titleHtml + '<span>' + destination.data.location + '</span>';
	}	
	
	markerData = {
		circleClass: circleClass,
		thumb: thumbHtml,
		date: spanDate,
		readMore: readMoreHtml,
		title: titleHtml
	};
	
	return markerData;
	
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
			the array were we keep track of the already loaded thumbs 
			 */
			$("#nwm-preload-img-" + id ).remove();
			img.attr('src', imgSrc);
			loadedImageList.push( id );
		});	
	} else {
		img.attr('src', imgSrc);
	}

}

function checkHeaderFormat( markerUrl, markerTitle, destination ) {
	var title;
	
	if ( markerUrl ) {
		title = '<a href="' + markerUrl + '">' + markerTitle + '</a>';
	} else {
		if ( markerTitle ) {
			title = markerTitle;
		} else {
			title = destination; 
		}		
	}	
	
	return title;
}

function checkCircleClass( thumbCircles ) {
	var circleClass;
	
	if ( thumbCircles == 1 ) {
		circleClass = 'nwm-circle';	
	} else {
		circleClass = '';	
	}		

	return circleClass;
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

/* Enable keyboard navigation for the slider */
$(document).keydown( function( eventObject ) {
     if ( eventObject.which == 37 ) {
		$(".nwm-back").trigger( 'click' );
     } else if ( eventObject.which == 39 ) {
		$(".nwm-forward").trigger( 'click' ); 
     }
});
		 
});