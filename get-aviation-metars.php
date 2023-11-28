<?php
error_reporting(E_ALL);
ini_set('display_errors','1');
#----------------------------------------------------------------------------
/*
  Script: get-aviation-metars.php

	Purpose: retrieve current decoded METAR reports from aviationweather.gov
	  and create a PHP file with an array with the formatted data for further processing
		
	Inputs; URL https://www.aviationweather.gov/adds/dataserver_current/current/metars.cache.csv
	        file metar-metadata-inc.php produced by get-metar-metadata.php program
					
	Output: aviation-metars-data-inc.php file with $METARS array containing formatted data.
	        aviation-metar-raw.txt cache file
 
  Script by Ken True - webmaster@saratoga-weather.org

*/
#----------------------------------------------------------------------------
// Version 1.00 - 27-Jun-2023 - Initial Release
// version 1.01 - 28-Jun-2023 - added cloud code to $M['codes'] if no other weather codes specified 
// Version 1.02 - 29-Jun-2023 - added patch for winddir out of range 0..360
// Version 1.03 - 01-Jul-2023 - added reporting for missing iconcodes
// Version 1.04 - 03-Jul-2023 - added CSV error checking
// Version 1.05 - 08-Jul-2023 - added $M['windkts'], $M['windgustkts'] and text wind info in knots
// Version 1.06 - 10-Jul-2023 - added precip data $M['PRECIP']
// Version 1.07 - 15-Jul-2023 - fix issues with missing temp/dp from METAR
// Version 1.08 - 16-Oct-2023 - update for new aviationweather.gov website changes 
// Version 1.09 - 17-Oct-2023 - additional fixes for new aviationweather.gov website changes
// Version 1.10 - 18-Oct-2023 - additional fixes for new aviationweather.gov website changes+LFBT metar fix
// Version 1.11 - 27-Nov-2023 - fix error with temp/dewpt at 0C/32F non-display
// Version 1.12 - 28-Nov-2023 - fix issue with temp 0F(0C) display
// Version 1.13 - 28-Nov-2023 - fix issue with temp 0F(0C) display w/PHP 7.4+
// -------------Settings ---------------------------------
  $cacheFileDir = './';      // default cache file directory
  $ourTZ = 'America/Los_Angeles';
	
// -------------End Settings -----------------------------
//

$GMLversion = 'get-aviation-metars.php V1.13 - 28-Nov-2023 - saratoga-weather.org';
$NOAA_URL = 'https://aviationweather.gov/data/cache/metars.cache.csv.gz'; // new location 15-June-2016
//
$NOAAcacheName = $cacheFileDir."aviationweather-current.csv";
$outputFile    = 'aviation-metars-data-inc.php';
// ---------- end of settings -----------------------

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

// --------- search for nearby metars ------------
  if (!function_exists('date_default_timezone_set')) {
	putenv("TZ=" . $ourTZ);
#	$Status .= "<!-- using putenv(\"TZ=$ourTZ\") -->\n";
    } else {
	date_default_timezone_set("$ourTZ");
#	$Status .= "<!-- using date_default_timezone_set(\"$ourTZ\") -->\n";
}
include_once("metar-metadata-inc.php");
if(file_exists("metar-cond-iconcodes-inc.php")) {
	include_once("metar-cond-iconcodes-inc.php");
} else {
	print "Warning: metar-cond-iconcodes-inc.php file not found. Aborting.\n";
	exit;
}

global $Debug,$metarMetadata,$missingLLE,$wxCodesSeen,$wxCodesMissing ;

$Debug = "<!-- $GMLversion -->\n";
$Debug .= "<!-- run on ".date('D, d-M-Y H:i:s T'). " -->\n";
$Debug .= "<!--        ".gmdate('D, d-M-Y H:i:s T'). " -->\n";

$output = '';
$metars = array();
$missingLLE = array();
$wxCodesSeen = array();
$wxCodesMissing = array();

$rawGZ = GML_fetchUrlWithoutHanging($NOAA_URL);
# ---------------------------------------------------------------------------------
# note: new url of https://aviationweather.gov/data/cache/metars.cache.csv.gz 
# returns a truncated header of \x0c with curl.  grrr.
# we'll prepend a 'good header' for the gzip return to let the gzdecode work
# ---------------------------------------------------------------------------------
if(strlen($rawGZ) < 5000) {
	$Debug .= "<!-- Oops.. insufficient data returned at ".date('Ymd-Hms')." -->\n";
	$Debug .= "<!-- rawGZ returned ".strlen($rawGZ)." bytes. -->\n";
  $Debug = preg_replace('|<!--|is','',$Debug);
  $Debug = preg_replace('|-->|is','',$Debug);
  print "<pre>\n";
  print $Debug;
	file_put_contents($cacheFileDir.'log-'.date('Ymd').'.txt',"----------\n".$Debug,FILE_APPEND );
	exit();
}
$goodHeader = "\x1f\x8b\x08\x08"; # 4-byte GZ file header
if(substr($rawGZ,0,4) === $goodHeader) {# check for valid GZ header
  $Debug .= "<!-- ..GZ file has good header\n";
	$rawHTML = gzdecode($rawGZ);
} else {
  $Debug .= "<!-- GZ file has missing header .. prepending it.\n";
  $rawHTML = gzdecode($goodHeader.$rawGZ);
}
if($rawHTML !== false) {
  $Debug .= "<!-- GZ file was decompressed successfully.\n";
  file_put_contents($cacheFileDir.'aviation-metar-raw.txt',$rawHTML);
	$Debug .= "<!-- saved '".$cacheFileDir.'aviation-metar-raw.txt'."' raw data file -->\n";
} else {
	file_put_contents($cacheFileDir.'aviation-metar-csv.gz.'.date('Ymd-His').'.txt',$rawGZ);
	$Debug .= "<!-- Oops.. gzdecode of $NOAA_URL contents failed -->\n";
	$Debug .= "<!--  saved raw GZ file to '".$cacheFileDir.'aviation-metar-csv.gz.'.date('Ymd-His').'.txt'."' -->\n";
  $Debug = preg_replace('|<!--|is','',$Debug);
  $Debug = preg_replace('|-->|is','',$Debug);
  print "<pre>\n";
  print $Debug;
	exit();
}

