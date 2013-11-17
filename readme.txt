=== Nomad World Map ===
Contributors: tijmensmit
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=NFZ6NCFKXQ8EA
Tags: google maps, route, travel, travel blog, trip, geocoding
Requires at least: 3.5
Tested up to: 3.7.1
Stable tag: 1.1.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Create your own custom travel map. Link locations on the map to blog posts and share your travel plans.

== Description ==

For each location that is added to the map you can set the type of content you want to show. Either the excerpt of a blog post, a short custom description or only the travel dates.

The location content itself is shown in a carousel underneath the map. When you slide through the carousel the map will automatically zoom to the location that is linked to the visible content.


= Features include: =

* Create multiple independent maps
* Show a list of visited locations with optional arrival and departure dates
* Set custom thumbnails for each location
* Set custom line colors for the past and future travel routes
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

= How do I specify which map is shown? =

You can add the id attribute to the `[nwm_map id="3"]` shortcode. This will show the map with ID 3 on your page.

= What other shortcode options exist for individual maps? =

You can set the zoom level like this. `[nwm_map id="3" zoom="6"]`
You can set the content type to either 'tooltip' or 'slider'. `[nwm_map id="3" content="tooltip"]` If it's set to tooltip, it will remove the slider.

If the zoom level or the content type are not set in the shortcode, the values from the general settings page will be used.

= Can I show a list of all the destination on the map? =

Yes, this short code `[nwm_list id="1"]` will show the destination list for the map with id 1. If no id is set, it will default to 1.

Other shortcode options for the list:

`[nwm_list id="1" dates="all"]` Show both the arrival and departure dates
`[nwm_list id="1" dates="arrival"]` Only show the arrival dates
`[nwm_list id="1" dates="departure"]` Only show the arrival dates

= When I search for a blog post title it returns no results? =

Make sure the blog post you search for is published, and that it matches exactly with the title you see in the blog post editor.
Otherwise please open a support request in the support form.

= Where can I suggest new features? =

You can suggest new features [here](http://nomadworldmap.uservoice.com/), or vote for existing suggestions from others

== Screenshots ==

1. Front-end of the plugin
2. Settings screen
3. The route editor


== Changelog ==

= 1.1.4 =
* Fixed the map not showing any content when the "On pageload zoom to" setting is set to "The last location before your scheduled route starts", but no previous location exists
* Fixed the thumbnail not being correctly updated on the map after it was changed in the linked post
* Fixed the cache not being deleted if a shortcode attribute was changed in a page that contained the nwm_map shortcode
* Added the option to remove the slider, and show the location content in the tooltip instead
* Added the option to add a "read more" link after the content
* Added the option to show the location name in the tooltip / slider content
* Added support for two new shortcode attributes. You can for individual maps set the zoom level, or define if the content is shown in a tooltip or slider.
* Added HTTPS support for Google Maps
* Added support for searching through pages and custom post types, instead of only blog post

= 1.1.3 =
* Fixed custom thumbnails not showing up for travel dates
* Fixed a bug where the location thumbnail was shown in a square instead of a circle on the map
* Fixed the title of the custom content not being displayed correctly in the slider under the map
* Fixed a notification message sometimes appearing twice in the route editor
* Changed the css for the generated route list

= 1.1.2 =
* Fixed changes to the settings not always clearing the map cache correctly. This only happened if the map contained just custom content and travel dates.

= 1.1.1 =
* Fixed the script that is used to delete the map cache. This script triggered a php warning during install.

= 1.1 =
* Added support for multiple independent maps
* Added a new short code that will show a list of all destinations [nwm_list]
* Added the option to set custom thumbnails for each destination
* Added the option to set different map types (roadmap, terrain, satellite, hybrid)
* Added the option to show either straight or curved lines between the markers on the map
* Modified the used date format on the front-end, it will now use the date format that is set in WordPress (general settings).
* Improved the removal of multiple route items
* Fixed the blog title search breaking with strange characters

= 1.0.4 =
* Fixed the handling of custom travel dates for php versions lower than 5.3
* Changed the shortcode output so that the map can also be placed between content, and doesn't always ends up on top of the content

= 1.0.3 =
* Fixed a situation where an invalid zoom level value would break the entire map

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