<?php
#---------------------------------------------------------------------------
/*
Program: metar-placefile.php

Purpose: generate a GRLevelX placefile to display metar conditions

Usage:   invoke as a placefile in the GrlevelX placefile manager

Requires: decoded metar data in aviation-metars-data-inc.php produced by get-aviation-metars.php
          icon lookup function/codes in metar-cond-iconcodes-inc.php

Author: Ken True - webmaster@saratoga-weather.org

Acknowledgement:
  
   Special thanks to Mike Davis, W1ARN of the National Weather Service, Nashville TN office
   for the windbarbs_75_new.png and cloudcover_new.png icon sheets,
	 the METAR weather conditions to icon code mapping, 
	 preliminary placefile output example, 
	 and for his testing/feedback during development.   

Version 1.00 - 21-Jun-2023 - initial release
Version 1.01 - 29-Jun-2023 - update for condition/sky icon choosing based on $M['codes']
Version 1.02 - 01-Jul-2023 - added icon decode for hail (PL,GR,GS) and snow grains (SG) and metar-cond-iconcodes-inc.php
Version 1.03 - 08-Jul-2023 - switch to using knots for determining wind barb, added gust barb
Version 1.04 - 10-Jul-2023 - display separate gust barb w/value, alert img for heat-index, high wind and low visibility
Version 1.05 - 10-Jul-2023 - add precip (if present) to popup
Version 1.06 - 11-Jul-2023 - added icon display formats per Mike Davis, added wind-chill display
Version 1.07 - 15-Jul-2023 - update from Mike Davis for improved visibility display
*/
#---------------------------------------------------------------------------

#-----------settings--------------------------------------------------------
date_default_timezone_set('UTC');
$timeFormat = "d-M-Y g:ia T";  // time display for date() in popup
#-----------end of settings-------------------------------------------------

$Version = "metar-placefile.php V1.07 - 15-Jul-2023 - webmaster@saratoga-weather.org";
global $Version,$timeFormat;

// self downloader
if (isset($_REQUEST['sce']) && strtolower($_REQUEST['sce']) == 'view' ) {
   //--self downloader --
   $filenameReal = __FILE__;
   $download_size = filesize($filenameReal);
   header('Pragma: public');
   header('Cache-Control: private');
   header('Cache-Control: no-cache, must-revalidate');
   header("Content-type: text/plain");
   header("Accept-Ranges: bytes");
   header("Content-Length: $download_size");
   header('Connection: close');
   
   readfile($filenameReal);
   exit;
}

header('Content-type: text/plain,charset=ISO-8859-1');

if(file_exists("metar-cond-iconcodes-inc.php")) {
	include_once("metar-cond-iconcodes-inc.php");
} else {
	print "Warning: metar-cond-iconcodes-inc.php file not found. Aborting.\n";
	exit;
}
if(file_exists("aviation-metars-data-inc.php")) {
	include_once("aviation-metars-data-inc.php");
} else {
	print "Warning: aviation-metars-data-inc.php file not found. Aborting.\n";
	exit;
}

if(isset($_GET['lat'])) {$latitude = $_GET['lat'];}
if(isset($_GET['lon'])) {$longitude = $_GET['lon'];}
if(isset($_GET['version'])) {$version = $_GET['version'];}

if(isset($latitude) and !is_numeric($latitude)) {
	print "Bad latitude spec.";
	exit;
}
if(isset($latitude) and $latitude >= -90.0 and $latitude <= 90.0) {
	# OK latitude
} else {
	print "Latitude outside range -90.0 to +90.0\n";
	exit;
}

if(isset($longitude) and !is_numeric($longitude)) {
	print "Bad longitude spec.";
	exit;
}
if(isset($longitude) and $longitude >= -180.0 and $longitude <= 180.0) {
	# OK longitude
} else {
	print "Longitude outside range -180.0 to +180.0\n";
	exit;
}	
if(!isset($latitude) or !isset($longitude) or !isset($version)) {
	print "This script only runs via a GRlevelX placefile manager.";
	exit();
}

