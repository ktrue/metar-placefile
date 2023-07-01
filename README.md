# metar-placefile
## GRLevelX placefile generator for METAR data from aviationweather.gov

## Purpose:

This script set gets METAR data from aviationweather.gov and formats a placefile for GRLevelX software
to display icons for weather conditions/sky conditions, wind barbs for wind direction/speed, and
mouse-over popups with text for the current METAR report from the station.

Two scripts are to run via cron to gather the data routinely (*get-metar-metadata.php*, *get-aviation-metars.php*).  

The *metar-placefile.php* script is to be accessed by including the website URL in the GRLevelX placefile manager window.

## Scripts:

### *get-metar-metadata.php*

This script reads the **stations.txt** from aviationweather.gov and merges optional updates from
**new_station_data.txt** (a comma delimited CSV file) to produce *metar-metadata-inc.php* which
is used by the *get-aviation-metars.php* program for all the descriptive info about a METAR site.

It should be run daily by cron .. the source file doesn't change very often.

### *get-aviation-metars.php*

This script reads the **metar.cache.csv** from aviationweather.gov and creates the 
aviation-metars-data-inc.php file which contains the parsed and formatted weather
data for each reporting METAR station. 

This program requires the following files:
  *metar-cond-iconcodes-inc.php* (for weather code to iconnumber lookup)
  *metar-metadata-inc.php* (for details about the METAR ICAO produced by *get-metar-metadata.php*)

It should be run by cron every 5 or 10 minutes to keep the data current.  Keep in mind that
many METAR sites report only once per hour so loading more often won't result in 'new' data.

### *metar-placefile.php*

This script generates a GRLevelX placefile from the aviation-metars-data-inc.php 
file on demand by a GRLevel3 instance.  It will return METAR icons with popup info and wind barb
for each METAR within 300 miles of the current radar selected in GRLevel3.
It requires the following files:

   *metar-cond-iconcodes-inc.php* (for weather code to iconnumber lookup)
   *aviation-metars-data-inc.php* (produced by *get-aviation-metars.php* for the current METAR data)
   

The script uses 2 icon files:  *windbarbs_75_new.png*, *cloudcover_new.png*

If you run the script for debugging in a browser, add `?dpi=96&lat={latitude}&lon={longitude}` to
the *metar-placefile.php* URL so it knows what to select for display.

### *metar-cond-iconcodes-inc.php*

This file contains the `pick_cond_icon()` function and lookup tables to determine the iconnumber to
display from the *cloudcover_new.png* file.  It is included in the *get-aviation-metars.php* and
*metar-placefile.php*.  The *get-aviation-metars.php* output will report any code not found (with the raw METAR)
to enable adding any missing codes.

Additional documentation is in each script for further modification convenience.

## Installation

Put the following files in a directory under the document root of your website.  (We used 'placefiles' in the examples below)

  *get-metar-metadata.php*
  *get-aviation-metars.php*
  *metar-placefile.php*
  *metar-cond-iconcodes-inc.php*
  *cloudcover_new.png*
  *windbarbs_75_new.png*
  
Set up cron to run *get-metar-metadata.php* like:
```
1 1 * * * cd $HOME/public_html/placefiles;php -q get-metar-metadata.php > metadata-status.txt
```
be sure to change the public_html/placefiles to the directory where you installed the scripts.

Run the script once (to generate data) by `https://your.website.com/placefiles/get-metar-metadat.php`

Set up cron to run *get-aviation-metars.php* like:

```
*/10 * * * * cd $HOME/public_html/placefiles;php -q get-aviation-metars.php > metar-status.txt
```
be sure to change the public_html/placefiles to the directory where you installed the scripts.

Run the script once (to generate data) by `https://your.website.com/placefiles/get-aviation-metars.php`

Then you can test the metar-placefile script by using your browser to go to
`https://your.website.com/placefiles/metar-placefile.php?dpi=96&lat=37.0&lon=-122.0`

If that returns a placefile, then add your placefile URL into the GRLevelX placefile
manager window

## Acknowledgement

Special thanks to Mike Davis, W1ARN of the National Weather Service, Nashville TN office
for the *windbarbs_75_new.png* and *cloudcover_new.png* icon sheets,
the METAR weather conditions to icon code mapping, 
preliminary placefile output example, 
and for his testing/feedback during development.   


