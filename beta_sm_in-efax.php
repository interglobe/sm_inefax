#!/usr/bin/php
<?php
/*
    ** In eFax **

    An Asterisk AGI to accept incoming faxes, convert them to
    PDF and e-mail them to the user.

    Copyright (c) 2017 InterGlobe Communications, Inc.

    Written by Eric Wieling <ewieling@nyigc.com>.
    Contact Gerald Bove <gbove@nyigc.com> for commercial licensing.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published
    by the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

// from e-mail address (required)
$from = "devnull@nyigc.net";

//FNORD  logo to use in header or footer. use in $header like <img src="cid:logo.png">
$logo = "/igc/agi/sm/igc-logo.png";

// header html.  no default.
$header = <<<END
  <div style="font-weight: bold;padding:8px;background-color:#ffe5af; border-bottom:1px solid #707070;clear:both;">
    <img src="cid:logo@localhost.localdomain" width="210" height="67" style="width:210px;height:67px;clear:none;"/>
    <div style="float:right;text-align:right;margin-top:8px;clear:none;">http://www.nyigc.com/<br>support@nyigc.com<br>1-212-918-2000</span>
  </div>

END;

/* TODO
     put all the config variables into a global config array
     modularize code into more functions
     use pipes when possible when converting, but that makes it harder to debug
     e-mail fatal errors to $fail_to
*/

// enable debug and don't daemonize. default is false.
$debug = TRUE;

// send failed fax notifications here. default is to not send failed fax notifications.
$fail_to = "ewieling@nyigc.com";

// cc address to receive copies of all successfully received faxes. no default.
// this might be useful to cc all faxes to a manager or to a 3rd party fax archive system
$cc = "ewieling@nyigc.com";

// location of tiff2pdf binary. default is /usr/bin/tiff2pdf.
$tiff2pdf_bin = "/usr/bin/tiff2pdf";

// location of tiffinfo binary. default is /usr/bin/tiffinfo.
$tiffinfo_bin = "/usr/bin/tiffinfo";

// location of tiffcrop binary. default is /usr/bin/tiffcrop.
$tiffcrop_bin = "/usr/bin/tiffcrop";

// location of tifftopnm binary. default is /usr/bin/tifftopnm
$tifftopnm_bin = "/usr/bin/tifftopnm";

// location of pbmreduce binary. default is /usr/bin/pbmreduce
$pbmreduce_bin = "/usr/bin/pbmreduce";

// location of pnmtopng binary. default is /usr/bin/pnmtopng
$pnmtopng_bin = "/usr/bin/pnmtopng";

// where your phpagi.php is located. default is /var/lib/asterisk/agi-bin.
$agi_lib_dir = "/igc/lib";

// temp dir for temp files, directory will be created if it does not exist. default is /tmp/sm_efax.
//$temp_dir = "/tmp/sm_efax";

// delete .tiff files after fax is e-mailed out. default is false.
$delete_tiff = FALSE;

// delete .pdf files after fax is e-mailed out.  default is false.
$delete_pdf = FALSE;

/* *** END OF CONFIGURATION VARIABLES *** */

ini_set("log_errors", 1);
ini_set("log_errors_max_len", 4096);
ini_set("display_startup_errors", 1);
ini_set("zlib.output_compression", 0);
ini_set("display_errors", 1);
error_reporting(E_ALL | E_STRICT);

$version = "v0.52 2017-02-01";

// set defaults
if (!isset($debug) || ($debug !== TRUE && $debug !== FALSE)) {
    $debug = FALSE;
}

if (!isset($keep_tiff) || ($keep_tiff !== TRUE && $keep_tiff !== FALSE)) {
    $keep_tiff = FALSE;
}

if (!isset($keep_pdf) || ($keep_pdf !== TRUE && $keep_pdf !== FALSE)) {
    $keep_pdf = FALSE;
}

if (!isset($tiff2pdf_bin) || trim($tiff2pdf_bin) == "") {
    $tiff2pdf_bin = "/usr/bin/tiff2pdf";
}

