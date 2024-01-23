<?php
error_reporting(E_ALL);
ini_set('display_errors','1');
#----------------------------------------------------------------------------
/*
  Script: get-aviation-metadata.php

	Purpose:   Purpose: read the METAR stations.xml file from Aviationweather.gov, parse entries and
	    create a metar-metadata-inc.php file with $METARS array with details about a METAR
      ICAO for use by other programs.
			Also optionally merge data from a new_station_data.txt file to update name,lat,long,elev
			info for stations not listed in the stations.txt file.

		
	Inputs; URL https://aviationweather.gov/data/cache/stations.cache.xml.gz
	        file metar-metadata-inc.php produced by get-metar-metadata.php program
					
	Output: metar-metadata-inc.php file with $metarMetadata array containing formatted data.
	        metar-location-raw.txt cache file
 
  Script by Ken True - webmaster@saratoga-weather.org

*/
#----------------------------------------------------------------------------
# Version 1.00 - 21-Jan-2024 - Initial Release
# -------------Settings ---------------------------------
  $cacheFileDir = './';      // default cache file directory
  $ourTZ = 'America/Los_Angeles';
  $cacheFileDir = './';      // default cache file directory
  $GMLcacheName = "metar-location-raw.txt";    // cache file for stations.txt
  $GMLoutputFile = "metar-metadata-inc.php";   // output file with $METARS array
  $GMLaddedFile  = "new_station_data.txt";     // optional override file for ICAO,name,lat,long,elev-ft 
	
// -------------End Settings -----------------------------
//

$GMLversion = 'get-aviation-metadata.php V1.00 - 21-Jan-2024 - saratoga-weather.org';
$XML_URL = 'https://aviationweather.gov/data/cache/stations.cache.xml.gz'; 
//
$XMLcacheName = $cacheFileDir.$GMLcacheName;
$outputFile    = $cacheFileDir.$GMLoutputFile;
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
header('Content-type: text/plain; charset=ISO-8859-1');

// --------- search for nearby metars ------------
  if (!function_exists('date_default_timezone_set')) {
	putenv("TZ=" . $ourTZ);
#	$Status .= "using putenv(\"TZ=$ourTZ\")\n";
    } else {
	date_default_timezone_set("$ourTZ");
#	$Status .= "using date_default_timezone_set(\"$ourTZ\")\n";
}

global $Debug,$metarMetadata,$missingLLE,$wxCodesSeen,$wxCodesMissing ;

$Debug = "$GMLversion\n";
$Debug .= "run on ".date('D, d-M-Y H:i:s T'). "\n";
$Debug .= "       ".gmdate('D, d-M-Y H:i:s T'). "\n";

$output = '';
$metars = array();
$missingLLE = array();

$rawGZ = GML_fetchUrlWithoutHanging($XML_URL);
# ---------------------------------------------------------------------------------
# note: new url of https://aviationweather.gov/data/cache/metars.cache.csv.gz 
# returns a truncated header of \x0c with curl.  grrr.
# we'll prepend a 'good header' for the gzip return to let the gzdecode work
# ---------------------------------------------------------------------------------
if(strlen($rawGZ) < 5000) {
	$Debug .= "--Oops.. insufficient data returned at ".date('Ymd-Hms')."\n";
	$Debug .= "--rawGZ returned ".strlen($rawGZ)." bytes.\n";
  print $Debug;
	file_put_contents($cacheFileDir.'log-'.date('Ymd').'.txt',"----------\n".$Debug,FILE_APPEND );
	exit();
}
$goodHeader = "\x1f\x8b\x08\x08"; # 4-byte GZ file header
if(substr($rawGZ,0,4) === $goodHeader) {# check for valid GZ header
  $Debug .= "..GZ file has good header\n";
	$rawXML = gzdecode($rawGZ);
} else {
  $Debug .= "--GZ file has missing header .. prepending it.\n";
  $rawXML = gzdecode($goodHeader.$rawGZ);
}
if($rawXML !== false) {
  $Debug .= "..GZ file was decompressed successfully.\n";
  file_put_contents($XMLcacheName,$rawXML);
	$Debug .= "..saved '$XMLcacheName' raw data file\n";
} else {
	file_put_contents($cacheFileDir.'aviation-stations.xml.gz.'.date('Ymd-His').'.txt',$rawGZ);
  $Debug .= "--saved bad gz file to '". $cacheFileDir.'aviation-stations.xml.gz.'.date('Ymd-His').'.txt'."'\n"; 
  print $Debug;
	exit();
}