$Debug .= "<!-- Processing METAR entries -->\n";
$recs = explode("\n",$rawHTML);

if(strlen($rawHTML) < 2000 ){
	$Debug .= "<!-- Oops.. insufficient data returned from $NOAA_URL - aborting. -->\n";
	$Debug = preg_replace('|<!--|is','',$Debug);
	$Debug = preg_replace('|-->|is','',$Debug);
	print "<pre>\n";
	print $Debug;
	print "</pre>\n";
	exit;
}
	
	
foreach ($recs as $i => $rec) {
	if(strlen($rec) < 100) {continue;}
	if(substr($rec,0,1) == '"') {
		$vals = str_getcsv($rec); 
		$Debug .= "<!-- rec $i has embedded commas for '".$vals[0]."'... reprocessing -->\n";
		$vals[0] = str_replace(',','',$vals[0]); # remove commas in raw metar.
		$rec = join(',',$vals);
	}
	if(substr($rec,0,8) == 'raw_text') {continue;}
	$metar = substr($rec,0,4);
	$tM = gen_data($metar,trim($rec));
	if(count($tM) > 0) {$metars[$metar] = $tM;}
}

ksort($metars);

file_put_contents($outputFile,
  "<?php \n" .
	"# generated by $GMLversion\n" .
	"# https://github.com/ktrue/metar-placefile by Saratoga-Weather.org\n" .
	"# run on: ".gmdate('D, d-M-Y H:i:s T')."\n".
	"# php version ".phpversion()."\n".
	"# \n" .
	"# raw data in ".$cacheFileDir.'aviation-metar-raw.txt'."\n".
	"#
if (isset(\$_REQUEST['sce']) && strtolower(\$_REQUEST['sce']) == 'view' ) {
   //--self downloader --
   \$filenameReal = __FILE__;
   \$download_size = filesize(\$filenameReal);
   header('Pragma: public');
   header('Cache-Control: private');
   header('Cache-Control: no-cache, must-revalidate');
   header('Content-type: text/plain');
   header('Accept-Ranges: bytes');
   header(\"Content-Length: \$download_size\");
   header('Connection: close');
   
   readfile(\$filenameReal);
   exit;
}
".
	"\$METARS = ".var_export($metars,true).";\n");
$Debug .= "<!-- file $outputFile saved. -->\n";

#file_put_contents("tgftp.txt",$output);
#Debug .= "<!-- file tgftp.txt saved with ".strlen($output)." bytes. -->\n";

$Debug .= "\n\n<!-- finished processing -->\n";
if(count($missingLLE) > 0) {
	ksort($missingLLE);
  $missinglist = wordwrap(join(' ',$missingLLE));
 $Debug .= "<!-- Missing lat/long/name/elevation for: -->\n";
 $Debug .= "<!-- \n$missinglist -->\n";
 $Debug .= "<!-- \n.. ".count($missingLLE)." METARs are reporting w/o metadata available. -->\n";
}
if(count($wxCodesMissing) > 0) {
	$Debug .= "<!-- \n.. ".count($wxCodesMissing)." wx codes missing from metar-cond-iconcodes-inc.php lookup -->\n";
	ksort($wxCodesMissing);
	foreach ($wxCodesMissing as $icao => $data) {
		$Debug .= "<!--  $icao '$data' -->\n";
	}
}
	
if(count($wxCodesSeen) > 0) {
	ksort($wxCodesSeen);
	$Debug .= "<!-- \nwx codes seen\n".var_export($wxCodesSeen,true)."\n -->\n";
}

$Debug = preg_replace('|<!--|is','',$Debug);
$Debug = preg_replace('|-->|is','',$Debug);
print "<pre>\n";
print $Debug;
print ".. found ".count($metars)." METAR records in $outputFile \$METARS array.\n";
print "</pre>\n";

// ----------------------------functions ----------------------------------- 

function gen_data($icao,$data) {
	global $metarMetadata, $Debug, $missingLLE,$wxCodesSeen,$wxCodesMissing;
	static $compass = array(
					"N", "NNE", "NE", "ENE",
					"E", "ESE", "SE", "SSE",
					"S", "SSW", "SW", "WSW",
					"W", "WNW", "NW", "NNW"
			);
	static $cloudCode = array(
		'SKC' => 'Clear',
		'CLR' => 'Clear',
		'CAVOK' => 'Clear',
		'FEW' => 'Few Clouds',
		'FW'  => 'Few Clouds',
		'SCT' => 'Partly Cloudy',
		'BKN' => 'Mostly Cloudy',
		'BK'  => 'Mostly Cloudy',
		'OVC' => 'Overcast',
//		'NSC' => 'No significant clouds', // official designation.. we map to Partly Cloudy
		'NSC' => 'Partly Cloudy',
//        'NCD' => 'No cloud detected',  // official designation .. we map to Clear
        'NCD' => 'Clear',
//		'TCU' => 'Towering Cumulus',     // official designation .. we map to Thunder Storm
		'TCU' => 'Thunderstorm',
//		'CB'  => 'Cumulonimbus',         // official designation .. we map to Thunder Storm
		'CB'  => 'Thunderstorm',
		'UNK' => 'Unknown',
		'VV'  => 'Overcast',
		'OVX' => 'Vertical Visibility');

#
# create mapping for a display matching the old $wxInfo() array;
/*
offset	data	Convert	Key	sample
0	raw_text		RAW-METAR,FIX-METAR	KHSE 201551Z AUTO 09010KT 10SM -RA SCT017 BKN100 BKN120 26/23 A2994 RMK AO2 RAB51 SLP136 P0000 T02560228
1	station_id		STATION	KHSE
		lookup from STATION	NAME	Hatteras/Mitchel,  North Carolina, USA
2	observation_time		OBSTIME	2023-06-20T15:51:00Z
3	latitude		LATITUDE	35.23
4	longitude		LONGITUDE	-75.62
5	temp_c	C2F	dtemp	25.6
		constructed	TEMP	73F (23C)
6	dewpoint_c	C2F	ddewpt	22.8
		constructed	DEWPT	70F (21C)
		conditional	dheatidx	104
		constructed-conditional	HEATINDEX	104F (40C)
		conditional	dwindch	22
		constructed-conditional	WINDCHILL	22F (-6C)
		constructed	dhum	89
		constructed	HUMIDITY	89%
7	wind_dir_degrees		dwinddir	90
8	wind_speed_kt	Kt2mph	dwind	10
9	wind_gust_kt	Kt2mph	dwindgust	
		constructed	WIND	S at 9 mph (15 km/h),  gusting to 17 mph (28 km/h)
10	visibility_statute_mi		dvis	5
		constructed	VISIBILITY	5 miles (8 km)
11	altim_in_hg		daltinhg	29.94
			daltinhpa	1013.8
		Constructed	BAROMETER	1013 hPa (29.94 inHg)
12	sea_level_pressure_mb			1013.6
				
21	wx_string		codes	-RA

22	sky_cover			SCT
23	cloud_base_ft_agl			1700
24	sky_cover			BKN
25	cloud_base_ft_agl			10000
26	sky_cover			BKN
27	cloud_base_ft_agl			12000
28	sky_cover			
29	cloud_base_ft_agl			
		constructed	CLOUDS	Mostly Cloudy
		constructed	CLOUD-DETAILS	Partly Cloudy 1700 ft, Mostly Cloudy 10000 ft, Mostly Cloudy 12000 ft
30	flight_category			VFR
31	three_hr_pressure_tendency_mb			
32	maxT_c			
33	minT_c			
34	maxT24hr_c			
35	minT24hr_c			
36	precip_in			0.005
37	pcp3hr_in			
38	pcp6hr_in			
39	pcp24hr_in			
40	snow_in			
41	vert_vis_ft			
42	metar_type			METAR
43	elevation_m	meter2feet	ELEVATION	4

*/
	$M = array();
	$V = str_getcsv($data); // split up CSV record
	if(count($V) != 44) {
		$Debug .= "<!-- malformed record '".$V[0]."' with ".count($V)." fields rejected. -->\n";
		return($M);
	}
	if($icao == 'LFBT') {$V[4] = '0.0000';} #kludge to fix empty longitude in CSV for this station
	$M['RAW-METAR'] = $V[0];
	$M['FIX-METAR'] = $V[0];
	$M['STATION']   = $icao;
	$M['OBSTIME']   = $V[2];
	$M['LATITUDE']  = $V[3];
	$M['LONGITUDE'] = $V[4];
	if(!is_numeric($V[3]) or !is_numeric($V[4])) {
		$missingLLE[$icao] = $icao."(lat='".$V[3]."', lon='".$V[4]."')";
	}
	$M['ELEVATION'] = intval(round(convertDistance((float)$V[43],'m','ft'),0));
	if(isset($metarMetadata[$icao])) {
		list($mICAO,$mNiceName,$mLat,$mLon,$mElevFeet,$mCountryState,$mName) 
		  = explode("|",$metarMetadata[$icao]);
	  $M['LOOKUP'] = "$mLat,$mLon,$mElevFeet";
		if(!is_numeric($V[3]) )  {$M['LATITUDE']  = $mLat; }
		if(!is_numeric($V[4]) )  {$M['LONGITUDE'] = $mLon; }
		if(!is_numeric($V[43]) ) {$M['ELEVATION'] = $mElevFeet; }
	} else {
		$mNiceName = 'Not Specified';
	}
	$M['NAME']      = $mNiceName;
	if(isset($V[5]) and is_numeric($V[5])) {
    $M['dtemp']     = intval(round(convertTemperature($V[5],'c','f'),0));
	  $M['TEMPERATURE'] = $M['dtemp']."F (".intval(round($V[5]))."C)";
	} else {
		$M['TEMPERATURE'] = 'n/a';
	}
	if(isset($V[6]) and is_numeric($V[6])) {
	  $M['ddewpt']    = intval(round(convertTemperature($V[6],'c','f'),0));	
	  $M['DEWPT']     = $M['ddewpt']."F (".intval(round($V[6]))."C)";
	} else {
    $M['DEWPT']		  = 'n/a';
	}
	if(isset($V[5]) and is_numeric($V[5]) and isset($V[6]) and is_numeric($V[6])) {
  	$M['dhum']      = intval(round(calculateHumidity($V[5],$V[6]),0));
	  $M['HUMIDITY']  = $M['dhum']."%";
	} else {
		$M['HUMIDITY']  = 'n/a';
	}

  if(isset($M['dtemp']) and isset($M['dhum']) and $M['dtemp'] >= 79) {
		$M['dheatidx'] = intval(round(calculateHeatIndex($M['dtemp'],$M['dhum'],'F'),0));
		$thidxc=         intval(round(convertTemperature($M['dheatidx'],'f','c'),0));
		$M['HEATINDEX'] = $M['dheatidx']."F ({$thidxc}C)";
	}
	
	$M['dwinddir']   = (integer)$V[7];
	if($M['dwinddir'] >= 0 and $M['dwinddir'] <= 360) {
	  $direction       = $compass[intval(round($M['dwinddir'] / 22.5) % 16)];
	} else {
		$direction       = 'n/a';
	}
	$M['dwind']      = intval(round(convertSpeed($V[8],'kt','mph'),0));
	$M['dwindkts']   = (integer)$V[8];
	$windkmh         = intval(round(convertSpeed($V[8],'kt','kmh'),0));
	$M['WIND']       = $direction." at ".$M['dwind']." mph"."($windkmh km/h, ".$M['dwindkts']." kt)";
	if($V[9] !== '') {
		$M['dwindgust'] = intval(round(convertSpeed($V[9],'kt','mph'),0));
		$gustkmh        = intval(round(convertSpeed($V[9],'kt','kmh'),0));
		$M['dwindgustkts'] = (integer)$V[9];
		$M['WIND']     .= ", gusting to ".$M['dwindgust']." mph ($gustkmh km/h,".$M['dwindgustkts']." kt)";
	}
	if($M['dwind'] == 0 and $M['dwinddir'] == 0) {
		$M['WIND']  = 'Calm';
	}

	if(isset($M['dtemp']) and $M['dtemp'] <= 50 and $M['dwind'] > 0) {
		$M['dwindch'] = intval(round(calculateWindChill($M['dtemp'],$M['dwind']),0));
		$twindchC     = intval(round(convertTemperature($M['dwindch'],'f','c'),0));
		$M['WINDCHILL'] = $M['dwindch']."F ({$twindchC}C)";
	}
	
	if($V[10]!== '') {
	  $M['dvis']        = $V[10];
	  $viskm            = intval(round(convertDistance((float)$V[10],'sm','km'),0));
		$M['VISIBILITY']  = $V[10]." sm ($viskm km)";
	}
	$M['daltinhg']      = round((float)$V[11],2);
	$M['dalthpa']       = round(convertPressure((float)$V[11],'in','hpa'),1);
	$M['BAROMETER']     = $M['daltinhg']." inHg (".$M['dalthpa']." hPa)";
	$M['codes']         = str_replace(' ',',',trim($V[21]));
	$M['CONDITIONS']    =  decode_conditions($M['codes']);
	$M['CLOUD-DETAILS'] = '';
	$saveClouds = array();
	for ($i=22;$i<30;$i+=2) {# process cloud entries
	  if(strlen($V[$i]) < 1){ break;}
		$saveClouds[] = $V[$i];
		if( in_array($V[$i],array('CAVOK','CLR','SKC','NCD','NSC')) ) {
			if($M['CONDITIONS'] == '') {$M['CONDITIONS'] = 'Clear';}
			continue;
		}
		$type = $V[$i];
		$hft  = $V[$i+1];
		if($type == 'OVX') {$hft = $V[41];}
		if(isset($cloudCode[$type])){
			$type = $cloudCode[$type];
		}
		$M['CLOUD-DETAILS'] .= "$type $hft ft\t";
	}
	if(strlen($M['CLOUD-DETAILS']) > 5) {
		$M['CLOUD-DETAILS'] = substr($M['CLOUD-DETAILS'],0,strlen($M['CLOUD-DETAILS'])-1);
	}
	if($M['CONDITIONS'] == '' and count($saveClouds) > 0) {
		$c = array_pop($saveClouds);
		if(isset($cloudCode[$c])) {
			$M['CONDITIONS'] = $cloudCode[$c];
			if(strlen($M['codes']) < 1) {
				$M['codes'] = $c; # add sky to codes if no other codes already found
			} 
		}
	}
	if($M['CONDITIONS']== 'Clear' and $M['codes']== '') {
		$M['codes'] = 'SKC';
	}
	if($M['codes'] !== '') {
		if(isset($wxCodesSeen[$M['codes']])) {
			$wxCodesSeen[$M['codes']]++;
		} else {
			$wxCodesSeen[$M['codes']] = 1;
		}
    if(pick_cond_icon($M['codes']) < 0) {
			$wxCodesMissing[$M['STATION']] = $M['codes'].'|'.$M['RAW-METAR'];
		}
	}
/*
36	precip_in			0.005
37	pcp3hr_in			
38	pcp6hr_in			
39	pcp24hr_in			
40	snow_in			
*/
	if(!empty($V[36]) or !empty($V[37]) or !empty($V[38]) or !empty($V[39])) { #have precip report
	  #$Debug .= "<!-- $data -->\n";
		$p = '';
		if(!empty($V[36])) { $p .= $V[36].' in, '; }
		if(!empty($V[37])) { $p .= $V[37].' in (3hr), '; }
		if(!empty($V[38])) { $p .= $V[38].' in (6hr), '; }
		if(!empty($V[39])) { $p .= $V[39].' in (24hr), '; }
		$M['PRECIP'] = substr($p,0,strlen($p)-2);
	}
	
	if(!empty($V[40])) {
		$M['SNOW'] = 'Snow: '.$V[40].'in';
	}
	return($M);
	
}

function decode_conditions ($codes) {
	
	static $conditions = array(
                "+"           => "heavy",                   "-"           => "light",

                "vc"          => "vicinity",                "re"          => "recent",
                "nsw"         => "no significant weather",

                "mi"          => "shallow",                 "bc"          => "patches",
                "pr"          => "partial",                 "ts"          => "thunderstorm",
                "bl"          => "blowing",                 "sh"          => "showers",
                "dr"          => "low drifting",            "fz"          => "freezing",

                "dz"          => "drizzle",                 "ra"          => "rain",
                "sn"          => "snow",                    "sg"          => "snow grains",
                "ic"          => "ice crystals",            "pe"          => "ice pellets",
                "pl"          => "ice pellets",             "gr"          => "hail",
                "gs"          => "small hail/snow pellets", "up"          => "unknown precipitation",

                "br"          => "mist",                    "fg"          => "fog",
                "fu"          => "smoke",                   "va"          => "volcanic ash",
                "sa"          => "sand",                    "hz"          => "haze",
                "py"          => "spray",                   "du"          => "widespread dust",

                "sq"          => "squall",                  "ss"          => "sandstorm",
                "ds"          => "duststorm",               "po"          => "well developed dust/sand whirls",
                "fc"          => "funnel cloud",

                "+fc"         => "tornado/waterspout"
            );

  static $condRegex = "(-|\+|VC|RE|NSW)?(MI|BC|PR|TS|BL|SH|DR|FZ)?((DZ)|(RA)|(SN)|(SG)|(IC)|(PE)|(PL)|(GR)|(GS)|(UP))*(BR|FG|FU|VA|DU|SA|HZ|PY)?(PO|SQ|FC|SS|DS)?";
	
	$C = ''; # assembly string for conditions
	
	if(strlen($codes) < 1) {return('');}
	
	$mcodes = explode(',',$codes);
	
	foreach ($mcodes as $i=> $code) {
		if(preg_match("/^".$condRegex."$/i", $code, $result)) {
			   // First some basic setups
		 if (strlen($C) > 0) {
				 $C .= ",";
		 }
	
		 if (in_array(strtolower($result[0]), $conditions)) {
				 // First try matching the complete string
				 $C .= " ".$conditions[strtolower($result[0])];
		 } else {
				 // No luck, match part by part
				 array_shift($result);
				 $result = array_unique($result);
				 foreach ($result as $condition) {
						 if (strlen($condition) > 0) {
								 $C .= " ".$conditions[strtolower($condition)];
						 }
				 }
		 }
   $C = trim(ucwords($C));
		}
	}
	return($C);
}


// get contents from one URL and return as string 
 function GML_fetchUrlWithoutHanging($url,$useFopen=false) {
// get contents from one URL and return as string 
  global $Debug, $needCookie;
  
  $overall_start = time();
  if (! $useFopen) {
   // Set maximum number of seconds (can have floating-point) to wait for feed before displaying page without feed
   $numberOfSeconds=30;   

// Thanks to Curly from ricksturf.com for the cURL fetch functions

  $data = '';
  $domain = parse_url($url,PHP_URL_HOST);
  $theURL = str_replace('nocache','?'.$overall_start,$url);        // add cache-buster to URL if needed
  $Debug .= "<!-- curl fetching '$theURL' -->\n";
  $ch = curl_init();                                           // initialize a cURL session
  curl_setopt($ch, CURLOPT_URL, $theURL);                         // connect to provided URL
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);                 // don't verify peer certificate
  curl_setopt($ch, CURLOPT_USERAGENT, 
    'Mozilla/5.0 (get-aviation-metars.php - saratoga-weather.org)');

  curl_setopt($ch,CURLOPT_HTTPHEADER,                          // request LD-JSON format
     array (
         "Accept: */*",
				 "Accept-Encoding: gzip,deflate,br",
				 "Pragma: no-cache",
				 "Cache-Control: no-cache"
				 
     ));

  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $numberOfSeconds);  //  connection timeout
  curl_setopt($ch, CURLOPT_TIMEOUT, $numberOfSeconds);         //  data timeout
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);              // return the data transfer
  curl_setopt($ch, CURLOPT_NOBODY, false);                     // set nobody
  curl_setopt($ch, CURLOPT_HEADER, false);                      // include header information
