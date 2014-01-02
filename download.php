<?php
/* Copyright (c) 2013, Liam Fraser <liam@liamfraser.co.uk>
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Liam Fraser nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL LIAM FRASER BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/* Load balancing system for downloads.raspberrypi.org
 * Created by Liam Fraser */

// Before we do anything else, we need to block XSS attacks!

// Get a list of the allowed file paths into an array
$allowedDownloads = array(
"/images/debian/6/debian6-17-02-2012/debian6-17-02-2012.zip"
// and so on...
);
 
// Get a list of allowed continent values (null is included because it can be blank), note that CDN is used but not passed as a variable
$allowedContinentValues = array(null, "AS", "EU", "SA", "AF", "AN", "OC", "NA", "manual");

// Die if one of our variable values are not allowed
if( !in_array($_GET["continent"], $allowedContinentValues) || !in_array($_GET["file"], $allowedDownloads))
{
	die("Invalid Request");
}
 
// Define a mirror class
class mirror
{
	// The path to the servers raspberry pi root
	private $uriRoot = "";
	
	// Getter for our uri variable
	public function __get($uri)
	{
		/* Return the servers root with the file uri trimming the
		 * forward slash from the file uri */
		return $this->uriRoot . substr($_GET["file"], 1);
	}
	
	// A metric calculated from bandwidth
	public $metric = 0;
	
	// A continent code for the mirror
	public $continentCode;
	
	// HTML code for the logo that the mirror has provided
	public $mirrorLogo;
	
	// Text that the mirror wants to display - can be html
	public $mirrorText = "(The mirror did not provide information.)";
	
	// Constructor for our mirror class
	public function __construct($uriRoot, $continentCode, $metric, $mirrorText = "(The mirror did not provide information.)", $mirrorLogo = null)
	{
		$this->uriRoot = $uriRoot;
		$this->metric = $metric;
		$this->continentCode = $continentCode;
		$this->mirrorText = $mirrorText;
		$this->mirrorLogo = $mirrorLogo;
	}
}

// Declare mirrors - uriRoot, Continent code, metric, mirror text, mirror logo code
$mirrorList = array(
new mirror("http://raspi.example.org/", "NA", 10, "Mirror Provided by Example", "<a href=\"http://example.org\" target=\"_blank\"><img src=\"/img/logos/examples.png\" alt=\"Mirror Logo\"></a>")
// and so on...
);

// Declare a map of continents with their two character code as the key
$continents = array(
"AS" => "Asia",
"EU" => "Europe",
"SA" => "South America",
"AF" => "Africa",
"AN" => "Antartica",
"OC" => "Oceania",
"NA" => "North America"
);

// Get the number of mirrors
$numberOfMirrors = sizeof($mirrorList);

// Declare an array with only the continents we have mirrors in
$mirrorContinents = array();

// Create a count for our while loop
$count = 0;

// Declare a bool so we know if we have a CDN or not
$haveCDN = false;

// While have CDN is false and we have not been through each mirror
while ($haveCDN == false && $count < $numberOfMirrors)
{
	// Go through the mirror list and add each continent we have a mirror in
	foreach ($mirrorList as $mirror)
	{
		// If our mirror is not a CDN
		if ($mirror->continentCode != "CDN")
		{
			// If the array does not already contain the continent code of this mirror, add it
			if (!array_key_exists($mirror->continentCode, $mirrorContinents))
			{
				$mirrorContinents[$mirror->continentCode] = $continents[$mirror->continentCode];
			}
		}
		else
		{
			// Set a variable indicating we have an active CDN network
			$haveCDN = true;
		}
	}
		
	// Increment the count
	$count++;
}

/* If we have a CDN network, then we want to list all continents, so set mirror continents to the array
 * containing a list of all the continents */
$mirrorContinents = $continents;

