<?php
/*
 *  Copyright (C) 2012 MyProfile Project
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal 
 *  in the Software without restriction, including without limitation the rights 
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell 
 *  copies of the Software, and to permit persons to whom the Software is furnished 
 *  to do so, subject to the following conditions:

 *  The above copyright notice and this permission notice shall be included in all 
 *  copies or substantial portions of the Software.

 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, 
 *  INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A 
 *  PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT 
 *  HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION 
 *  OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE 
 *  SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
 
/*
 * OpenSSL based, in-browser certificate generation.
 *
 *
 */
    
require_once 'include.php';
// form in html
$form = '';

$form .= "<div class=\"content relative shadow clearfix main\">\n";
$form .= "<font style=\"font-size: 2em; text-shadow: 0 1px 1px #cccccc;\">Generate a Simple WebID KEYGEN-based Client Certificate</font>\n";
	
$form .= "<br/><form name=\"input\" action=\"certgen.php\" method=\"post\">\n";
$form .= "<input type=\"hidden\" name=\"doit\" value=\"1\">\n";
$form .= "<table>\n";
$form .= "	<tr><td colspan=\"2\"><font style=\"font-size: 1em;\">If you already have a FOAF card, this form allows you to create a client certificate for it.</font><br/><br/></td></tr>\n";
$form .= "	<tr><td><h3>WebID address</h3></td><td><input type=\"text\" name=\"foaf[]\" size=\"40\" id=\"foaf\" onkeypress=\"validateCert('foaf', 'commonName', 'submit', 2)\" value=\"".urldecode($_REQUEST['webid'])."\" /> (min 2 characters)</td></tr>\n";
$form .= "	<tr><td><h3>Full name</h3></td><td><input type=\"text\" id=\"commonName\" size=\"40\" name=\"commonName\" onkeypress=\"validateCert('foaf', 'commonName', 'submit', 2)\" value=\"".urldecode($_REQUEST['name'])."\" /></font> (min 2 characters)</td></tr>\n";
$form .= "	<tr><td><h3>Email address</h3></td><td><input type=\"text\" name=\"emailAddress\" value=\"".urldecode($_REQUEST['email'])."\" /></td></tr>\n";
$form .= "	<tr hidden><td hidden><keygen name=\"pubkey\" keytype=\"rsa\" challenge=\"randomchars\"></td></tr>\n";
$form .= "	<tr><td colspan=\"3\">&nbsp;</td></tr>\n";
$form .= "	<tr><td></td><td><input type=\"submit\" class=\"btn btn-primary\" id=\"submit\" value=\"Install certificate\" disabled></td></tr>\n";
$form .= "</table>\n";
$form .= "</form>\n";
$form .= "</div>\n";
$form .= "<script type=\"text/javascript\">\n";
$form .= "validateCert('foaf', 'commonName', 'submit', 2)";
$form .= "</script>\n";

if ($_POST['doit'] == 1) {

	//
	// Main
	//

    $error = '';
    // Check if the foaf location is specified in the script call
	$foafLocation = $_POST['foaf'];
	if (!$foafLocation) {
		$error .= 'Please specify the location of your foaf file.';
	}

	// Check if the commonName is specified in the script call
	$commonName = $_POST['commonName'];
	if (strlen($commonName) < 2) {
		$error .= 'Please specify a Common Name with a min. length or 2 characters.';
	}
	if (strlen($_POST['countryName']) < 1)
		$_POST['countryName'] = 'FR';

	// Get the rest of the script parameters
	$countryName		    = $_POST['countryName'];
	$stateOrProvinceName	= $_POST['stateOrProvinceName'];
	$localityName		    = $_POST['localityName'];
	$organizationName	    = $_POST['organizationName'];
	$organizationalUnitName = $_POST['organizationalUnitName'];
	$emailAddress		    = $_POST['emailAddress'];
	$pubkey			        = $_POST['pubkey'];
	
    // check that everything is ok
    if (strlen($error) > 0) {
        include 'header.php';
        $ret= "<div class=\"container\">\n";
        $ret .= "<div class=\"content\">\n";
        $ret .= "<div class=\"row\">\n";
        $ret .= "<div class=\"ui-state-error ui-corner-all\">\n";
        $ret .= "<p><span class=\"ui-icon ui-icon-info\" style=\"float: left; margin-right: .3em;\"></span>\n";
        $ret .= "<div align=\"left\"><strong>Error: </strong>" . $error . ".</div>\n";
        $ret .= "</p></div></div><br/>\n";
        echo $ret;
        echo $form;
        include 'footer.php';
    } else {
        include_once 'lib/functions.php';
    	// Create a x509 SSL certificate
	    if ($x509 = create_identity_x509($countryName, $stateOrProvinceName, $localityName, $organizationName, $organizationalUnitName, $commonName, $emailAddress, $foafLocation, $pubkey, SSL_CONF, CA_PASS)) {
		    // Send the X.509 SSL certificate to the script caller (user) as a file transfer
		    download_identity_x509($x509, $foafLocation[0]);
	    }
    }
} else {
    include_once 'header.php'; 
    echo $form;
	include_once 'footer.php';
}
?>
