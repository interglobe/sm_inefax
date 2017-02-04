#!/usr/bin/php
<?php
/*
    ** Inefax **

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

/* TODO
     put all the config variables into a single global config array
     modularize code into more functions using the single global config array
     use pipes when possible, but that makes it harder to debug
     e-mail fatal errors to $fail_to
     correctly handle normal, fine, and (later) superfine
*/

$version = "v0.52 2017-02-01";

ini_set("log_errors", 1);
ini_set("log_errors_max_len", 4096);
ini_set("display_startup_errors", 1);
ini_set("zlib.output_compression", 0);
ini_set("display_errors", 1);
error_reporting(E_ALL | E_STRICT);

// for use in error messages
$gecos = posix_getpwuid(posix_geteuid());
$username = $gecos["name"];

// command line options
$opts = getopt("a:d:e:c:");
if ($opts == FALSE || !isset($opts["a"]) || !isset($opts["d"]) ||!isset($opts["e"]) ) {
    echo __LINE__ . ": ######## invalid command line options. or two few options" . print_r($argv, TRUE) . "\n";
    die;
}

// find and load config file

if (isset($opts["c"])) { // config file specified

    if (file_exists($opts["c"])) {
        require_once($opts["c"]);
    } else {
        echo "configuration file '{$opts["c"]}' does not exist or cannot be accessed by user '$username'";
    }

} else { // config file not specified, look for it

    $basename = basename(__FILE__, ".php");
    if (file_exists("/etc/$basename.conf")) {
        require_once("/etc/$basename.conf");
    } else if (file_exists("/etc/sm_inefax.conf")) {
        require_once("/etc/sm_inefax.conf");
    } else {
        echo __LINE__ . ": unable to find config file.  first tried '/etc/$basename.conf, then '/etc/sm_inefax.conf'\n";
        die;
    }
}

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

if (!isset($pnmtopng_bin) || trim($pnmtopng_bin) == "") {
    $pnmtopng_bin = "/usr/bin/pnmtopng";
}

if (!isset($agi_lib) || trim($agi_lib) == "") {
    $agi_lib = "/var/lib/asterisk/agi-bin/phpagi.php";
}

if (!isset($temp_dir) || trim($temp_dir) == "") {
    $temp_dir = "/tmp/sm_inefax";
}

if (!isset($logo)|| trim($logo) == "") {
    $logo = "";
}

if (!isset($header) || trim($header) == "") {
    $header = "";
}

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
        $errors .= __LINE__ . ": ######## cannot create temp dir $temp_dir as user $username.\n";
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

// process the To: email addresses
// removes "efax/" strings and converts any & (ampersand) to , (comma)
$to = "";
$tos = preg_replace("#efax/#i", "", $opts["e"]);
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

// if there are any errors at this point, output them with agi verbose and exit
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

// format NANP ani
if (preg_match("/1([2-9]\d\d)([2-9]\d\d)(\d{4})/", $opts["a"], $matches)) {
    $ani = "1-{$matches[1]}-{$matches[2]}-{$matches[3]}";
} else {
    $ani = $opts["a"];
}

// format NANP dnis
if (preg_match("/1([2-9]\d\d)([2-9]\d\d)(\d{4})/", $opts["d"], $matches)) {
    $dnis = "1-{$matches[1]}-{$matches[2]}-{$matches[3]}";
} else {
    $dnis = $opts["d"];
}

$agi->set_variable("FAXOPT(localstationid)", trim("$dnis"));

set_time_limit(600);
declare(ticks = 1); // needed for pcntl_ functions

// ignore SIGHUP -- asterisk sends it when channel hangs up, but,
// we need to stick around to process the fax
pcntl_signal(SIGHUP, SIG_IGN);

// tarpit, helps slow down looping calls
sleep(1);

// build the fax filename
$datetime = date("Ymd_His", time());
preg_match("/0\.([^ ]+)/", microtime(), $matches);
$datetime .= "-" . substr($matches[1], 0, 6);
$filename = "fax_" . $opts["a"] . "_to_" . $opts["d"] . "_at_" . $datetime;

// start racording audio for troubleshooting if the fax fails.
$agi->exec("Monitor", "/var/spool/asterisk/tmp/$filename,o");

// receive the fax
if ($debug === TRUE) {
    $agi->exec("ReceiveFax", "$temp_dir/$filename.tiff,d");
} else {
    $agi->exec("ReceiveFax", "$temp_dir/$filename.tiff");
}

// end recording
$agi->exec("StopMonitor");

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