// A function to echo continent hyperlinks
function printMirrorContinents()
{	
	// We need to use the global keyword to access the mirrorContinents array from this function
	global $mirrorContinents;
	
	echo "<p>We currently have mirrors in the following continents:</p>";
	
	// Create a hyperlink for each continent we have a mirror in
	foreach ($mirrorContinents as $continent)
	{
		echo "<p><a href=\"download.php?file=" . $_GET["file"] . "&continent=" . array_search($continent, $mirrorContinents) . "\" " . "title=\"" . $continent . "\">" . $continent . "</a></p>";
	}
}

// Function to write to a log file
function writeToLog()
{
	// We need to make variables global
	global $mirrorList;
	global $mirrorChoices;
	global $destinationIndex;
	
	$myFile = "/var/log/loadbalancer/loadbalancer.log";
	// Open for amending
	
	$fh = fopen($myFile, 'a');
	$stringData =  date("d.m.Y H:i:s") . " Sending " . $_SERVER["REMOTE_ADDR"] . " to " . $mirrorList[$destinationIndex]->uri . "\n"; 
	fwrite($fh, $stringData);
	fclose($fh);
}

// Get the clients IP address
$clientIP = $_SERVER["REMOTE_ADDR"];

//Create an empty string variable to hold the clients continent code
$clientContinentCode = "";

// Get the clients continent according to GeoIP
$clientGeoIPContinentCode = geoip_continent_code_by_name($clientIP);

// Check if a continent has been passed
if (isset($_GET["continent"]))
{
	// If a continent is passed then get it into a variable
	$clientContinentCode = $_GET["continent"];
}
else
{
	/* Get the clients continent code from their ip address
	 * Note that FALSE will be returned if the continent can't be determined */
	$clientContinentCode = $clientGeoIPContinentCode;
}

// Variable to hold the number of mirrors in this continent
$mirrorsInContinent = 0;

// The array we will use to store our mirrors indexes after calculating their metrics
$mirrorChoices = array();

// If there is a selected continent (i.e it's not manual or false)
if ($clientContinentCode != "manual" && $clientContinentCode != FALSE)
{
	/* Generate another array from the list of mirrors by adding the url the number 
	 * of times specified by the metric */
	for ($mirrorCount = 0 ;  $mirrorCount < $numberOfMirrors ; $mirrorCount++)
	{
		// If the mirror is in the continent we want or it is a CDN
		if ($mirrorList[$mirrorCount]->continentCode == $clientContinentCode || $mirrorList[$mirrorCount]->continentCode == "CDN")
		{
			// Increment mirrorsInContinent for stats later
			$mirrorsInContinent += 1;
			
			// Add the mirrors index to end of the array untill count = metric
			for ($metricCount = 0 ; $metricCount < $mirrorList[$mirrorCount]->metric ; $metricCount++)
			{
				$mirrorChoices[] = $mirrorCount;
			}
		}
	}
}

/* Get the number of choices from the size of the array.
 * Note that each mirror now has multiple entries according to it's
 * metric */
$numberOfChoices = sizeof($mirrorChoices);

// If we have at least 1 server then pick one and log it
if ($numberOfChoices > 0)
{
	/* Generate a random index for the destination mirror
	* -1 is needed because the index is zero based. The destination will be an index
	* to be used with $mirrorList because we get the index of the selected mirror from $mirrorChoices */
	$destinationIndex = $mirrorChoices[mt_rand(0, ($numberOfChoices - 1))];
	
	// Log where we are sending the client
	writeToLog();
}

//	Construct a page
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN"
"http://www.w3.org/TR/html4/strict.dtd">

<html>

<!-- Raspberry Pi Downloads Load Balancing page -->
<!-- created by Liam Fraser -->

<style media="screen" type="text/css">
html,
body {
	font-family: arial;
	margin:5;
}

/* We want images to be vertially centered */
img	{ vertical-align:middle; } 

/* We want any anchors (links) to stay the same when visited */
a:link	{ color:blue; }
a:visited { color:blue; }
</style>

