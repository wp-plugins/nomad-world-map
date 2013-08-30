=== Nomad World Map ===
Contributors: tijmensmit
Tags: google maps, route, travel, travel blog, trip, geocoding
Requires at least: 3.5
Tested up to: 3.6
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Create your own custom travel map. Link locations on the map to blog posts and share your travel plans.

== Description ==

For each location that is added to the map you can set the type of content you want to show. Either the excerpt of a blog post, a short custom description or only the travel dates.

The location content itself is shown in a carousel underneath the map. When you slide through the carousel the map will automatically zoom to the location that is linked to the visible content.


= Features include: =

* Set custom line colors for the past and future travel routes.
* Rearrange the order of the travel route by dragging the items up or down in the route list.
* Specify if you want the map to zoom to the first or last location of your travel route.
* Choose from three different content types for each location. Either link to a blog post, write a custom description or show the travel dates.

[Demo](http://nomadworldmap.com/)

== Installation ==

1. Upload the `nomad-world-map` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Create your route on the map under 'Nomad Map'
1. Add the map to a page with this shortcode: [nwm_map]

== Frequently Asked Questions ==

= How do I add the map to a page? =

Add this shortcode [nwm_map] to the page where you want to display the map.

= Can I specify the dimensions of the map? =

Yes, just add the width and height as an attribute to the shortcode. `[nwm_map height="500" width="500"]`

== Screenshots ==

1. Front-end of the plugin
2. Setting screen
3. The route editor


== Changelog ==

= 1.0.2 =
* Added the option to set a custom zoom level
* Improved the zooming on page load by setting the latlng value of the active location as the center of the map
* Fixed a css bug where in some themes the thumbnails were shown as a square instead of a circle
* Fixed the incorrect display of older dates on the map
* Fixed a situation where an incorrect title could show up for future locations
* Modified the handling of blog post that are deleted and were linked to locations on the map

= 1.0.1 =
* Fixed a interface bug that in some cases resulted in a "setMap is not a function" javascript error when searching for a new location on Google Maps
* Modified the datepicker, removed the dates limits and added a check for keyboard input

= 1.0 =
* Initial release