if(strlen($rawXML) < 5000 ){
	$Debug .= "--Oops.. insufficient data returned from $XML_URL - aborting.\n";
	print $Debug;
	exit;
}


$XML = simplexml_load_string($rawXML);

if($XML==false) {
	$Debug .= "--error: unable to parse '$XMLcacheName'.\n";
	print $Debug;
	exit(0);
} 

$Debug .= "Processing XML for METAR station entries\n";

# 		$Name = (string)$xmlData["$riverid"]->observed->datum[0]->primary->attributes()->name;
$nStations = (string)$XML->data->attributes()->num_results;
$Debug .= ".. $nStations stations in response.\n";
$metars = array();

$nMetar = 0;
$nRejected = 0;
$missingSC = array();
global $missingSC;
foreach ($XML->data->Station as $i => $S) {
	#print "$i: ".var_export($S,true)."\n-----\n";
/*
\SimpleXMLElement::__set_state(array(
   'station_id' => 'KLOZ',
   'iata_id' => 'LOZ',
   'faa_id' => 'LOZ',
   'latitude' => '37.0896',
   'longitude' => '-84.0688',
   'elevation_m' => '361',
   'site' => 'London-Corbin Arpt',
   'state' => 'KY',
   'country' => 'US',
   'site_type' => 
  \SimpleXMLElement::__set_state(array(
     'METAR' => 
    \SimpleXMLElement::__set_state(array(
    )),
     'TAF' => 
    \SimpleXMLElement::__set_state(array(
    )),
  )),

*/
  $ICAO = (string)$S->station_id;
  $LAT  = (string)$S->latitude;
  $LON = (string)$S->longitude;
  $ELEV = round((integer)$S->elevation_m * 3.28084,0);
  $NAME = (string)$S->site;
  $STATE = (string)$S->state;
  $COUNTRY = (string)$S->country;
	$CSCODE = "$COUNTRY:$STATE";
	$tWhere = get_country($CSCODE);
	if(isset($S->site_type)) {
		$t = array();
		foreach($S->site_type as $T) {
			foreach ($T as $k => $v) {
			  #print " k=$k v=$v\n";
				$t[] = $k;
		  }
			#print " ... \n".var_export($type,true)."\n  ...\n"; 
		}
		$TYPE = join(',',$t);
	} else {
		$TYPE = 'n/a';
	}
	
	if(strpos($TYPE,'METAR') !== false) {
		#  'KLOT' => 'KLOT|Romeoville/Chi, Illinois, USA|41.6000|-88.1000|673|USA - ILLINOIS|ROMEOVILLE/CHI|',
		$M = join('|',array($ICAO,"$NAME, $tWhere",$LAT,$LON,$ELEV,$TYPE,$CSCODE));
		if($ELEV > '32000') {
			$Debug .= "  Rejected '$M' invalid data.\n";
			$nRejected++;
		} else {
		  $mISO = @iconv('UTF-8','ISO-8859-1//TRANSLIT',$M);
			if($mISO == false) {
				$mISO = $M.'|BAD-ICONV';
			}
	    $metars[$ICAO] = $mISO;
		  $nMetar++;
		}
	}

}
$Debug .= ".. $nMetar METAR stations found.\n";	
$Debug .= "   $nRejected METAR records rejected due to invalid data.\n";

if(file_exists($GMLaddedFile)) {
	$Debug .= "..Loading updates from $GMLaddedFile.\n\n";
	$addRecs = file($GMLaddedFile);
	$recCount= 0;
	foreach ($addRecs as $i => $rec) {
		if(substr_count($rec,',') < 4) { 
		  $Debug .= " $i='$rec' ignored.\n";
			continue;
		}

		list($mICAO,$mNiceName,$mLat,$mLon,$mElevFeet) = explode(',',trim($rec));
		$mCountryState = '';
		$mName='';
		if(!isset($metars[$mICAO])) {
			$Debug .= " '$mICAO' $mNiceName at $mLat,$mLon at $mElevFeet was added.\n";
			$mType = 'METAR';
			$mCScode = '--:--';
	    $metars["$mICAO"] =  "$mICAO|$mNiceName|$mLat|$mLon|$mElevFeet|$mType|$mCScode|";
	    $recCount++;
		} else {
			/*
			list($oICAO,$oNiceName,$oLat,$oLon,$oElevFeet,$mType,$mCScode) = explode("|",$metars[$mICAO]);
			if(strlen($mNiceName) < 1) {$mNiceName = $oNiceName;}
			if(strlen($mLat) < 1)      {$mLat = $oLat;}
			if(strlen($mLon) < 1)      {$mLon = $oLon;}
			if(strlen($mElevFeet) < 1)      {$mElevFeet = $oElevFeet;}
			$Debug .= " '$mICAO' $mNiceName at $mLat,$mLon elev. $mElevFeet ft was updated.\n";
			*/
			$Debug .= " '$mICAO' (".$metars[$mICAO].") exists. Ignored replacement.\n";
		}
	}
	$Debug .= "\n.. added info for $recCount entries in $GMLaddedFile.\n";
	$Debug .= ".. ".count($metars)." METARs total.\n";
}