/*
Sample entry annotated:

  'KAQO' => 
  array (
    'RAW-METAR' => 'KAQO 220215Z AUTO 18008G15KT 5SM +TSRA BR FEW010 BKN030 OVC065 23/21 A2975 RMK AO2 LTG DSNT ALQDS',
    'FIX-METAR' => 'KAQO 220215Z AUTO 18008G15KT 5SM +TSRA BR FEW010 BKN030 OVC065 23/21 A2975 RMK AO2 LTG DSNT ALQDS',
    'STATION' => 'KAQO',
    'OBSTIME' => '2023-06-22T02:15:00 UTC',
    'LATITUDE' => '30.7833',
    'LONGITUDE' => '-98.6667',
    'ELEVATION' => '1096',
    'NAME' => 'Llano, Texas, USA',
    'dwinddir' => 180,
    'dwindgust' => 17,  // MAY NOT BE PRESENT
    'WIND' => 'S at 9 mph (15 km/h), gusting to 17 mph (28 km/h)',
    'dwind' => 9,
    'dvis' => 5,
    'VISIBILITY' => '5 miles (8 km)',
    'CONDITIONS' => 'Heavy Thunderstorm Rain, Mist',
    'codes' => '+TS,RA,BR',
    'CLOUDS' => 'Overcast',
    'CLOUD-DETAILS' => 'Few Clouds 1000 ft	Mostly Cloudy 3000 ft	Overcast 6500 ft	',
    'TEMP' => '73F (23C)',
    'dtemp' => 73,
    'ddewpt' => 70,
    'DEWPT' => '70F (21C)',
    'HUMIDITY' => '89%',
    'dhum' => 89,
    'BAROMETER' => '1007 hPa (29.75 inHg)',
    'daltinhg' => '29.75',
    'dalthpa' => 1007.0,
    'LATITUDE' => '30.7833',
    'LONGITUDE' => '-98.6667',
    'ELEVATION' => '1096',
    'NAME' => 'Llano, Texas, USA',
  ),
	
The following may not be present unless the METAR reports it:

    'dvis' => 5,
    'VISIBILITY' => '5 miles (8 km)',
    'dwindgust' => 17,  // MAY NOT BE PRESENT
    'ddewpt' => 70,
    'DEWPT' => '70F (21C)',
    'HUMIDITY' => '89%',
    'dhum' => 89,

	
The following MAY be present if conditions warrant it:

    'HEATINDEX' => '104F (40C)',
    'dheatidx' => 104,

    'WINDCHILL' => '22F (-6C)',
    'dwindch' => 22,
		
		'PRECIP'  => '0.05 in',

the 'd...' entries are all numeric w/o units , but use F,mph. Both altimeter readings in inHg and hPa are available

*/

gen_header();

foreach ($METARS as $ICAO => $M) {
	
  if(!isset($M['LONGITUDE']) or !isset($M['LONGITUDE'])) {
		#print "; --$ICAO missing LATITUDE and/or LONGITUDE .. ignored.\n";
		continue;
	}
	list($miles,$km,$bearingDeg,$bearingWR) = 
	  GML_distance((float)$latitude, (float)$longitude,(float)$M['LATITUDE'], (float)$M['LONGITUDE']);
	if($miles <= 250) {
		#print "..$ICAO is $miles $bearingWR\n";
		gen_entry($M,$miles,$bearingWR);
	}
}

#---------------------------------------------------------------------------
function gen_header() {
	global $Version;
	$title = "METAR Surface Observations";
	print '; placefile with conditions generated by '.$Version. '
; Generated on '.gmdate('r').'
;
Title: '.$title.' - '.gmdate('r').' 
Refresh: 7
Color: 255 255 255
Font: 1, 12, 1, Arial
;IconFile: 1, 43, 68, 29, 67, windbarbs_75_new.png
;IconFile: 1, 18, 58, 2, 58, windbarbs_kt.png
IconFile: 1, 19, 43, 2, 43, windbarbs-kt-white.png
IconFile: 2, 15, 15, 8, 8, cloudcover_new.png
IconFile: 3, 19, 43, 2, 43, windbarbs-kt-red.png
IconFile: 4, 19, 43, 9, 43, windbarbs-kt-gust.png
Threshold: 999

';
	
}

