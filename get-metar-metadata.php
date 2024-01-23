<?php
/*
  Purpose: read the METAR stations.txt file from Aviationweather.gov, parse entries and
	    create a metar-metadata-inc.php file with $METARS array with details about a METAR
      ICAO for use by other programs.
			Also optionally merge data from a new_station_data.txt file to update name,lat,long,elev
			info for stations not listed in the stations.txt file.

*/
// Script by Ken True - webmaster@saratoga-weather.org
// Version 1.00 - 21-Jun-2023 - Initial Release
// Version 1.01 - 22-Jun-2023 - use AviationWeather.gov source
// Version 1.02 - 27-Jun-2023 - added CSV import capability
// Version 1.03 - 28-Jun-2023 - added CSV import error checking
// Version 1.04 - 01-Jul-2023 - additional error checking on import of new_station_data.txt file
// Version 1.05 - 18-Oct-2023 - use saved stations.txt if new version not available via URL
// Version 1.06 - 22-Jan-2024 - script deprecated. Source data no longer available
// -------------Settings ---------------------------------
  $cacheFileDir = './';      // default cache file directory
  $GMLcacheName = "metar-location-raw.txt";    // cache file for stations.txt
  $GMLoutputFile = "metar-metadata-inc.php";   // output file with $METARS array
  $GMLaddedFile  = "new_station_data.txt";     // optional override file for ICAO,name,lat,long,elev-ft 

date_default_timezone_set('America/Los_Angeles');
// -------------End Settings -----------------------------
//
header('Content-type: text/plain,charset=ISO-8859-1');

$GMLversion = 'get-metar-metadata.php V1.06 - 22-Jan-2024 - saratoga-weather.org';
#$GML_URL = "http://weather.rap.ucar.edu/surface/stations.txt";  // metar station names/locations
$GML_URL = "https://www.aviationweather.gov/docs/metar/stations.txt";  // metar station names/locations

//


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
print ".. $GMLversion \n";

  global $GML_URL,$GMLcacheName,$NOAA_URL,$NOAAcacheName,$GMLrefetchSeconds,$Debug;
// You can now force the cache to update by adding ?force=1 to the end of the URL
$Debug = '';


$WhereLoaded = "from URL $GML_URL";
$html = GML_fetchUrlWithoutHanging($GML_URL);
if(strlen($html) > 10000) {
	$fp = fopen($GMLcacheName, "w"); 
	if($fp) {
		$write = fputs($fp, $html); 
		fclose($fp);
		$Debug .= ".. Wrote cache $GMLcacheName \n";
	} else {
		$Debug .= ".. Unable to write cache $GMLcacheName \n";
	}
} else {
	if(file_exists($GMLcacheName)) {
		$html = file_get_contents($GMLcacheName);
		$WhereLoaded = "from cache $GMLcacheName";
	} else {
		$html = '';
		$WhereLoaded = "$GMLcacheName is not available";
	}
}
$Debug .= ".. metar metadata load from $WhereLoaded \n";

print $Debug;

if(strlen($html) < 500 ) {
  print ".. data not available. Aborting. \n";
  return;
}

if(preg_match('|!\s+Date:\s+(\d+ \S+ \d{4})|Uis',$html,$matches)) {
	$GMLupdated = $matches[1];
} else {
	$GMLupdated = 'unknown';
}

/*

stations.txt file format:

          1         2         3         4         5         6         7         8         9
0123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890
ALASKA             09-MAY-11                                                  
CD  STATION         ICAO  IATA  SYNOP   LAT     LONG   ELEV   M  N  V  U  A  C
AK ADAK NAS         PADK  ADK   70454  51 53N  176 39W    4   X     T          7 US
AK AKHIOK           PAKH  AKK          56 56N  154 11W   14   X                8 US
AK AMBLER           PAFM  AFM          67 06N  157 51W   88   X                7 US
AK ANAKTUVUK PASS   PAKP  AKP          68 08N  151 44W  642   X                7 US
AK ANCHORAGE INTL   PANC  ANC   70273  61 10N  150 01W   38   X     T  X  A    5 US
AK ANCHORAGE/WFO    PAFC  AFC          61 10N  150 02W   48                  F 8 US
0123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890
          1         2         3         4         5         6         7         8         9
*/
$records = explode("\n",$html);
$mState = '';
$mDated = '';
$canadaProvinces = ',ALBERTA,BRITISH COLUMBIA,MANITOBA,NEW BRUNSWICK,NEWFOUNDLAND,NOVA SCOTIA,N.W. TERRITORIES,NUNAVUT,ONTARIO,PRINCE EDWARD ISLAND,QUEBEC,SASKATCHEWAN,YUKON TERRITORY,';
$mStates = array();
$mStateNames = array();
$returnMetars = array();
$nMetars = 0;
$nNotInNOAA = 0;
$nNotActive = 0;

