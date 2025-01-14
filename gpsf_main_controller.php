<?php
// -----
// Google Product Search Feeder II, main script (cronable).
// Copyright 2023, https://vinosdefrutastropicales.com
//
// Last updated: v1.0.0
//
/**
 * Based on
 *
 * @package google product search feeder
 * @copyright Copyright 2007-2008 Numinix Technology http://www.numinix.com
 * @copyright Portions Copyright 2003-2006 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: google_product_search.php 21 2012-09-27 17:48:54Z numinix $
 * @author Numinix Technology
 */
require 'includes/application_top.php';

if (!defined('GPSF_ENABLED') || GPSF_ENABLED !== 'true') {
    die('Google Product Search Feeder II is disabled');
}
// process parameters
$key = isset($_REQUEST['key']) ? $_REQUEST['key'] : null;
if ($key !== GPSF_ACCESS_KEY) {
    exit('Incorrect key supplied!');
}

if (!isset($_REQUEST['feed'])) {
    exit('Missing feed type.');
}

if ((int)GPSF_MAX_EXECUTION_TIME > 0) {
    ini_set('max_execution_time', (int)GPSF_MAX_EXECUTION_TIME);
    set_time_limit((int)GPSF_MAX_EXECUTION_TIME);
}
ini_set('max_input_time', -1);
if ((int)GPSF_MEMORY_LIMIT > 0) {
    ini_set('memory_limit', (int)GPSF_MEMORY_LIMIT . 'M');
}

// -----
// Remove the 'queryCache' object so that unwanted database caching
// doesn't occur as well as the pre-zc158 $configuration array that
// contains all the configuration setting retrieved to free up more
// memory.
//
unset($queryCache, $configuration);

define('NL', "<br>\n");

require DIR_WS_CLASSES . 'gpsfFeedGenerator.php';
$gpsf = new gpsfFeedGenerator();

// -----
// Retrieve the parameters based on the requested feed type, normally in the format
// ?feed=f[y|n]_u[y|n][_tp]
//
if ($gpsf->setFeedParameters($_REQUEST['feed']) === false) {
    exit('Unknown "feed" parameters received, see associated log.');
}
$type = $gpsf->getFeedType();
if ($type !== 'products') {
    trigger_error("Only a 'products' feed is currently supported; '$type' indicated in {$_REQUEST['feed']}.", E_USER_WARNING);
    exit("Unsupported feed type ($type) indicated, nothing more to do.");
}

$feed = $gpsf->isFeedGeneration();

require zen_get_file_directory(DIR_WS_LANGUAGES . $_SESSION['language'] . '/', 'gpsf_main_controller.php', 'false');
?>
<html>
<body>
<?php
$language_id = (isset($_GET['language_id']) ? $_GET['language_id'] : GPSF_LANGUAGE);
$languages_query = "SELECT code, languages_id, directory FROM " . TABLE_LANGUAGES . " WHERE languages_id = " . (int)$language_id . " LIMIT 1";
$languages = $db->Execute($languages_query);
if ($languages->EOF) {
    exit('Unknown language specified for feed, nothing further to do.');
}
$gpsf->setFeedLanguage($languages->fields);

$limit = '';
$offset = '';

// sql limiters
$query_limit = 0;
if ((int)GPSF_MAX_PRODUCTS > 0 || (isset($_REQUEST['limit']) && (int)$_REQUEST['limit'] > 0)) {
    $query_limit = (isset($_REQUEST['limit']) && (int)$_REQUEST['limit'] > 0) ? (int) $_REQUEST['limit'] : (int)GPSF_MAX_PRODUCTS;
    $limit = ' LIMIT ' . $query_limit;
}
$query_offset = 0;
if ((int)GPSF_START_PRODUCTS > 0 || (isset($_REQUEST['offset']) && (int)$_REQUEST['offset'] > 0)) {
    $query_offset = (isset($_REQUEST['offset']) && (int)$_REQUEST['offset'] > 0) ? (int)$_REQUEST['offset'] : (int)GPSF_START_PRODUCTS;
    $offset = ' OFFSET ' . $query_offset;
}
$outfile = DIR_FS_CATALOG . GPSF_DIRECTORY . GPSF_OUTPUT_FILENAME . '_' . $type . '_' . $languages->fields['code'];
if ($query_limit > 0) {
    $outfile .= '_' . $query_limit;
}
if ($query_offset > 0) {
    $outfile .= '_' . $query_offset;
}
$outfile .= '.xml'; //example domain_products.xml

