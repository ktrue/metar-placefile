<?php
/*
  Purpose: lookup the iconnumber to use for METAR weather codes via function
	      pick_cond_icon($codes);
	The $codes contains comma separated string of weather codes (i.e 'SHRA,SHSN,BR')
	
	Returns an iconcode for the cloudcover_new.png icon file used by metar-placefile.php
	
	Used by get-aviation-metars.php and metar-placefile.php

Version 1.00 - 30-Jun-2023 - initial release
Version 1.01 - 01-Jul-2023 - added 'OVX' for Vertical Visibility
Version 1.02 - 03-Jul-2023 - added SA, DU, SS, DS for Sand/Dust
Version 1.03 - 27-Nov-2023 - added IC for Ice Crystals

*/
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

#---------------------------------------------------------------------------

function pick_cond_icon($codes) {
/*
  Purpose: pick an icon number from the available ones in cloudcover_new.png based on
	         the order of most-severe to least-severe weather reported in $M['codes']
					 METAR conditions/sky cover codes.
					 
	Order of severity:
	
	 tornado/waterspout
   thunder (in any form)
   Ice / Snow/ Freezing / Sleet / Hail
   Rain/Drizzle
   Fog
   Haze/Smoke/Dust/Volcano
   cloud cover


*/

static $condIcons = array(
	# array is in order of decreasing severity
	#
	# tornado/hurricane
	'FC' => 42,
	'+FC' => 42,
	'HU' => 55,
	
	#  thunder (in any form)
	'+TSRA' => 32,		
	'TSRA' => 33,
	'TSSN' => 34,
  '-TSPL' => 29, // Light Thunderstorm Ice Pellets 
  '+TSPL' => 31, // Heavy Thunderstorm Ice Pellets 
  'TSPL' => 30, // Moderate Thunderstorm Ice Pellets 

 '-TSGR' => 29, // Light Thunderstorm Hail 
 '+TSGR' => 31, // Heavy Thunderstorm Hail 
 'TSGR' => 30, // Moderate Thunderstorm Hail 

 '-TSGS' => 29, // Light Thunderstorm Small Hail 
 '+TSGS' => 31, // Heavy Thunderstorm Small Hail 
 'TSGS' => 30, // Moderate Thunderstorm Small Hail 

 '-TSSG' => 13, // Light Thunderstorm Snow Grains 
 '+TSSG' => 15, // Heavy Thunderstorm Snow Grains 
 'TSSG' => 14, // Moderate Thunderstorm Snow Grains 

 '-VCTSPL' => 29, // Light Nearby Thunderstorm Ice Pellets 
 '+VCTSPL' => 31, // Heavy Nearby Thunderstorm Ice Pellets 
 'VCTSPL' => 30, // Moderate Nearby Thunderstorm Ice Pellets 

 '-VCTSGR' => 29, // Light Nearby Thunderstorm Hail 
 '+VCTSGR' => 31, // Heavy Nearby Thunderstorm Hail 
 'VCTSGR' => 30, // Moderate Nearby Thunderstorm Hail 

 '-VCTSGS' => 29, // Light Nearby Thunderstorm Small Hail 
 '+VCTSGS' => 31, // Heavy Nearby Thunderstorm Small Hail 
 'VCTSGS' => 30, // Moderate Nearby Thunderstorm Small Hail 

 '-VCTSSG' => 13, // Light Nearby Thunderstorm Snow Grains 
 '+VCTSSG' => 15, // Heavy Nearby Thunderstorm Snow Grains 
 'VCTSSG' => 14, // Moderate Nearby Thunderstorm Snow Grains 

	'VCTS' => 28,		
	'TS' => 28,		
	'TCU' => 63,
	'SQ' => 33, # 'Squalls',
	
	
	#   Ice / Snow/ Freezing / Sleet /
 '-VCSHPL' => 29, // Light Nearby Showers Ice Pellets 
 '+VCSHPL' => 31, // Heavy Nearby Showers Ice Pellets 
 'VCSHPL' => 30, // Moderate Nearby Showers Ice Pellets 

 '-VCSHGR' => 29, // Light Nearby Showers Hail 
 '+VCSHGR' => 31, // Heavy Nearby Showers Hail 
 'VCSHGR' => 30, // Moderate Nearby Showers Hail 

 '-VCSHGS' => 29, // Light Nearby Showers Small Hail 
 '+VCSHGS' => 31, // Heavy Nearby Showers Small Hail 
 'VCSHGS' => 30, // Moderate Nearby Showers Small Hail 

 '-VCSHSG' => 13, // Light Nearby Showers Snow Grains 
 '+VCSHSG' => 15, // Heavy Nearby Showers Snow Grains 
 'VCSHSG' => 14, // Moderate Nearby Showers Snow Grains 

 '-SHPL' => 29, // Light Showers Ice Pellets 
 '+SHPL' => 31, // Heavy Showers Ice Pellets 
 'SHPL' => 30, // Moderate Showers Ice Pellets 

 '-SHGR' => 29, // Light Showers Hail 
 '+SHGR' => 31, // Heavy Showers Hail 
 'SHGR' => 30, // Moderate Showers Hail 

 '-SHGS' => 29, // Light Showers Small Hail 
 '+SHGS' => 31, // Heavy Showers Small Hail 
 'SHGS' => 30, // Moderate Showers Small Hail 

 '-SHSG' => 13, // Light Showers Snow Grains 
 '+SHSG' => 15, // Heavy Showers Snow Grains 
 'SHSG' => 14, // Moderate Showers Snow Grains 

 '-PL' => 29, // Light Ice Pellets 
 '+PL' => 31, // Heavy Ice Pellets 
 'PL' => 30, // Moderate Ice Pellets 

 '-GR' => 29, // Light Hail 
 '+GR' => 31, // Heavy Hail 
 'GR' => 30, // Moderate Hail 

 '-GS' => 29, // Light Small Hail 
 '+GS' => 31, // Heavy Small Hail 
 'GS' => 30, // Moderate Small Hail 

 '-SG' => 13, // Light Snow Grains 
 '+SG' => 15, // Heavy Snow Grains 
 'SG' => 14, // Moderate Snow Grains 

	'PLFZRA' => 20,
	'FZRAPL' => 20,
	
	'SNPL' => 18,
	'PLRA' => 19,
	'RAPL' => 19,
	'SNFZRA' => 21,
	'FZRASN' => 21,
	
	'+FZRA' => 24,
	'-FZRA' => 22,
	'FZRA' => 23,
	
	'+FZDZ' => 27,
	'-FZDZ' => 25,
	'FZDZ' => 26,
	
	'+FZFG' => 39,
	'-FZFG' => 39,
	'FZFG' => 39,

	'+IC'  => 39, // ice crystals
	'-IC'  => 39,
	'IC'   => 39,
	
	'SNSH' => 16,
	'SHSN' => 16,
	'BLSN' => 41,
	
	'SNRA' => 17,
	'RASN' => 17,

	'+SN' => 15,
	'-SN' => 13,
	'SN' => 14,
	
	#   Rain
	'RASH' => 17,
	'SHRA' => 17,
	'+RA' => 8,
	'-RA' => 6,
	'RA' => 7,
	'+DZ' => 11,
	'-DZ' => 9,
	'DZ' => 10,
	
	'VCSH' => 12,		
	'+BR' => 37,
	'-BR' => 37,
	'BR' => 37,
	
	#   Fog
	'MIFG' => 38,
	'PRFG' => 38,
	'BCFG' => 38,
	'DRFG' => 38,
	'FG' => 38,
	
	#   Haze/Smoke/Dust/Volcano
	'-BLSA' => 70, // Light Blowing Sand in air 
	'+BLSA' => 70, // Heavy Blowing Sand in air 
	'BLSA' => 70, // Moderate Blowing Sand in air 
	'-DRSA' => 70, // Light Drifting Sand in air 
	'+DRSA' => 70, // Heavy Drifting Sand in air 
	'DRSA' => 70, // Moderate Drifting Sand in air 
	'-SA' => 70, // Light Sand in air 
	'+SA' => 70, // Heavy Sand in air 
	'SA' => 70, // Moderate Sand in air 
	
	'-BLDU' => 69, // Light Blowing Dust in air 
	'+BLDU' => 69, // Heavy Blowing Dust in air 
	'BLDU' => 69, // Moderate Blowing Dust in air 
	'-DRDU' => 69, // Light Drifting Dust in air 
	'+DRDU' => 69, // Heavy Drifting Dust in air 
	'DRDU' => 69, // Moderate Drifting Dust in air 
	'-DU' => 69, // Light Dust in air 
	'+DU' => 69, // Heavy Dust in air 
	'DU' => 69, // Moderate Dust in air 
	
	'-SS' => 72, // Light Sandstorm 
	'+SS' => 72, // Heavy Sandstorm 
	'SS' => 72, // Moderate Sandstorm 
	
	'-DS' => 71, // Light Duststorm 
	'+DS' => 71, // Heavy Duststorm 
	'DS' => 71, // Moderate Duststorm 

	'FU' => 35,
	'HZ' => 36,
	'VA' => 66,
	'PY' => 61, # 'Spray',
	'PO' => 42, # 'Well-developed Dust/Sand Whirls',
	'UP' => 43,
	
	#  cloud cover
	'VV' => 68,
	'OVX' => 68,
	'OVC' => 1, 
	'BKN' => 2, 
	'SCT' => 3, 
	'FEW' => 4,
	'SKC' => 5,
	'CLR' => 5,
	#
	# not supported currently
	#
	'Winds >=35'  => 51,
	'Winds >=58' => 52,
	'VFR' => 45,
	'MVFR' => 46,
	'IFR' => 47,
	'LIFR' => 48,		
	'PRESFR' => 49,
	'PRESRR' => 50,
	'MISSING' => 40,
	'ALERT' => 64,
	'LIGHTNING' => 67,
);

/*
$conditions = array(
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
*/
	$iconnumb = -1;
	$tcond = ','.$codes;
	
	foreach($condIcons as $test => $num) {
		if(strpos($tcond,$test) > 0) {
			$iconnumb = $num;
			break;
		}
	}
	
	return $iconnumb;
}