ksort($metars);

file_put_contents($outputFile,
  "<?php \n" .
	"# generated by $GMLversion\n" .
	"# https://github.com/ktrue/metar-placefile by Saratoga-Weather.org\n" .
	"# run on: ".gmdate('D, d-M-Y H:i:s T')."\n".
	"# php version ".phpversion()."\n".
	"# \n" .
	"# raw data in '$XMLcacheName'\n".
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
	"\$metarMetadata = ".var_export($metars,true).";\n");
$Debug .= "..file $outputFile saved.\n";

#file_put_contents("tgftp.txt",$output);
#Debug .= "file tgftp.txt saved with ".strlen($output)." bytes.\n";

$Debug .= "\n\nfinished processing\n";
if(count($missingLLE) > 0) {
	ksort($missingLLE);
  $missinglist = wordwrap(join(' ',$missingLLE));
 $Debug .= "Missing lat/long/name/elevation for:\n";
 $Debug .= "\n$missinglist\n";
 $Debug .= "\n.. ".count($missingLLE)." METARs are reporting w/o metadata available.\n";
}

print $Debug;
print ".. found ".count($metars)." METAR records in $outputFile \$metarMetadata array.\n";

print ".. ".count($missingSC). " entries with state names missing saved to aviation-metadata-missing.txt.\n";
ksort($missingSC);
file_put_contents('aviation-metadata-missing.txt',var_export($missingSC,true));