if (!isset($tiffinfo_bin) || trim($tiffinfo_bin) == "") {
    $tiffinfo_bin = "/usr/bin/tiffinfo";
}

if (!isset($tiffcrop_bin) || trim($tiffcrop_bin) == "") {
    $tiffinfo_bin = "/usr/bin/tiffcrop";
}

if (!isset($tifftopnm_bin) || trim($tifftopnm_bin) == "") {
    $tiffinfo_bin = "/usr/bin/tifftopnm";
}

if (!isset($tifftopnm_bin) || trim($tifftopnm_bin) == "") {
    $tiffinfo_bin = "/usr/bin/tifftopnm";
}

if (!isset($pbmreduce_bin) || trim($pbmreduce_bin) == "") {
    $tiffinfo_bin = "/usr/bin/pbmreduce";
}

if (!isset($pnmtopng_bin) || trim($pnmtopng_bin) == "") {
    $pnmtopng_bin = "/usr/bin/pnmtopng";
}

if (!isset($agi_lib_dir) || trim($agi_lib_dir) == "") {
    $agi_lib = "/var/lib/asterisk/agi-bin/phpagi.php";
} else {
    $agi_lib = "$agi_lib_dir/phpagi.php";
}

if (!isset($temp_dir) || trim($temp_dir) == "") {
    $temp_dir = "/tmp/sm_efax";
}

if (!isset($logo)|| trim($logo) == "") {
    $logo = "";
}

if (!isset($header) || trim($header) == "") {
    $header = "";
}

if (!isset($footer) || trim($footer) == "") {
    $footer = "";
}

// for use in error messages
$gecos = posix_getpwuid(posix_geteuid());
$username = $gecos["name"];

// verify required files and directories are accessable and have required permissions
$errors = "";
if (!isset($from) || trim($from) == "") {
    $errors .= __LINE__ . ': ######## $from is not set.' . "\n";
}

if (!is_executable($tiff2pdf_bin)) {
    $errors .= __LINE__ . ": ######## $tiff2pdf_bin is not executable by user $username.\n";
}

if (!is_executable($tiffinfo_bin)) {
    $errors .= __LINE__ . ": ######## $tiffinfo_bin is not executable by user $username.\n";
}

if (!is_executable($tiffcrop_bin)) {
    $errors .= __LINE__ . ": ######## $tiffcrop_bin is not executable by user $username.\n";
}

if (!is_executable($tifftopnm_bin)) {
    $errors .= __LINE__ . ": ######## $tifftopnm_bin is not executable by user $username.\n";
}

if (!is_executable($pbmreduce_bin)) {
    $errors .= __LINE__ . ": ######## $pbmreduce_bin is not executable by user $username.\n";
}

if (!is_executable($pnmtopng_bin)) {
    $errors .= __LINE__ . ": ######## $pnmtopng_bin is not executable by user $username.\n";
}

if (!is_readable($agi_lib)) {
    $errors .= __LINE__ . ": ######## $agi_lib is not readable by user $username.\n";
}

if ($logo != "" && !is_readable($logo)) {
    $errors .= __LINE__ . ": ######## $logo is not readable by user $username.\n";
}

if (!file_exists($temp_dir)) {
    if (!mkdir($temp_dir, 0700 , TRUE)) {
        $errors .= __LINE__ . ": ######## cannot create temp dir $temp_dir with user $username.\n";
    }
}

if (!is_readable($temp_dir)) {
    $errors .= __LINE__ . ": ######## $temp_dir is not readable by user $username.\n";
}

if (!is_writable($temp_dir)) {
    $errors .= __LINE__ . ": ######## $temp_dir is not writable by user $username.\n";
}

if (!is_executable($temp_dir)) {
    $errors .= __LINE__ . ": ######## $temp_dir is not executable by user $username.\n";
}

$opts = getopt("", array("ani:", "dnis:", "to:"));
if ($opts == FALSE || count($opts) != 3) {
    $errors .= __LINE__ . ": ######## invalid command line options. " . print_r($argv, TRUE) . "\n";
}