$agi->exec("CELGenUserEvent", "\"SM_INEFAX,status='$status' statusstr='$statusstr' ecm='$ecm' pages='$pages' rate='$rate' remotestationid='$remotestationid' localstationid='$localstationid' resolution='$resolution' sessionid='$sessionid' filenames='$fax_filenames'\"");

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

// build mime e-mail from scratch to eliminate external dependencies.

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

// consider only files > 512 bytes as valid. it is possible to have a usable
// fax, even if FAXOPT(status) is ERROR.  perhaps the last few pixels or lines
// of pixels was not received.  we still want to process them.
if (file_exists("$temp_dir/$filename.tiff") && filesize("$temp_dir/$filename.tiff") > 512) {

    // delete audio recording because this is a successful fax
    unlink("/var/spool/asterisk/tmp/$filename.wav");

    $timestamp = date("D, Y-m-d") . " at " . date("h:i a T");
    $subject = "Fax from $ani to $dnis";
    $imageinfo = `$tiffinfo_bin $temp_dir/$filename.tiff 2>&1`;

    if ($cc != "") {
        if ($debug === TRUE) {
            $headers .= "BCC: $cc\n";
        } else {
            $headers .= "cc: $cc\n";
        }
    }

    // placeholder for missing logo
    $logo_base64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAABBJREFUeNpi+P//PwNAgAEACPwC/tuiTRYAAAAASUVORK5CYII=";

    if (!trim($logo) == "") {
        $logo_img = file_get_contents($logo);
        if ($logo_img !== FALSE) {
            $logo_base64 = chunk_split(base64_encode($logo_img));
        }
    }

    system("$tiffcrop_bin -N 1 $temp_dir/$filename.tiff $temp_dir/$filename-cover-temp.tiff");

    system("/usr/bin/tifftopnm -respectfillorder $temp_dir/$filename-cover-temp.tiff > $temp_dir/$filename-cover-temp.pnm");

    system("/usr/bin/pnmtopng $temp_dir/$filename-cover-temp.pnm > $temp_dir/$filename-cover.png");

    $cover_fp = fopen("$temp_dir/$filename-cover.png", "rb");
    if ($cover_fp === FALSE) {
        echo "unable to open cover page.  cannot continue.\n";
        die;
    }
    $cover_img = fread($cover_fp, filesize("$temp_dir/$filename-cover.png"));
    fclose($cover_fp);

    $cover_base64 = chunk_split(base64_encode($cover_img));

    // get width and height of generated cover page for <img> tag
    $temp = unpack("N1width/N1height", substr($cover_img, 16, 8));
    unset($cover_img);

    $width = round($temp["width"] / 3);
    $height = round($temp["height"] / 3);

    system("$tiff2pdf_bin -p letter -o $temp_dir/$filename.pdf $temp_dir/$filename.tiff");
    $fax_base64 = chunk_split(base64_encode(file_get_contents("$temp_dir/$filename.pdf")));

$message .= <<< END
--$alt_boundary
Content-Type: text/plain

 A $pages page fax from $ani was received by $dnis on $timestamp.

 Remote Station ID: $remotestationid
  Local Station ID: $localstationid

    Your fax is attached.

 ** InterGlobe Inefax $version (c)2017 InterGlobe Communications, Inc. **

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
        First page of your fax is shown here.  Complete fax document is attached to this message.
      </div>
      <br>
      <img src="cid:cover-page@localhost.localdomain" height="$height" width="$width" alt="copy of first page of fax" style="height:$height px;width:$width px;display:block;margin-right:auto;margin-left:auto;border:1px solid #707070;padding:4px;clear:both;"/>
      <br>
      <div style="text-align:center;">
        <span style="font-weight:bold">complete fax document is attached</span>
      </div>
  </div>
  <div style="text-align:center;padding:4px">
    <a href="http://www.nyigc.com/">InterGlobe Inefax</a> $version (c)2017 InterGlobe Communications, Inc.
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

    // TODO attach audio recording for these failed faxes.

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

This metadata is useful only to technicians troubleshooting Inefax issues and the NSA.

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

DNIS={$opts["d"]}
ANI={$opts["a"]}

LINKEDID=$linkedid

FAXOPT(filenames)=$fax_filenames

$imageinfo

--$mixed_boundary--


END;

mail($to, $subject, $message, $headers);

if ($delete_tiff === TRUE && file_exists("$temp_dir/$filename.tiff")) {
    unlink("$temp_dir/$filename.tiff");
}

if ($delete_pdf === TRUE && file_exists("$temp_dir/$filename.pdf")) {
    unlink("$temp_dir/$filename.pdf");
}

exit;