//  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);              // follow Location: redirect
//  curl_setopt($ch, CURLOPT_MAXREDIRS, 1);                      //   but only one time
  if (isset($needCookie[$domain])) {
    curl_setopt($ch, $needCookie[$domain]);                    // set the cookie for this request
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);             // and ignore prior cookies
    $Debug .=  "<!-- cookie used '" . $needCookie[$domain] . "' for GET to $domain -->\n";
  }

  $data = curl_exec($ch);                                      // execute session

  if(curl_error($ch) <> '') {                                  // IF there is an error
   $Debug .= "<!-- curl Error: ". curl_error($ch) ." -->\n";        //  display error notice
  }
  $cinfo = curl_getinfo($ch);                                  // get info on curl exec.
/*
curl info sample
Array
(
[url] => http://saratoga-weather.net/clientraw.txt
[content_type] => text/plain
[http_code] => 200
[header_size] => 266
[request_size] => 141
[filetime] => -1
[ssl_verify_result] => 0
[redirect_count] => 0
  [total_time] => 0.125
  [namelookup_time] => 0.016
  [connect_time] => 0.063
[pretransfer_time] => 0.063
[size_upload] => 0
[size_download] => 758
[speed_download] => 6064
[speed_upload] => 0
[download_content_length] => 758
[upload_content_length] => -1
  [starttransfer_time] => 0.125
[redirect_time] => 0
[redirect_url] =>
[primary_ip] => 74.208.149.102
[certinfo] => Array
(
)

[primary_port] => 80
[local_ip] => 192.168.1.104
[local_port] => 54156
)
*/
  $Debug .= "<!-- HTTP stats: " .
    " RC=".$cinfo['http_code'];
	if(isset($cinfo['primary_ip'])) {
		$Debug .= " dest=".$cinfo['primary_ip'] ;
	}
	if(isset($cinfo['primary_port'])) { 
	  $Debug .= " port=".$cinfo['primary_port'] ;
	}
	if(isset($cinfo['local_ip'])) {
	  $Debug .= " (from sce=" . $cinfo['local_ip'] . ")";
	}
	$Debug .= 
	"\n      Times:" .
    " dns=".sprintf("%01.3f",round($cinfo['namelookup_time'],3)).
    " conn=".sprintf("%01.3f",round($cinfo['connect_time'],3)).
    " pxfer=".sprintf("%01.3f",round($cinfo['pretransfer_time'],3));
	if($cinfo['total_time'] - $cinfo['pretransfer_time'] > 0.0000) {
	  $Debug .=
	  " get=". sprintf("%01.3f",round($cinfo['total_time'] - $cinfo['pretransfer_time'],3));
	}
    $Debug .= " total=".sprintf("%01.3f",round($cinfo['total_time'],3)) .
    " secs -->\n";

  //$Debug .= "<!-- curl info\n".print_r($cinfo,true)." -->\n";
  curl_close($ch);                                              // close the cURL session
  //$Debug .= "<!-- raw data\n".$data."\n -->\n"; 
  $i = strpos($data,"\r\n\r\n");
  $headers = substr($data,0,$i);
  $content = substr($data,$i+4);
  if($cinfo['http_code'] <> '200') {
    $Debug .= "<!-- headers returned:\n".$headers."\n -->\n"; 
  }
  return $content;                                                 // return headers+contents

 } else {
//   print "<!-- using file_get_contents function -->\n";
   $STRopts = array(
	  'http'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (get-aviation-metars.php - saratoga-weather.org)\r\n" .
				"Accept: text/html,text/plain\r\n"
	  ),
	  'https'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (get-aviation-metars.php - saratoga-weather.org)\r\n" .
				"Accept: text/html,text/plain\r\n"
	  )
	);
	
   $STRcontext = stream_context_create($STRopts);

   $T_start = GML_fetch_microtime();
   $xml = file_get_contents($url,false,$STRcontext);
   $T_close = GML_fetch_microtime();
   $headerarray = get_headers($url,0);
   $theaders = join("\r\n",$headerarray);
   $xml = $theaders . "\r\n\r\n" . $xml;

   $ms_total = sprintf("%01.3f",round($T_close - $T_start,3)); 
   $Debug .= "<!-- file_get_contents() stats: total=$ms_total secs -->\n";
   $Debug .= "<-- get_headers returns\n".$theaders."\n -->\n";