// process the To: email addresses
// removes "efax/" strings and converts any & (ampersand) to , (comma)
$to = "";
$tos = preg_replace("#efax/#i", "", $opts["to"]);
$tos = preg_replace("/,/", "&", $tos);
$temp1 = explode("&", $tos);
foreach ($temp1 as $temp2) {
    $to .= $temp2 . ",";
}
$to = substr($to, 0, -1);

if ($to == "") {
    $errors .= __LINE__ . ": ######## invalid or missing destination e-mail address;\n";
}

require_once($agi_lib);

$agi = new AGI();

// output fatal errors and exit
if ($errors != "") {
    foreach (explode("\n", $errors) as $error) {
        $error = preg_replace('/"/', "'", preg_replace("/\s+/", " ", trim($error)));
        if ($error != "") {
            $agi->verbose($error);
        }
    }
    die;
}

$linkedid = $agi->get_variable("LINKEDID", TRUE);
$account_name = preg_replace("/[^ a-z0-9&,.-]/i", " ", $agi->get_variable("SM_ACCOUNT_NAME", TRUE));

// format NANP ani
if (preg_match("/1([2-9]\d\d)([2-9]\d\d)(\d{4})/", $opts["ani"], $matches)) {
    $ani = "1-{$matches[1]}-{$matches[2]}-{$matches[3]}";
} else {
    $ani = $opts["ani"];
}

// format NANP dnis
if (preg_match("/1([2-9]\d\d)([2-9]\d\d)(\d{4})/", $opts["dnis"], $matches)) {
    $dnis = "1-{$matches[1]}-{$matches[2]}-{$matches[3]}";
} else {
    $dnis = $opts["dnis"];
}

$agi->set_variable("FAXOPT(localstationid)", trim("$dnis $account_name"));

// build the fax filename
$datetime = date("Ymd_His", time());
preg_match("/0\.([^ ]+)/", microtime(), $matches);
$datetime .= "-" . substr($matches[1], 0, 6);
$filename = "fax_" . $opts["ani"] . "_to_" . $opts["dnis"] . "_at_" . $datetime;

set_time_limit(600);
declare(ticks = 1); // needed for pcntl_ functions

// ignore SIGHUP -- asterisk sends it when channel hangs up, but,
// we need to stick around to process the fax
pcntl_signal(SIGHUP, SIG_IGN);

if ($debug === TRUE) {
    $agi->exec("ReceiveFax", "$temp_dir/$filename.tiff,d");
} else {
    $agi->exec("ReceiveFax", "$temp_dir/$filename.tiff");
}

$error = $agi->get_variable("FAXOPT(error)", TRUE);
if ($error == "INIT_ERROR") {
    $agi->verbose("######## ######## ####### EFAX ERROR INIT ERROR ######## ######## ######## ######## ######## ######## ");
    exit;
}

$status = $agi->get_variable("FAXOPT(status)", TRUE);
$statusstr = $agi->get_variable("FAXOPT(statusstr)", TRUE);
$ecm = $agi->get_variable("FAXOPT(ecm)", TRUE);
$fax_filenames = $agi->get_variable("FAXOPT(filenames)", TRUE);
$rate = $agi->get_variable("FAXOPT(rate)", TRUE);
$remotestationid = preg_replace("/[^ a-z0-9&,.-]/i", "", $agi->get_variable("FAXOPT(remotestationid)", TRUE));
$localstationid = $agi->get_variable("FAXOPT(localstationid)", TRUE);
$resolution = $agi->get_variable("FAXOPT(resolution)", TRUE);
$sessionid = $agi->get_variable("FAXOPT(sessionid)", TRUE);
$pages = $agi->get_variable("FAXOPT(pages)", TRUE);
$headerinfo = $agi->get_variable("FAXOPT(headerinfo)", TRUE);

$agi->exec("CELGenUserEvent", "\"SM_EFAX,status='$status' statusstr='$statusstr' ecm='$ecm' pages='$pages' rate='$rate' remotestationid='$remotestationid' localstationid='$localstationid' resolution='$resolution' sessionid='$sessionid' filenames='$fax_filenames'\"");