#---------------------------------------------------------------------------

function gen_entry($M,$miles,$bearingWR) {
/*
  Purpose: generate the detail entry with popup for the METAR report

*/	

  print "; generate ".$M['STATION']." ".$M['NAME']." at ".$M['LATITUDE'].','.$M['LONGITUDE']." at $miles miles $bearingWR \n";
	
  $output = 'Object: '.$M['LATITUDE'].','.$M['LONGITUDE']. "\n";
  $output .= "Threshold: 999\n";
	if(isset($M['dtemp'])) {
    $output .= "Text: -17, 13, 1, ".$M['dtemp']."\n";
	}
  if(isset($M['ddewpt'])) {
    $output .= "Text: -17, -13, 1, ".$M['ddewpt']."\n";
	}
	if(isset($M['dvis'])) {
		$tVis = ($M['dvis'] >= 2.0)?intval($M['dvis']):$M['dvis'];
		if($tVis == 0) {
		$output .= "Color: 250 0 248\n";  
		$output .= "Text: 17, -13, 1, ".$tVis."\n";
		}
		if($tVis <= 1) {
		$output .= "Color: 250 0 248\n";  
		$output .= "Text: 24, -13, 1, ".$tVis."\n";
		}
		if($tVis > 1 && $tVis < 3) {
		$output .= "Color: 247 11 15\n";  
		$output .= "Text: 17, -13, 1, ".$tVis."\n";
		}
		if($tVis >= 3 && $tVis <= 5) {
		$output .= "Color: 255 255 0\n";  
		$output .= "Text: 17, -13, 1, ".$tVis."\n";
		}
		if($tVis > 5) {
		$output .= "Color: 24 189 7\n";  
		$output .= "Text: 17, -13, 1, ".$tVis."\n";
		}
	  $output .= "Color: 255 255 255\n";
	}
  #$output .= "Color: 24 189 7\n";
	if($M['dalthpa'] > 500) {
    $output .= "Text: 28, 13, 1, ".round($M['dalthpa'],0)."\n";
	}

  #$output .= "Color: 24 189 7\n";
  #$output .= "Text: 17, -13, 1, ".$M['STATION']."\n";
	$barbno = pick_wind_icon($M['dwindkts']);
//*
	$barbgust = isset($M['dwindgust'])?pick_gust_icon($M['dwindgust']):0;
	if($barbgust > 0) {
		$tDir = intval(($M['dwinddir']+180) % 360);
		$output .= "Icon: 0,0,".$tDir.",4,".$barbgust."\n";
		list($tX,$tY) = pick_gust_offsets($tDir,50);
		$output .= "Text: $tX, $tY, 1, ".$M['dwindgust']."\n";
	}
//*/
	if($barbno > 0) {
    $output .= "Icon: 0,0,".$M['dwinddir'].",1,".$barbno."\n";
	}
	$icon = pick_cond_icon($M['codes']);
	if($icon < 0) {$icon = 40; } # show missing icon if not found
	# high wind speed overrides
	if($M['dwind'] >= 58) {
		$icon=52;

	} elseif ($M['dwind'] >= 35) {
		$icon=51;
	}
	# overrides for alert issues:
	if((isset($M['dvis']) and $M['dvis'] <= 1.0) or 
	   (isset($M['dheatidx']) and $M['dheatidx'] >= 105) or
		 ($M['dwind'] >= 35) ) {
			 $icon =64; # alert icon
		 }
	if(isset($M['dheatidx'])) {
	  $output .= "Color: 252 78 42\n";
		$output .= "Text: -30, 0, 1, ".$M['dheatidx']."\n";
    $output .= "Color: 255 255 255\n";
	}
	if(isset($M['dwindch'])) {
	  $output .= "Color: 2 145 255\n";
		$output .= "Text: -30, 0, 1, ".$M['dwindch']."\n";
    $output .= "Color: 255 255 255\n";
	}
  $output .= "Icon: 0,0,000,2,$icon,\"".gen_popup($M)."\"\n";
  $output .= "End:\n\n";

  print $output;	
	
}
#---------------------------------------------------------------------------