//   print " file() stats: total=$ms_total secs.\n";
   $overall_end = time();
   $overall_elapsed =   $overall_end - $overall_start;
   $Debug .= "<!-- fetch function elapsed= $overall_elapsed secs. -->\n"; 
//   print "fetch function elapsed= $overall_elapsed secs.\n"; 
   return($xml);
 }

}    // end GML_fetchUrlWithoutHanging
// ------------------------------------------------------------------

function GML_fetch_microtime()
{
   list($usec, $sec) = explode(" ", microtime());
   return ((float)$usec + (float)$sec);
}
   
 
// ----------------------------------------------------------

#
# the following functions are amalgmated from https://github.com/pear/Services_Weather
/**
 * PEAR::Services_Weather_Common
 *
 * PHP versions 4 and 5
 *
 * <LICENSE>
 * Copyright (c) 2005-2011, Alexander Wirtz
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * o Redistributions of source code must retain the above copyright notice,
 *   this list of conditions and the following disclaimer.
 * o Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 * o Neither the name of the software nor the names of its contributors
 *   may be used to endorse or promote products derived from this software
 *   without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 * </LICENSE>
 *
 * @category    Web Services
 * @package     Services_Weather
 * @author      Alexander Wirtz <alex@pc4p.net>
 * @copyright   2005-2011 Alexander Wirtz
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version     CVS: $Id$
 * @link        http://pear.php.net/package/Services_Weather
 * @filesource
 */