// become a daemon so we don't tie up asterisk resources while we process the fax
if ($debug !== TRUE) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die("could not fork");
    } elseif ($pid) {
        exit; // we are the parent
    }
    // we are the child
    // detatch from the controlling terminal so we don't become a zombie when we die.
    if (posix_setsid() == -1) {
        die("could not detach from terminal");
    }
}

// be nice and lower our priority and the priority of any spawned processes.
proc_nice(10);

// build mime e-mail from scratch to eliminate external dependencies

// Generate a MIME boundary string
$boundary = md5(microtime(TRUE));
$mixed_boundary = "mixed-$boundary";
$related_boundary = "related-$boundary";
$alt_boundary = "alt-$boundary";

$header_date = date("r");
$headers = <<<END
From: $remotestationid <noreply@nyigc.net>
Return-Path: noreply@nyigc.net
Reply-To: noreply@nyigc.net
Date: $header_date
MIME-Version: 1.0
Content-Type: multipart/mixed;
    boundary=$mixed_boundary

END;

$message = <<< END
--$mixed_boundary
Content-Type: multipart/related; type="text/plain";
    boundary=$related_boundary
Content-Disposition: inline; filename=message

--$related_boundary
Content-Type: multipart/alternative;
    boundary=$alt_boundary
Content-Disposition: inline; filename=body


END;

// only consider files > 512 bytes to be valid. it is possible to have a usable
// fax, even if FAXOPT(status) is ERROR.  perhaps the last few pixels or lines
// of pixels was not received.
if (file_exists("$temp_dir/$filename.tiff") && filesize("$temp_dir/$filename.tiff") > 512) {

    $imageinfo = `$tiffinfo_bin $temp_dir/$filename.tiff 2>&1`;

    $timestamp = date("D, Y-m-d") . " at " . date("h:i a T");

    if ($cc != "") {
        $headers .= "BCC: $cc\n";
    }

    $subject = "Fax from $ani to $dnis";

    if (!trim($logo) == "") {

        $logo_img = file_get_contents($logo);
        if ($logo_img === FALSE) {
            // placeholder for missing logo
            $logo_base64 = "R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";
        } else {
            $logo_base64 = chunk_split(base64_encode($logo_img));
        }

    } else {
        // placeholder for missing logo
        $logo_base64 = "R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";
    }

    system("$tiffcrop_bin -N 1 $temp_dir/$filename.tiff $temp_dir/$filename-cover-temp.tiff");

    system("/usr/bin/tifftopnm -respectfillorder $temp_dir/$filename-cover-temp.tiff > $temp_dir/$filename-cover-temp.pbm");

    system("/usr/bin/pbmreduce 3 $temp_dir/$filename-cover-temp.pbm > $temp_dir/$filename-cover.pbm");

    system("/usr/bin/pnmtopng $temp_dir/$filename-cover.pbm > $temp_dir/$filename-cover.png");

    $cover_fp = fopen("$temp_dir/$filename-cover.png", "rb");
    if ($cover_fp === FALSE) {
        echo "unable to open cover page.  cannot continue.\n";
        die;
    }
    $cover_img = fread($cover_fp, filesize("$temp_dir/$filename-cover.png"));
    fclose($cover_fp);

    $cover_base64 = chunk_split(base64_encode($cover_img));

    // get width and height of generated cover page for <img> tag
    $cover_header = unpack("N1width/N1height", substr($cover_img, 16, 8));

    system("$tiff2pdf_bin -p letter -o $temp_dir/$filename.pdf $temp_dir/$filename.tiff");
    $fax_base64 = chunk_split(base64_encode(file_get_contents("$temp_dir/$filename.pdf")));

$message .= <<< END
--$alt_boundary
Content-Type: text/plain

 A $pages page fax from $ani was received by $dnis on $timestamp.

 Remote Station ID: $remotestationid
  Local Station ID: $localstationid

    Your fax is attached.

 ** InterGlobe eFax $version (c)2017 InterGlobe Communications, Inc. **

--$alt_boundary
Content-Type: text/html

<btml><body>
<div style="max-width:700px;border:1px solid #707070;margin:6px;padding:0px;background-color:#f7f7f7;">
  <div>
$header
  </div>
  <div style="padding:8px;">
      <br>
      <div>
        A $pages page fax from $ani was received by $dnis on $timestamp.
      </div>
      <br>
      <div>
        <span style="display:inline-block; width: 150px;text-align:right;">Sending Fax ID:</span> <span style="font-weight:bold;">$remotestationid</span>
      </div>
      <div>
        <span style="display:inline-block; width: 150px;text-align:right;">Receiving Fax ID:</span> <span style="font-weight:bold;">$localstationid</span>
      </div>
      <br>
      <div>
        The first page of your fax is shown below.  The complete fax is an attachment to this message.
      </div>
      <br>
      <img src="cid:cover-page@localhost.localdomain" height="{$cover_header["height"]}" width="{$cover_header["width"]}" style="height:{$cover_header["height"]}px;width:{$cover_header["width"]}px;display:block;margin-right:auto;margin-left:auto;border:1px solid #707070;padding:4px;clear:both;"/>
      <br>
      <div style="text-align:center;">
        <span style="font-weight:bold">complete fax document is attached
      </div>
  </div>
  <div style="text-align:center;padding:4px">
    <a href="http://www.nyigc.com/">InterGlobe eFax</a> $version (c)2017 InterGlobe Communications, Inc.
  </div>
</div>
</body></html>
--$alt_boundary--
--$related_boundary
Content-Type: image/png
Content-Transfer-Encoding: base64
Content-Disposition: inline; filename=logo.png
Content-ID: <logo@localhost.localdomain>

$logo_base64
--$related_boundary
Content-Type: image/png
Content-Transfer-Encoding: base64
Content-Disposition: inline; filename=cover-page.png
Content-ID: <cover-page@localhost.localdomain>

$cover_base64
--$related_boundary--
--$mixed_boundary
Content-Type: application/pdf
Content-Transfer-Encoding: base64
Content-Disposition: attachment;
    filename=$filename.pdf

$fax_base64

END;

} elseif ($fail_to != "") {

    // fax failed and $fail_to is set
    $to = $fail_to;

    $subject = "Failed fax from $ani to $dnis";

$message .= <<<END
--$alt_boundary
Content-Type: text/plain;
Content-Transfer-Encoding: 8bit

 Failed fax from $ani was received by $dnis.

 Remote Station ID: $remotestationid
  Local Station ID: $localstationid

END;

} else {
    // fax failed and $fail_to not set
    exit;
}