function pick_wind_icon($speed) {
	# return icon number based on speed in 5mph chunks using https://www.weather.gov/hfo/windbarbinfo
	# as a guide.for windbarbs_75_new.png image
	
	static $barbs = array(2,8,14,20,25,31,37,43,60,66,71,77,83,89,94,100,112,117/*,123*/); #in MPH
	static $barbs = array(2,7,12,17,22,27,32,37,52,47,52,57,62,67,77,82,87,92,97,102); # in KTS
	if($speed > 117) {return(17);}
	for ($i=0;$i<count($barbs);$i++){
	  if($speed <= $barbs[$i]) {break;}
  }

	if($i > 17) {$i = 17; }
	return($i);
	
}
#---------------------------------------------------------------------------

function pick_gust_icon($speed) {
	# return icon number based on gust speed
	# as a guide.for winbarbs-kt-gust.png image
	
	#static $barbs = array(2,8,14,20,25,31,37,43,60,66,71,77,83,89,94,100,112,117/*,123*/); #in MPH
	static $barbs = array(2,20,60,77,180); #in MPH
	#static $barbs = array(2,7,12,17,22,27,32,37,52,47,52,57,62,67,77,82,87,92,97,102); # in KTS
	if($speed > 180) {return(4);}
	for ($i=0;$i<count($barbs);$i++){
	  if($speed <= $barbs[$i]) {break;}
  }

	if($i > 4) {$i = 4; }
	return($i);
	
}

#---------------------------------------------------------------------------
function pick_gust_offsets ($angle,$radius) {
	# pick the offset x, y to place the gust value at the end of the arrow
	
	$theta = $angle*pi()/180;
	$y = intval(cos($theta)*$radius);
	$x = intval(sin($theta)*$radius);
  return(array($x,$y));
}
#---------------------------------------------------------------------------

function gen_popup($M) {
	global $timeFormat;
	# note use '\n' to end each line so GRLevelX will do a new-line in the popup.
	
	$out = $M['NAME'].'\n   ('.$M['LATITUDE'].",".$M['LONGITUDE']." @ ".$M['ELEVATION'].' ft)\n';
	$out .= "----------------------------------------------------------".'\n';
	$out .= $M['RAW-METAR'].'\n';
	$out .= "----------------------------------------------------------".'\n';
	$obsTime = strtotime($M['OBSTIME']);
	$out .= "Time: ".date($timeFormat,$obsTime)." (".gmdate('H:i',$obsTime).'Z)\n';
	$out .= "T:    ".$M['TEMPERATURE'].'\n';
	$out .= "Td:   ".$M['DEWPT'].'\n';
	$out .= "RH:   ".$M['HUMIDITY'].'\n';
	
	if(strpos($M['WIND'],',') !== false) {
		$M['WIND'] = str_replace("gusting",'\n        gusting',$M['WIND']);
	}
	$out .= "Wind: ".$M['WIND'].'\n';
	if(isset($M['VISIBILITY'])) {
		$out .= "Vsby: " .$M['VISIBILITY'].'\n';
	}
	if(isset($M['PRECIP'])) {
		$out .= 'Prcp: '.$M['PRECIP'].'\n';
	}
	if(isset($M['SNOW'])) {
		$out .= 'Snow:   '.$M['SNOW'].'\n';
	}
	$out .= "Cond: ".$M['CONDITIONS'].'\n';
	if(strlen($M['CLOUD-DETAILS']) > 4) {
	  $sky = str_replace("\t",',\n        ',$M['CLOUD-DETAILS']);
	  $out .= "Sky:  ".$sky.'\n';
	}
	if($M['dalthpa'] > 500) {
	  $out .= "Pres: ".$M['BAROMETER'].'\n';
	} else {
	  $out .= "Pres: n/a".'\n';
	}
	$out .= "WX:   ".$M['codes'].'\n';
	if(isset($M['HEATINDEX'])) {
		$out .="Heat Index: ".$M['HEATINDEX'].'\n';
	}
	if(isset($M['WINDCHILL'])) {
		$out .="Wind Chill: ".$M['WINDCHILL'].'\n';
	}
# last line of popup
	$out .= "----------------------------------------------------------";
	$out = str_replace('"',"'",$out);
  return($out);	
}