ob_start();
echo '<p>' . sprintf(TEXT_GPSF_STARTED, GPSF_VERSION) . '</p>';
echo '<p>' . TEXT_GPSF_FILE_LOCATION . $outfile . '</p>';
echo '<p>Processing: Feed - ' . ($feed === 'yes' ? 'Yes' : 'No') . '</p>';
echo '<p>PHP Memory Limit: ' . ini_get('memory_limit') . '</p>';
ob_flush();
flush();

// -----
// If we're generating a feed ...
//
if ($feed === 'yes') {
    if (is_dir(DIR_FS_CATALOG . GPSF_DIRECTORY) === false) {
        exit(ERROR_GPSF_DIRECTORY_DOES_NOT_EXIST);
    } elseif (is_writeable(DIR_FS_CATALOG . GPSF_DIRECTORY) === false) {
        exit(ERROR_GPSF_DIRECTORY_NOT_WRITEABLE);
    }

    // -----
    // See if the lock file is present and, if so, dated within the last hour.
    // If so, a feed's in the process of re-generating and we'll exit so as not
    // to overwrite another in-process generation.
    //
    $lockfile = "$outfile.lock";
    if (file_exists($lockfile) && filemtime($lockfile) > time() - (1 * 60 * 60)) {
        exit("Pre-existing lock file ($lockfile) found, another feed is currently in process!");
    }

    // -----
    // Open the to-be-generated feed-file for writing, to see if it's writable.
    //
    $fp = fopen($outfile, 'ab');
    if ($fp === false) {
        exit("Unable to open '$outfile' for writing; check permissions.");
    }

    // -----
    // Acquire a lock on the to-be-generated feed-file, exiting if the lock
    // request fails.
    //
    if (flock($fp, LOCK_EX) === false) {
        fclose($fp);
        exit("Unable to lock '$outfile' for the processing; feed not generated.");
    }

    // -----
    // Update the last-updated time on the lock file and, now that we know that the
    // feed-file's writable and locked, truncate the feed-file prior to the current
    // feed's start.
    //
    touch($lockfile);
    ftruncate($fp, 0);

    $timer_feed_start = $gpsf->microtime_float();

    // -----
    // Kick the feed's generation off ...
    //
    $gpsf->generateProductsFeed($fp, $limit, $offset);

    // release the lock
    flock($fp, LOCK_UN);
    fclose($fp);
    if (file_exists($lockfile)) {
        unlink($lockfile);
    }

    if (GPSF_COMPRESS === 'true' && function_exists('gzopen')) {
        $gzcontent = file_get_contents($outfile);
        unlink($outfile);

        $outfile .= '.gz'; // Append .gz to end of file name
        $gz = gzopen($outfile, 'w9'); // Open file for writing, 0 (no) to 9 (maximum) compression
        gzwrite($gz, $gzcontent); // Write compressed file
        gzclose($gz); // Close file handler
    }

    $products_total = $gpsf->getTotalProducts();
    $products_processed = $gpsf->getTotalProductsProcessed();
    $products_skipped = $products_total - $products_processed;
    $peak_memory_usage_mb = (float)(memory_get_peak_usage(true) / (1024 * 1024));
    echo
        '<p>' .
            sprintf(TEXT_GPSF_FEED_COMPLETE, $gpsf->microtime_float() - $timer_feed_start, $peak_memory_usage_mb) .
            '<br>' .
            sprintf(TEXT_GPSF_FEED_PROCESSED, $products_total, $products_processed, $products_skipped) .
        '</p>';

    $gpsf->googleOutputDebug();
}
?>
</body>
</html>