$message .= <<<END
--$mixed_boundary
Content-Type: text/plain
Content-Disposition: attachment; filename=metadata.txt

This is only useful to the NSA and techs troubleshooting eFax issues.

----------
METADATA
----------

FAXOPT(error)=$error
FAXOPT(status)=$status
FAXOPT(statusstr)=$statusstr
FAXOPT(pages)=$pages
FAXOPT(ecm)=$ecm
FAXOPT(rate)=$rate
FAXOPT(remotestationid)=$remotestationid
FAXOPT(localstationid)=$localstationid
FAXOPT(resolution)=$resolution
FAXOPT(sessionid)=$sessionid
FAXOPT(headerinfo)=$headerinfo

DNIS={$opts["dnis"]}
ANI={$opts["ani"]}

LINKEDID=$linkedid

FAXOPT(filenames)=$fax_filenames

$imageinfo

--$mixed_boundary--


END;

//if ($debug == TRUE) {
//    file_put_contents("/tmp/efax.message", "$headers\nXXXXXXXXXX\n$to\nXXXXXXXXXX\n$subject\nXXXXXXXXXX\n$message");
//}

mail($to, $subject, $message, $headers);

if ($delete_tiff === TRUE && file_exists("$temp_dir/$filename.tiff")) {
    unlink("$temp_dir/$filename.tiff");
}

if ($delete_pdf === TRUE && file_exists("$temp_dir/$filename.pdf")) {
    unlink("$temp_dir/$filename.pdf");
}

exit;