<head>
	<title>Downloads | Raspberry Pi</title>
	
	<?php
		// Only put in a refresh if we have a continent and it's not set to manual. Or number of of choices == 0
		if (($clientContinentCode != FALSE && $clientContinentCode != "manual") || $numberOfChoices != 0)
		{
			echo "<meta http-equiv=\"refresh\" content=\"5; url=" . $mirrorList[$destinationIndex]->uri . "\">";
		}
	?>
	
	<link rel="shortcut icon" href="/img/favicon.ico" 
	type="image/vnd.microsoft.icon"/>
	
	<link rel="icon" href="/img/favicon.ico" type="image/x-ico"/>
	
	<link type="text/css" rel="stylesheet" href="style.css">
</head>

<body>
		
		<h1>
			<a href="http://www.raspberrypi.org" title="Main Raspberry Pi Site">
			<img src="/img/pi.png" border="0" alt="Raspberry Pi Logo"></a>
				
			Raspberry Pi Distribution Downloads
		</h1>
		
		<p>
			<?php
			// Echo some stats
			echo "Total number of mirrors: $numberOfMirrors<br>";
			
			// Get the total metric and estimate total bandwidth
			$totalMetric = 0;
			foreach ($mirrorList as $mirror)
			{
				$totalMetric += $mirror->metric;
			}
			
			$totalMetric = $totalMetric / 10;
			echo "Total estimated bandwidth: $totalMetric Gbits/s<br>";
			
			// Only output continent info if there are mirrors in this continent
			if($numberOfChoices != 0)
			{
				echo "<br>";
				echo "Total number of mirrors in this continent: $mirrorsInContinent<br>";
				
				// Estimate continent bandwidth from number of choices
				$continentMetric = $numberOfChoices;
				$continentMetric = $continentMetric / 10;
				
				
				echo "Total estimated bandwidth for this continent: $continentMetric Gbits/s";
			}
			?>
		</p>
	
	<hr />
		
		<?php 
			/* If we don't have a continent code or it's set to manual, or there are no mirrors for the continent, don't output anything apart from a list of continents
			 * for the client to choose from. */
			if ($clientContinentCode == FALSE || $clientContinentCode == "manual" || $numberOfChoices == 0)
			{
				// If GeoIP is having an issue output a sorry message.
				if ($clientContinentCode == FALSE)
				{
					echo "<p>Sorry, we couldn't detect your continent.</p>";
				}
				
				// Echo a sorry we don't have mirrors if we have none and client continent code is not set to manual
				if ($numberOfChoices == 0 && $clientContinentCode != "manual")
				{
					echo "<p>Sorry, we don't have any mirrors in " . $continents[$clientContinentCode] . ".</p>";			
				}
				
				// Run our print continents function
				printMirrorContinents();
			}
			else
			{	
				// Get the path to the file
				$filePath = "/usr/share/nginx/html" . $_GET["file"];
				
				// Get the size of the file in bytes
				$fileBytes = filesize($filePath);
				
				// String containing file size
				$fileSize = "";
				
				// Convert bytes to MiB or GiB
				if ($fileBytes < 1073741824)
				{
					$fileSize = round($fileBytes / 1048576, 2) . ' MiB';
				}
				elseif ($fileBytes < 1099511627776)
				{
					$fileSize = round($fileBytes / 1073741824, 2) . ' GiB';
				}
				
				// Echo the file name
				echo "<h2>File: " . substr(strrchr($_GET["file"], "/"), 1) . "</h2>";
				echo "<h3>File Size: " . $fileSize ."</h3>";
				
				echo "<p>You will be redirected in 5 seconds. Don't want to wait? Use a
				<a href=\"" . $mirrorList[$destinationIndex]->uri .	"\" title=\"Direct Link\">Direct Link</a>.</p>";
				
				// Get the contents of the checksum file for the specified file
				$sha1 = file_get_contents($filePath . ".sha1");
			 
				// Get the sha1 hash only (remove file name)
				$sha1 = substr($sha1, 0, 40);
			 
				// Get the hyperlink to the .sha1 file
				$sha1Link = substr($_GET["file"], 1) . ".sha1";
			 
				// Echo the checksum for the specified file
				echo "<h3> <a href=\"/" . $sha1Link . "\" target=\"_blank\" title=\"SHA-1 Checksum\">SHA-1 Checksum</a>: " . $sha1 . "</h3>";
				
				echo "<p>We reccomend that you verify the image with the SHA-1 checksum provided above. Instructions for this are <a href=\"/verifying_an_image.html\" target=\"_blank\" title=\"SHA-1 Verification Instructions\">Here</a>.</p>";
			
				echo "<h3>Mirror information:</h3>";
				
				// We have a continent code so carry on as normal. Let's check if it's a manual continent or a GeoIP one
				if($clientContinentCode == $clientGeoIPContinentCode && !isset($_GET["continent"]))
				{
					echo "<p>We detected that your continent is " . $continents[$clientContinentCode];
				}
				else
				{
					echo "<p>You selected " . $continents[$clientContinentCode];
				}
				

				echo ". If this is incorrect, or you would like to try a mirror from a different continent, please " . "<a href=\"download.php?file="
				. $_GET["file"] . "&continent=manual"	. "\" " . "title=\"" . "Select a Continent"
				. "\">Select a Continent</a>.</p>";
				
				// Output destination mirror information
				echo "<p>" . $mirrorList[$destinationIndex]->mirrorText . "</p>"; 
					
				// Include image if there is one
				if ($mirrorList[$destinationIndex]->mirrorLogo != null)
				{
					echo "<p>" . $mirrorList[$destinationIndex]->mirrorLogo . "</p>";
				}
		
				// Get a hyperlink to the torrent file
				$torrentURL = $_GET["file"] . ".torrent";
		
				echo "<h3>Having an issue with a mirror? Why not try the <a href =\"" . $torrentURL . "\" title=\"Torrent\">Torrent</a> or an alternative mirror?</h3>";
				
				// Get the alternative mirrors
				
				// Create a blank array to hold the index of alternatives we have
				$alternativeMirrors = array();
				
				// Check if there are any mirrors for the detected continent
				for ($mirrorCount = 0 ;  $mirrorCount < $numberOfMirrors ; $mirrorCount++)
				{
					// If the mirror is in the continent we want / a CDN and it's not the destination mirror
					if (($mirrorList[$mirrorCount]->continentCode == $clientContinentCode || $mirrorList[$mirrorCount]->continentCode == "CDN") &&  $mirrorList[$destinationIndex]->uri != $mirrorList[$mirrorCount]->uri)
					{
						// Add the mirrors index to the alternative mirror array
						$alternativeMirrors[] = $mirrorCount;
						
					}
				}
				
				// Get the number of alternative mirrors
				$numberOfAlternativeMirrors = sizeof($alternativeMirrors);
				
				// If the number of alternative mirrors is 0 then there is only 1
				 if ($numberOfAlternativeMirrors == 0) 
				 {
					echo "<p>There are no alternative mirrors in this continent. Why not try another? </p>";
					echo "<a href=\"download.php?file="
					. $_GET["file"] . "&continent=manual"	. "\" " . "title=\"" . "Select a Continent"
					. "\">Select a Continent</a>.</p>";
				 }
				 else
				 {
					// Now we have each index of the alternative mirrors, we want to shuffle them so the order is random each time
					shuffle($alternativeMirrors);
					
					// Go through and output each mirror, now in a random order
					for ($mirrorCount = 0 ;  $mirrorCount < $numberOfAlternativeMirrors ; $mirrorCount++)
					{
						// Create a hyperlink for each mirror
						echo "<p><a href=\"" . $mirrorList[$alternativeMirrors[$mirrorCount]]->uri . "\" title=\"Mirror "
						. ($mirrorCount + 1) . "\">Mirror " . ($mirrorCount + 1) . "</a>";
						
						// Add the mirrors text if any
						if ($mirrorList[$alternativeMirrors[$mirrorCount]]->mirrorText != null)
						{
							echo " - " . $mirrorList[$alternativeMirrors[$mirrorCount]]->mirrorText;
						}
						
						//Close the paragraph
						echo "</p>";
						
					}
				 }						
			}
		?>

</body>

</html>