#---------------------------------------------------------------------------

// ------------ distance calculation function ---------------------
   
    //**************************************
    //     
    // Name: Calculate Distance and Radius u
    //     sing Latitude and Longitude in PHP
    // Description:This function calculates 
    //     the distance between two locations by us
    //     ing latitude and longitude from ZIP code
    //     , postal code or postcode. The result is
    //     available in miles, kilometers or nautic
    //     al miles based on great circle distance 
    //     calculation. 
    // By: ZipCodeWorld
    //
    //This code is copyrighted and has
	// limited warranties.Please see http://
    //     www.Planet-Source-Code.com/vb/scripts/Sh
    //     owCode.asp?txtCodeId=1848&lngWId=8    //for details.    //**************************************
    //     
    /*::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::*/
    /*:: :*/
    /*:: This routine calculates the distance between two points (given the :*/
    /*:: latitude/longitude of those points). It is being used to calculate :*/
    /*:: the distance between two ZIP Codes or Postal Codes using our:*/
    /*:: ZIPCodeWorld(TM) and PostalCodeWorld(TM) products. :*/
    /*:: :*/
    /*:: Definitions::*/
    /*::South latitudes are negative, east longitudes are positive:*/
    /*:: :*/
    /*:: Passed to function::*/
    /*::lat1, lon1 = Latitude and Longitude of point 1 (in decimal degrees) :*/
    /*::lat2, lon2 = Latitude and Longitude of point 2 (in decimal degrees) :*/
    /*::unit = the unit you desire for results:*/
    /*::where: 'M' is statute miles:*/
    /*:: 'K' is kilometers (default):*/
    /*:: 'N' is nautical miles :*/
    /*:: United States ZIP Code/ Canadian Postal Code databases with latitude & :*/
    /*:: longitude are available at http://www.zipcodeworld.com :*/
    /*:: :*/
    /*:: For enquiries, please contact sales@zipcodeworld.com:*/
    /*:: :*/
    /*:: Official Web site: http://www.zipcodeworld.com :*/
    /*:: :*/
    /*:: Hexa Software Development Center © All Rights Reserved 2004:*/
    /*:: :*/
    /*::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::*/
  function GML_distance($lat1, $lon1, $lat2, $lon2) { 
    $theta = $lon1 - $lon2; 
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta)); 
    $dist = acos($dist); 
    $dist = rad2deg($dist); 
    $miles = $dist * 60 * 1.1515;
//    $unit = strtoupper($unit);
	$bearingDeg = fmod((rad2deg(atan2(sin(deg2rad($lon2) - deg2rad($lon1)) * 
	   cos(deg2rad($lat2)), cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - 
	   sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lon2) - deg2rad($lon1)))) + 360), 360);

	$bearingWR = GML_direction($bearingDeg);
	
    $km = round($miles * 1.609344); 
    $kts = round($miles * 0.8684);
	$miles = round($miles);
	return(array($miles,$km,$bearingDeg,$bearingWR));
  }

#---------------------------------------------------------------------------

function GML_direction($degrees) {
   // figure out a text value for compass direction
   // Given the direction, return the text label
   // for that value.  16 point compass
   $winddir = $degrees;
   if ($winddir == "n/a") { return($winddir); }

  if (!isset($winddir)) {
    return "---";
  }
  if (!is_numeric($winddir)) {
	return($winddir);
  }
  $windlabel = array ("N","NNE", "NE", "ENE", "E", "ESE", "SE", "SSE", "S",
	 "SSW","SW", "WSW", "W", "WNW", "NW", "NNW");
  $dir = $windlabel[ (integer)fmod((($winddir + 11) / 22.5),16) ];
  return($dir);

} // end function GML_direction	