foreach ($records as $n => $rec) {
  if(strlen($rec) >= 28 and preg_match('|^\d+-\S+\d+$|',substr($rec,19,9))) {
	 $mState = substr($rec,0,18);
	 $mState = preg_replace('|, ISLAMIC REP|','',$mState);
	 $mState = preg_replace('|US-MIL|','',$mState);
	 $mState = trim($mState);
	 $mNiceState = preg_replace_callback('!(\(|\s|\-|\/)(\S)!',
	 function ($m) {
		//"'\\1'.strtoupper('\\2')"
		return ($m[1].strtoupper($m[2]) ); 
	 },
	 ucfirst(strtolower($mState)));
	 $mDated = trim(substr($rec,19,9));
	 continue;
  }

  if (strlen($rec) < 62) { continue; }

  $isMetar = (substr($rec,62,1) == 'X')?true:false;
  $isReporting = (preg_match('/^[ |A|W|M]$/',substr($rec,74,1)))?true:false;
  if(!$isMetar or !$isReporting) {continue;}
  
  $mName  = trim(substr($rec,3,16));
  $mICAO  = substr($rec,20,4);
  if($mICAO == 'ICAO' or strlen(trim($mICAO)) < 4) {continue; }

  $tState = substr($rec,0,2);
	if($tState !== '  ') {
		if(isset($mStates[$tState])) {
			$mStates[$tState]++;
		} else {
			$mStates[$tState] = 1;
		}
		
	}
	
  $mRawLat = trim(substr($rec,39,6));
  $mRawLon = trim(substr($rec,47,7));
  $mRawElev = trim(substr($rec,55,4));
	$mElevFeet = round($mRawElev * 3.28084,0);
  $mLat = GML_DMS($mRawLat);
  $mLon = GML_DMS($mRawLon);
  $mCD = trim(substr($rec,0,2)); // only USA/Canada have state markers
  $mCountry = '';
  $mNiceName = preg_replace_callback('!(\(|\s|\-|\/)(\S)!',
	 function ($m) {
		//"'\\1'.strtoupper('\\2')"
		return ($m[1].strtoupper($m[2]) ); 
	 },
  ucfirst(strtolower($mName)));
  
  if(strlen($mCD) == 2) {
	  $mCountry = (strpos($canadaProvinces,$mState) !== false)?'CANADA - ':'USA - ';
	  $mNiceName .= ", $mNiceState";
	  $mNiceName .= (strpos($canadaProvinces,$mState) !== false)?', Canada':', USA';
  } else {
	  $mNiceName .= ', ' . $mNiceState;
  }
	$mTcode = empty($mCD)?':'.substr($mICAO,0,2):$mCD;
	$mTstate = empty($mCD)?'C:'.$mNiceState:$mNiceState;
	if(!empty($mCD)){
	  $mTstate .= (strpos($canadaProvinces,$mState) !== false)?', Canada':', USA';
  }
	$mStateNames[$mTstate] = $mTcode;
  $nMetars++;
  $returnMetars["$mICAO"] =  "$mICAO|$mNiceName|$mLat|$mLon|$mElevFeet|$mCountry$mState|$mName|";
	
}
  print ".. $nMetars METARs processed. \n";
	
if(file_exists($GMLaddedFile)) {
	print "..Loading updates from $GMLaddedFile.\n\n";
	$addRecs = file($GMLaddedFile);
	$recCount= 0;
	foreach ($addRecs as $i => $rec) {
		if(substr_count($rec,',') < 4) { 
		  print " $i='$rec' ignored.\n";
			continue;
		}

		list($mICAO,$mNiceName,$mLat,$mLon,$mElevFeet) = explode(',',trim($rec));
		$mCountryState = '';
		$mName='';
		if(!isset($returnMetars[$mICAO])) {
			print " '$mICAO' $mNiceName at $mLat,$mLon at $mElevFeet was added.\n";
		} else {
			list($oICAO,$oNiceName,$oLat,$oLon,$oElevFeet,$mCountryState,$mName) = explode("|",$returnMetars[$mICAO]);
			if(strlen($mNiceName) < 1) {$mNiceName = $oNiceName;}
			if(strlen($mLat) < 1)      {$mLat = $oLat;}
			if(strlen($mLon) < 1)      {$mLon = $oLon;}
			if(strlen($mElevFeet) < 1)      {$mElevFeet = $oElevFeet;}
			print " '$mICAO' $mNiceName at $mLat,$mLon elev. $mElevFeet ft was updated.\n";
		}
	$returnMetars["$mICAO"] =  "$mICAO|$mNiceName|$mLat|$mLon|$mElevFeet|$mCountryState|$mName|";
	$recCount++;
	}
	print "\n.. sdded info for $recCount entries in $GMLaddedFile.\n";
	print ".. ".count($returnMetars)." METARs total.\n";
}

  ksort($returnMetars);
	$heading = "<?php\n".
	"# $GMLversion\n".
	"# Run on ".date('r')."\n".
	"# Raw data updated $GMLupdated $WhereLoaded\n".
	"#\n".
	"\$metarMetadata = ";
  file_put_contents($GMLoutputFile,$heading.var_export($returnMetars,true).";\n");
	ksort($mStates);
	print ".. ".count($mStates)." states = array(\n";
	foreach($mStates as $state => $count) {
		print "'$state',";
	}
	print ");\n";

  ksort($mStateNames);
	print ".. ".count($mStateNames)." stateNames = ".var_export($mStateNames,true).";\n";

		
  print ".. output file $GMLoutputFile saved with \$metarMetadata array.\n";
	print ".. Done.\n";
	
	