#
# excerpted by Ken True - webmaster@saratoga-weather.org from https://github.com/pear/Services_Weather
# mods: removed class structure, changed to force imperial units (F,MPH,in,inHg), renamed some return names
# Version 1.00 - 26-Jun-2023 - initial release
#
#

    // {{{ convertTemperature()
    /**
     * Convert temperature between f and c
     *
     * @param   float                       $temperature
     * @param   string                      $from
     * @param   string                      $to
     * @return  float
     * @access  public
     */
    function convertTemperature($intemperature, $from, $to)
    {
        if (!is_numeric($intemperature) or $intemperature == '') {
            return 'n/a';
        }

        $from = strtolower($from[0]);
        $to   = strtolower($to[0]);
				$temperature = (float)$intemperature;

        $result = array(
            "f" => array(
                "f" => $temperature,            "c" => ($temperature - 32) / 1.8
            ),
            "c" => array(
                "f" => 1.8 * $temperature + 32, "c" => $temperature
            )
        );

        return $result[$from][$to];
    }
    // }}}

    // {{{ convertSpeed()
    /**
     * Convert speed between mph, kmh, kt, mps, fps and bft
     *
     * Function will return "false" when trying to convert from
     * Beaufort, as it is a scale and not a true measurement
     *
     * @param   float                       $speed
     * @param   string                      $from
     * @param   string                      $to
     * @return  float|int|bool
     * @access  public
     * @link    http://www.spc.noaa.gov/faq/tornado/beaufort.html
     */
    function convertSpeed($inspeed, $from, $to)
    {
        $from = strtolower($from);
        $to   = strtolower($to);
				$speed = (float)$inspeed;

        static $factor;
        static $beaufort;
        if (!isset($factor)) {
            $factor = array(
                "mph" => array(
                    "mph" => 1,         "kmh" => 1.609344, "kt" => 0.8689762, "mps" => 0.44704,   "fps" => 1.4666667
                ),
                "kmh" => array(
                    "mph" => 0.6213712, "kmh" => 1,        "kt" => 0.5399568, "mps" => 0.2777778, "fps" => 0.9113444
                ),
                "kt"  => array(
                    "mph" => 1.1507794, "kmh" => 1.852,    "kt" => 1,         "mps" => 0.5144444, "fps" => 1.6878099
                ),
                "mps" => array(
                    "mph" => 2.2369363, "kmh" => 3.6,      "kt" => 1.9438445, "mps" => 1,         "fps" => 3.2808399
                ),
                "fps" => array(
                    "mph" => 0.6818182, "kmh" => 1.09728,  "kt" => 0.5924838, "mps" => 0.3048,    "fps" => 1
                )
            );

            // Beaufort scale, measurements are in knots
            $beaufort = array(
                  1,   3,   6,  10,
                 16,  21,  27,  33,
                 40,  47,  55,  63
            );
        }

        if ($from == "bft") {
            return false;
        } elseif ($to == "bft") {
            $speed = round($speed * $factor[$from]["kt"], 0);
            for ($i = 0; $i < sizeof($beaufort); $i++) {
                if ($speed <= $beaufort[$i]) {
                    return $i;
                }
            }
            return sizeof($beaufort);
        } else {
            return ($speed * $factor[$from][$to]);
        }
    }
    // }}}

    // {{{ convertPressure()
    /**
     * Convert pressure between in, hpa, mb, mm and atm
     *
     * @param   float                       $pressure
     * @param   string                      $from
     * @param   string                      $to
     * @return  float
     * @access  public
     */
    function convertPressure($pressure, $from, $to)
    {
        $from = strtolower($from);
        $to   = strtolower($to);

        static $factor;
        if (!isset($factor)) {
            $factor = array(
                "in"   => array(
                    "in" => 1,         "hpa" => 33.863887, "mb" => 33.863887, "mm" => 25.4,      "atm" => 0.0334213
                ),
                "hpa"  => array(
                    "in" => 0.02953,   "hpa" => 1,         "mb" => 1,         "mm" => 0.7500616, "atm" => 0.0009869
                ),
                "mb"   => array(
                    "in" => 0.02953,   "hpa" => 1,         "mb" => 1,         "mm" => 0.7500616, "atm" => 0.0009869
                ),
                "mm"   => array(
                    "in" => 0.0393701, "hpa" => 1.3332239, "mb" => 1.3332239, "mm" => 1,         "atm" => 0.0013158
                ),
                "atm"  => array(
                    "in" => 29,921258, "hpa" => 1013.2501, "mb" => 1013.2501, "mm" => 759.999952, "atm" => 1
                )
            );
        }

        return ($pressure * $factor[$from][$to]);
    }
    // }}}

    // {{{ convertDistance()
    /**
     * Convert distance between km, ft and sm
     *
     * @param   float                       $distance
     * @param   string                      $from
     * @param   string                      $to
     * @return  float
     * @access  public
     */
    function convertDistance($distance, $from, $to)
    {
        $to   = strtolower($to);
        $from = strtolower($from);

        static $factor;
        if (!isset($factor)) {
            $factor = array(
                "m" => array(
                    "m" => 1,            "km" => 1000,      "ft" => 3.280839895, "sm" => 0.0006213699
                ),
                "km" => array(
                    "m" => 0.001,        "km" => 1,         "ft" => 3280.839895, "sm" => 0.6213699
                ),
                "ft" => array(
                    "m" => 0.3048,       "km" => 0.0003048, "ft" => 1,           "sm" => 0.0001894
                ),
                "sm" => array(
                    "m" => 0.0016093472, "km" => 1.6093472, "ft" => 5280.0106,   "sm" => 1
                )
            );
        }

        return ($distance * $factor[$from][$to]);
    }
    // }}}

    // {{{ calculateWindChill()
    /**
     * Calculate windchill from temperature and windspeed (enhanced formula)
     *
     * Temperature has to be entered in deg F, speed in mph!
     *
     * @param   float                       $temperature
     * @param   float                       $speed
     * @return  float
     * @access  public
     * @link    http://www.nws.noaa.gov/om/windchill/
     */
    function calculateWindChill($temperature, $speed)
    {
        return (35.74 + 0.6215 * $temperature - 35.75 * pow($speed, 0.16) + 0.4275 * $temperature * pow($speed, 0.16));
    }
    // }}}

    // {{{ calculateHumidity()
    /**
     * Calculate humidity from temperature and dewpoint
     * This is only an approximation, there is no exact formula, this
     * one here is called Magnus-Formula
     *
     * Temperature and dewpoint have to be entered in deg C!
     *
     * @param   float                       $temperature
     * @param   float                       $dewPoint
     * @return  float
     * @access  public
     * @link    http://www.faqs.org/faqs/meteorology/temp-dewpoint/
     */
    function calculateHumidity($temperature, $dewPoint)
    {
        // First calculate saturation steam pressure for both temperatures
        if ($temperature >= 0) {
            $a = 7.5;
            $b = 237.3;
        } else {
            $a = 7.6;
            $b = 240.7;
        }
        $tempSSP = 6.1078 * pow(10, ($a * $temperature) / ($b + $temperature));

        if ($dewPoint >= 0) {
            $a = 7.5;
            $b = 237.3;
        } else {
            $a = 7.6;
            $b = 240.7;
        }
        $dewSSP  = 6.1078 * pow(10, ($a * $dewPoint) / ($b + $dewPoint));

        $dp = 100 * $dewSSP / $tempSSP;
				if($dp < 0) {$dp = 0.0;}
				if($dp > 100) {$dp = 100.0;}
        return ($dp);
    }
    // }}}

    // {{{ calculateDewPoint()
    /**
     * Calculate dewpoint from temperature and humidity
     * This is only an approximation, there is no exact formula, this
     * one here is called Magnus-Formula
     *
     * Temperature has to be entered in deg C!
     *
     * @param   float                       $temperature
     * @param   float                       $humidity
     * @return  float
     * @access  public
     * @link    http://www.faqs.org/faqs/meteorology/temp-dewpoint/
     */
    function calculateDewPoint($temperature, $humidity)
    {
        if ($temperature >= 0) {
            $a = 7.5;
            $b = 237.3;
        } else {
            $a = 7.6;
            $b = 240.7;
        }

        // First calculate saturation steam pressure for temperature
        $SSP = 6.1078 * pow(10, ($a * $temperature) / ($b + $temperature));

        // Steam pressure
        $SP  = $humidity / 100 * $SSP;

        $v   = log($SP / 6.1078, 10);

        return ($b * $v / ($a - $v));
    }
    // }}}