// ----------------------------functions ----------------------------------- 


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
  $Debug .= "..curl fetching '$theURL'\n";
  $ch = curl_init();                                           // initialize a cURL session
  curl_setopt($ch, CURLOPT_URL, $theURL);                         // connect to provided URL
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);                 // don't verify peer certificate
  curl_setopt($ch, CURLOPT_USERAGENT, 
    'Mozilla/5.0 (get-aviation-metadata.php - saratoga-weather.org)');

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
    $Debug .=  "cookie used '" . $needCookie[$domain] . "' for GET to $domain\n";
  }

  $data = curl_exec($ch);                                      // execute session

  if(curl_error($ch) <> '') {                                  // IF there is an error
   $Debug .= "--curl Error: ". curl_error($ch) ."\n";        //  display error notice
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
  $Debug .= " HTTP stats: " .
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
    " secs\n";

  //$Debug .= "curl info\n".print_r($cinfo,true)."\n";
  curl_close($ch);                                              // close the cURL session
  //$Debug .= "raw data\n".$data."\n\n"; 
  $i = strpos($data,"\r\n\r\n");
  $headers = substr($data,0,$i);
  $content = substr($data,$i+4);
  if($cinfo['http_code'] <> '200') {
    $Debug .= " headers returned:\n".$headers."\n\n"; 
  }
  return $content;                                                 // return headers+contents

 } else {
//   print "using file_get_contents function\n";
   $STRopts = array(
	  'http'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (get-aviation-metadata.php - saratoga-weather.org)\r\n" .
				"Accept: text/html,text/plain\r\n"
	  ),
	  'ssl'=>array(
	  'method'=>"GET",
	  'protocol_version' => 1.1,
	  'header'=>"Cache-Control: no-cache, must-revalidate\r\n" .
				"Cache-control: max-age=0\r\n" .
				"Connection: close\r\n" .
				"User-agent: Mozilla/5.0 (get-aviation-metadata.php - saratoga-weather.org)\r\n" .
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
   $Debug .= "file_get_contents() stats: total=$ms_total secs\n";
   $Debug .= " get_headers returns\n".$theaders."\n\n";
//   print " file() stats: total=$ms_total secs.\n";
   $overall_end = time();
   $overall_elapsed =   $overall_end - $overall_start;
   $Debug .= "fetch function elapsed= $overall_elapsed secs.\n"; 
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

// ------------------------------------------------------------------
   
function get_country($code) {
	global $missingSC;
	static $COUNTRIES = array (
  'AD' => 'Andorra',
  'AE' => 'United Arab Emirates',
  'AF' => 'Afghanistan',
  'AG' => 'Antigua and Barbuda',
  'AI' => 'Anguilla',
  'AL' => 'Albania',
  'AM' => 'Armenia',
  'AO' => 'Angola',
  'AQ' => 'Antarctica',
  'AR' => 'Argentina',
  'AS' => 'American Samoa',
  'AT' => 'Austria',
  'AU' => 'Australia',
  'AU:QL' => 'Queensland, Australia',
	'AU:NS' => 'New South Wales, Australia', #state
	'AU:SA' => 'South Australia, Australia', #state
	'AU:TA' => 'Tasmania, Australia', #state
	'AU:VI' => 'Victoria, Australia', #state
	'AU:WA' => 'Western Australia, Australia', #state
	'AU:AC' => 'ACT, Australia', #territory
	'AU:NT' => 'Northern Territory, Australia', #territory
	'AW' => 'Aruba',
  'AX' => 'Åland Islands',
  'AZ' => 'Azerbaijan',
  'BA' => 'Bosnia and Herzegovina',
  'BB' => 'Barbados',
  'BD' => 'Bangladesh',
  'BE' => 'Belgium',
  'BF' => 'Burkina Faso',
  'BG' => 'Bulgaria',
  'BH' => 'Bahrain',
  'BI' => 'Burundi',
  'BJ' => 'Benin',
  'BL' => 'Saint Barthelemy',
  'BM' => 'Bermuda',
  'BN' => 'Brunei Darussalam',
  'BO' => 'Bolivia',
  'BQ' => 'Bonaire, Sint Eustatius and Saba',
  'BR' => 'Brazil',
  'BS' => 'Bahamas',
  'BT' => 'Bhutan',
  'BV' => 'Bouvet Island',
  'BW' => 'Botswana',
  'BY' => 'Belarus',
  'BZ' => 'Belize',
  'CA' => 'Canada',
  'CA:AB' => 'Alberta, Canada',
  'CA:BC' => 'British Columbia, Canada',
  'CA:MB' => 'Manitoba, Canada',
  'CA:NB' => 'New Brunswick, Canada',
  'CA:NL' => 'Newfoundland, Canada',
  'CA:NS' => 'Nova Scotia, Canada',
  'CA:NT' => 'N.W. Territories, Canada',
  'CA:NU' => 'Nunavut, Canada',
  'CA:PE' => 'Prince Edward Isl, Canada',
  'CA:QC' => 'Ontario, Canada',
  'CA:QC' => 'Quebec, Canada',
  'CA:SK' => 'Saskatchewan, Canada',
  'CA:YT' => 'Yukon Territory, Canada',
  'CC' => 'Cocos (Keeling) Islands',
  'CD' => 'Congo',
  'CF' => 'Central African Republic',
  'CG' => 'Congo',
  'CH' => 'Switzerland',
  'CI' => 'Cote d\'Ivoire',
  'CK' => 'Cook Islands',
  'CL' => 'Chile',
  'CM' => 'Cameroon',
  'CN' => 'China',
  'CO' => 'Colombia',
  'CR' => 'Costa Rica',
  'CU' => 'Cuba',
	'CU:15' => 'Artemisa, Cuba', #province
	'CU:09' => 'Camaguey, Cuba', #province
	'CU:08' => 'Ciego de Avila, Cuba', #province
	'CU:06' => 'Cienfuegos, Cuba', #province
	'CU:12' => 'Granma, Cuba', #province
	'CU:14' => 'Guantanamo, Cuba', #province
	'CU:11' => 'Holguín, Cuba', #province
	'CU:03' => 'La Habana, Cuba', #province
	'CU:10' => 'Las Tunas, Cuba', #province
	'CU:04' => 'Matanzas, Cuba', #province
	'CU:16' => 'Mayabeque, Cuba', #province
	'CU:01' => 'Pinar del Rio, Cuba', #province
	'CU:07' => 'Sancti Spíritus, Cuba', #province
	'CU:13' => 'Santiago de Cuba, Cuba', #province
	'CU:05' => 'Villa Clara, Cuba', #province
	'CU:99' => 'Isla de la Juventud, Cuba', #special municipality 
  'CV' => 'Cabo Verde',
  'CW' => 'Curacao',
  'CX' => 'Christmas Island',
  'CY' => 'Cyprus',
  'CZ' => 'Czechia',
  'DE' => 'Germany',
  'DJ' => 'Djibouti',
  'DK' => 'Denmark',
  'DM' => 'Dominica',
  'DO' => 'Dominican Republic',
  'DZ' => 'Algeria',
  'EC' => 'Ecuador',
  'EE' => 'Estonia',
  'EG' => 'Egypt',
  'EH' => 'Western Sahara*',
  'ER' => 'Eritrea',
  'ES' => 'Spain',
  'ET' => 'Ethiopia',
  'FI' => 'Finland',
  'FJ' => 'Fiji',
  'FK' => 'Falkland Islands',
  'FM' => 'Micronesia',
  'FO' => 'Faroe Islands',
  'FR' => 'France',
  'GA' => 'Gabon',
  'GB' => 'United Kingdom',
  'GD' => 'Grenada',
  'GE' => 'Georgia',
  'GF' => 'French Guiana',
  'GG' => 'Guernsey',
  'GH' => 'Ghana',
  'GI' => 'Gibraltar',
  'GL' => 'Greenland',
  'GM' => 'Gambia',
  'GN' => 'Guinea',
  'GP' => 'Guadeloupe',
  'GQ' => 'Equatorial Guinea',
  'GR' => 'Greece',
  'GS' => 'South Georgia/South Sandwich Islands',
  'GT' => 'Guatemala',
  'GU' => 'Guam',
  'GW' => 'Guinea-Bissau',
  'GY' => 'Guyana',
  'HK' => 'Hong Kong',
  'HM' => 'Heard Island/McDonald Islands',
  'HN' => 'Honduras',
  'HR' => 'Croatia',
  'HT' => 'Haiti',
  'HU' => 'Hungary',
  'ID' => 'Indonesia',
  'IE' => 'Ireland',
  'IL' => 'Israel',
  'IM' => 'Isle of Man',
  'IN' => 'India',
  'IO' => 'British Indian Ocean Territory',
  'IQ' => 'Iraq',
  'IR' => 'Iran',
  'IS' => 'Iceland',
  'IT' => 'Italy',
  'JE' => 'Jersey',
  'JM' => 'Jamaica',
  'JO' => 'Jordan',
  'JP' => 'Japan',
  'KE' => 'Kenya',
  'KG' => 'Kyrgyzstan',
  'KH' => 'Cambodia',
  'KI' => 'Kiribati',
  'KM' => 'Comoros',
  'KN' => 'Saint Kitts and Nevis',
  'KP' => 'North Korea',
  'KR' => 'South Korea',
  'KW' => 'Kuwait',
  'KY' => 'Cayman Islands',
  'KZ' => 'Kazakhstan',
  'LA' => 'Laos',
  'LB' => 'Lebanon',
  'LC' => 'Saint Lucia',
  'LI' => 'Liechtenstein',
  'LK' => 'Sri Lanka',
  'LR' => 'Liberia',
  'LS' => 'Lesotho',
  'LT' => 'Lithuania',
  'LU' => 'Luxembourg',
  'LV' => 'Latvia',
  'LY' => 'Libya',
  'MA' => 'Morocco',
  'MC' => 'Monaco',
  'MD' => 'Moldova',
  'ME' => 'Montenegro',
  'MF' => 'Saint Martin (French part)',
  'MG' => 'Madagascar',
  'MH' => 'Marshall Islands',
  'MK' => 'North Macedonia',
  'ML' => 'Mali',
  'MM' => 'Myanmar',
  'MN' => 'Mongolia',
  'MO' => 'Macao',
  'MP' => 'Northern Mariana Islands',
  'MQ' => 'Martinique',
  'MR' => 'Mauritania',
  'MS' => 'Montserrat',
  'MT' => 'Malta',
  'MU' => 'Mauritius',
  'MV' => 'Maldives',
  'MW' => 'Malawi',
  'MX' => 'Mexico',
  'MY' => 'Malaysia',
  'MZ' => 'Mozambique',
  'NA' => 'Namibia',
  'NC' => 'New Caledonia',
  'NE' => 'Niger',
  'NF' => 'Norfolk Island',
  'NG' => 'Nigeria',
  'NI' => 'Nicaragua',
  'NL' => 'Netherlands',
  'NO' => 'Norway',
  'NP' => 'Nepal',
  'NR' => 'Nauru',
  'NU' => 'Niue',
  'NZ' => 'New Zealand',
	'NZ:AU' => 'Auckland', #Tamaki-Makaurau 	region
	'NZ:BO' => 'Bay of Plenty', #Toi Moana 	region
	'NZ:CA' => 'Canterbury', #Waitaha 	region
	'NZ:CI' => 'Chatham Islands Territory, New Zealand', #Wharekauri 	special island authority
	'NZ:GI' => 'Gisborne, New Zealand', #Te Tairawhiti 	region
	'NZ:WG' => 'Greater Wellington, New Zealand', #Te Pane Matua Taiao 	region
	'NZ:HB' => 'Hawke\'s Bay, New Zealand', #Te Matau-a-Maui 	region
	'NZ:MW' => 'Manawatu-Whanganui, New Zealand', #Manawatu Whanganui 	region
	'NZ:MB' => 'Marlborough, New Zealand', #	region
	'NZ:NS' => 'Nelson, New Zealand', #Whakatu 	region
	'NZ:NL' => 'Northland, New Zealand', #Te Tai tokerau 	region
	'NZ:OT' => 'Otago, New Zealand', #O Takou 	region
	'NZ:ST' => 'Southland, New Zealand', #Te Taiao Tonga 	region
	'NZ:TK' => 'Taranaki, New Zealand', #Taranaki 	region
	'NZ:TS' => 'Tasman, New Zealand', #Te tai o Aorere 	region
	'NZ:WK' => 'Waikato, New Zealand', #Waikato 	region
	'NZ:WT' => 'West Coast, New Zealand', #Te Tai o Poutini 	region 
  'OM' => 'Oman',
  'PA' => 'Panama',
  'PE' => 'Peru',
  'PF' => 'French Polynesia',
  'PG' => 'Papua New Guinea',
  'PH' => 'Philippines',
  'PK' => 'Pakistan',
  'PL' => 'Poland',
  'PM' => 'Saint Pierre and Miquelon',
  'PN' => 'Pitcairn',
  'PR' => 'Puerto Rico',
  'PS' => 'Palestine',
  'PT' => 'Portugal',
  'PW' => 'Palau',
  'PY' => 'Paraguay',
  'QA' => 'Qatar',
  'RE' => 'Reunion',
  'RO' => 'Romania',
  'RS' => 'Serbia',
  'RU' => 'Russian Federation',
  'RW' => 'Rwanda',
  'SA' => 'Saudi Arabia',
  'SB' => 'Solomon Islands',
  'SC' => 'Seychelles',
  'SD' => 'Sudan',
  'SE' => 'Sweden',
  'SG' => 'Singapore',
  'SH' => 'Saint Helena, Ascension and Tristan da Cunha',
  'SI' => 'Slovenia',
  'SJ' => 'Svalbard/Jan Mayen',
  'SK' => 'Slovakia',
  'SL' => 'Sierra Leone',
  'SM' => 'San Marino',
  'SN' => 'Senegal',
  'SO' => 'Somalia',
  'SR' => 'Suriname',
  'SS' => 'South Sudan',
  'ST' => 'Sao Tome and Principe',
  'SV' => 'El Salvador',
  'SX' => 'Sint Maarten (Dutch part)',
  'SY' => 'Syria',
  'SZ' => 'Eswatini',
  'TC' => 'Turks/Caicos Islands',
  'TD' => 'Chad',
  'TF' => 'French Southern Territories',
  'TG' => 'Togo',
  'TH' => 'Thailand',
  'TJ' => 'Tajikistan',
  'TK' => 'Tokelau',
  'TL' => 'Timor-Leste',
  'TM' => 'Turkmenistan',
  'TN' => 'Tunisia',
  'TO' => 'Tonga',
  'TR' => 'Turkey',
  'TT' => 'Trinidad and Tobago',
  'TV' => 'Tuvalu',
  'TW' => 'Taiwan',
  'TZ' => 'Tanzania',
  'UA' => 'Ukraine',
  'UG' => 'Uganda',
  'UM' => 'United States Minor Outlying Islands',
  'US' => 'USA',
  'US:AK' => 'Alaska, USA',
  'US:AL' => 'Alabama, USA',
  'US:AR' => 'Arkansas, USA',
  'US:AZ' => 'Arizona, USA',
  'US:CA' => 'California, USA',
  'US:CO' => 'Colorado, USA',
  'US:CT' => 'Connecticut, USA',
  'US:DC' => 'Washington D.c., USA',
  'US:DE' => 'Delaware, USA',
  'US:FL' => 'Florida, USA',
  'US:GA' => 'Georgia, USA',
  'US:HI' => 'Hawaii, USA',
  'US:IA' => 'Iowa, USA',
  'US:ID' => 'Idaho, USA',
  'US:IL' => 'Illinois, USA',
  'US:IN' => 'Indiana, USA',
  'US:KS' => 'Kansas, USA',
  'US:KY' => 'Kentucky, USA',
  'US:LA' => 'Louisiana, USA',
  'US:MA' => 'Massachusetts, USA',
  'US:MD' => 'Maryland, USA',
  'US:ME' => 'Maine, USA',
  'US:MH' => 'Marshall Islands, USA',
  'US:MI' => 'Michigan, USA',
  'US:MN' => 'Minnesota, USA',
  'US:MO' => 'Missouri, USA',
  'US:MS' => 'Mississippi, USA',
  'US:MT' => 'Montana, USA',
  'US:NC' => 'North Carolina, USA',
  'US:ND' => 'North Dakota, USA',
  'US:NE' => 'Nebraska, USA',
  'US:NH' => 'New Hampshire, USA',
  'US:NJ' => 'New Jersey, USA',
  'US:NM' => 'New Mexico, USA',
  'US:NV' => 'Nevada, USA',
  'US:NY' => 'New York, USA',
  'US:OH' => 'Ohio, USA',
  'US:OK' => 'Oklahoma, USA',
  'US:OR' => 'Oregon, USA',
  'US:PA' => 'Pennsylvania, USA',
  'US:RI' => 'Rhode Island, USA',
  'US:SC' => 'South Carolina, USA',
  'US:SD' => 'South Dakota, USA',
  'US:TN' => 'Tennessee, USA',
  'US:TX' => 'Texas, USA',
  'US:UM' => 'Guam, USA',
  'US:UM' => 'Johnston/Wake/Xmas, USA',
  'US:UT' => 'Utah, USA',
  'US:VA' => 'Virginia, USA',
  'US:VT' => 'Vermont, USA',
  'US:WA' => 'Washington, USA',
  'US:WI' => 'Wisconsin, USA',
  'US:WV' => 'West Virginia, USA',
  'US:WY' => 'Wyoming, USA',
	'US:14' => 'Cuba, USA',
  'UY' => 'Uruguay',
  'UZ' => 'Uzbekistan',
  'VA' => 'Holy See',
  'VC' => 'Saint Vincent and the Grenadines',
  'VE' => 'Venezuela',
  'VG' => 'Virgin Islands (British)',
  'VI' => 'Virgin Islands (U.S.)',
  'VN' => 'Viet Nam',
  'VU' => 'Vanuatu',
  'WF' => 'Wallis and Futuna',
  'WS' => 'Samoa',
  'YE' => 'Yemen',
  'YT' => 'Mayotte',
  'ZA' => 'South Africa',
  'ZM' => 'Zambia',
  'ZW' => 'Zimbabwe',
);

 if(strpos($code,':') !== false) {
	 list($tCountry,$tState) = explode(':',$code);
 } else {
   $tCountry = $code; 
	 $tState = '';
 }
 
 
 if(isset($COUNTRIES[$code])) { // cc:ss format found
	 return($COUNTRIES[$code]);
 } elseif (isset($COUNTRIES[$tCountry]) ) {

	 if(strpos($tState,'-') !== false) { 
	   return($COUNTRIES[$tCountry]);
	 } else {
		 $tC = "$tCountry:$tState|".$COUNTRIES[$tCountry];
		 if(!isset($missingSC[$tC])) {
			 $missingSC[$tC] = 1;
		 } else {
			 $missingSC[$tC]++;
		 }
		 return("$tState, ".$COUNTRIES[$tCountry]);
	 }
 } else {
	 return ($code);
 }
 
}