// ----------------------------functions ----------------------------------- 

function GML_DMS($input) {
// expecting something like 'ddd mmD' or 	'7 17W' '117 17W'
   $t = trim($input);
	if(preg_match('|^(\d+) (\d+)(\S)$|',$t,$vals)) {
		$t = $vals[1] + $vals[2] / 60;
		$t = preg_match('|[WS]|',$vals[3])?-$t:$t;
		$t = sprintf('%01.4f',$t);
		
	}
	return($t);
}
 
// get contents from one URL and return as string 
 function GML_fetchUrlWithoutHanging($url,$useFopen=false) {
// get contents from one URL and return as string 
  global $Debug, $needCookie;
  
  $overall_start = time();
  if (! $useFopen) {
   // Set maximum number of seconds (can have floating-point) to wait for feed before displaying page without feed
   $numberOfSeconds=6;   

// Thanks to Curly from ricksturf.com for the cURL fetch functions

  $data = '';
  $domain = parse_url($url,PHP_URL_HOST);
  $theURL = str_replace('nocache','?'.$overall_start,$url);        // add cache-buster to URL if needed
  $Debug .= ".. curl fetching '$theURL' \n";
  $ch = curl_init();                                           // initialize a cURL session
  curl_setopt($ch, CURLOPT_URL, $theURL);                         // connect to provided URL
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);                 // don't verify peer certificate
  curl_setopt($ch, CURLOPT_USERAGENT, 
    'Mozilla/5.0 (get-metar-metadata.php - saratoga-weather.org)');

  curl_setopt($ch,CURLOPT_HTTPHEADER,                          // request LD-JSON format
     array (
         "Accept: text/html,text/plain"
     ));

  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $numberOfSeconds);  //  connection timeout
  curl_setopt($ch, CURLOPT_TIMEOUT, $numberOfSeconds);         //  data timeout
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);              // return the data transfer
  curl_setopt($ch, CURLOPT_NOBODY, false);                     // set nobody
  curl_setopt($ch, CURLOPT_HEADER, true);                      // include header information
//  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);              // follow Location: redirect
//  curl_setopt($ch, CURLOPT_MAXREDIRS, 1);                      //   but only one time
  if (isset($needCookie[$domain])) {
    curl_setopt($ch, $needCookie[$domain]);                    // set the cookie for this request
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);             // and ignore prior cookies
    $Debug .=  ".. cookie used '" . $needCookie[$domain] . "' for GET to $domain \n";
  }

  $data = curl_exec($ch);                                      // execute session

  if(curl_error($ch) <> '') {                                  // IF there is an error
   $Debug .= ".. curl Error: ". curl_error($ch) ." \n";        //  display error notice
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
  $Debug .= ".. HTTP stats: " .
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
    " secs \n";

  //$Debug .= ".. curl info\n".print_r($cinfo,true)." \n";
  curl_close($ch);                                              // close the cURL session
  //$Debug .= ".. raw data\n".$data."\n \n"; 
  $i = strpos($data,"\r\n\r\n");
  $headers = substr($data,0,$i);
  $content = substr($data,$i+4);
  if($cinfo['http_code'] <> '200') {
    $Debug .= ".. headers returned:\n".$headers."\n \n"; 
  }
  return $data;                                                 // return headers+contents

 } else {
//   print ".. using file_get_contents function \n";
   $STRopts = array(
	  'http'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (get-metar-metadata.php - saratoga-weather.org)\r\n" .
				"Accept: text/html,text/plain\r\n"
	  ),
	  'https'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (get-metar-metadata.php - saratoga-weather.org)\r\n" .
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
   $Debug .= ".. file_get_contents() stats: total=$ms_total secs \n";
   $Debug .= ".. get_headers returns\n".$theaders."\n \n";
//   print " file() stats: total=$ms_total secs.\n";
   $overall_end = time();
   $overall_elapsed =   $overall_end - $overall_start;
   $Debug .= ".. fetch function elapsed= $overall_elapsed secs. \n"; 
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
	
        
?>