# -------- end of extract from Weather/Common.php class ---------------------

function calculateHeatIndex ($temp,$humidity,$useunit) {
// Calculate Heat Index from temperature and humidity
// Source of calculation: http://woody.cowpi.com/phpscripts/getwx.php.txt	
  global $Debug;
  if(preg_match('|C|i',$useunit)) {
    $tempF = round(1.8 * $temp + 32,1);
  } else {
	$tempF = round($temp,1);
  }
  $rh = $humidity;
  
  
  // Calculate Heat Index based on temperature in F and relative humidity (65 = 65%)
  if ($tempF > 79 && $rh > 39) {
	  $hiF = -42.379 + 2.04901523 * $tempF + 10.14333127 * $rh - 0.22475541 * $tempF * $rh;
	  $hiF += -0.00683783 * pow($tempF, 2) - 0.05481717 * pow($rh, 2);
	  $hiF += 0.00122874 * pow($tempF, 2) * $rh + 0.00085282 * $tempF * pow($rh, 2);
	  $hiF += -0.00000199 * pow($tempF, 2) * pow($rh, 2);
	  $hiF = round($hiF,1);
	  $hiC = round(($hiF - 32) / 1.8,1);
  } else {
	  $hiF = $tempF;
	  $hiC = round(($hiF - 32) / 1.8,1);
  }
  if(preg_match('|F|i',$useunit)) {
     $heatIndex = $hiF;	  
  } else {
	 $heatIndex = $hiC;
  }
  return($heatIndex);	
}

