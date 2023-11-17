<?php

// Run this script as part of the Sakai 23 upgrade process.
// It should be implemented right after running the SQL upgrade to the
// Sakai 23 database schema and before starting Sakai on tomcat.
// This script translates users' favorited and unfavorited sites to
// pinned and unpinned sites.

// Executing this script may take several minutes to complete:
// e.g., 20K records in SAKAI_PREFERENCES takes approx. 5 minutes.

// Enter your MySQL / MariaDB parameters
$sakai_host = "";
$sakai_db = "";
$sakai_user = "";
$sakai_pass = "";

// Logging in this script assumes that the Pear logger is used.
// Otherwise, logging statements would need to be revised.
require_once("Log.php");
$logfile = preg_replace('/\.php/', ".log", basename($_SERVER['PHP_SELF']));
$logger = Log::factory('file', 'log/' . $logfile);

try {
    $logger->info($_SERVER['PHP_SELF'] . " has begun.");

    $dbc = new PDO('mysql:host=' . $sakai_host . ';dbname=' . $sakai_db,
                   $sakai_user, $sakai_pass);
    if (! $dbc)
        throw new Exception ("Could not connect to database '" . $sakai_db . "'.");

    $query = "select M.EID, M.USER_ID, P.XML FROM SAKAI_PREFERENCES P, SAKAI_USER_ID_MAP M WHERE P.PREFERENCES_ID=M.USER_ID";
    $r = $dbc->query($query);
	$num_rows = $r->rowCount();

    for ($i = 0; $i < $num_rows; $i++) {
        $row = $r->fetch();
        $eid = trim($row['EID']);
        $user_id = trim($row['USER_ID']);
        $xml = trim($row['XML']);

        $doc = new DOMDocument();
        $doc->loadXml($xml);

        $nodeList = $doc->getElementsByTagName("property");
        $pinnedSites = array();
        $unpinnedSites = array();
        foreach ($nodeList as $node) {
            $name = $node->getAttribute("name");
            $val = $node->getAttribute("value");

            if ("order" == $name) {
                $site_id = base64_decode($val);
                $pinnedSites[$site_id] = $site_id;
            }
            else if ("autoFavoritesSeenSites" == $name) {
                $site_id = base64_decode($val);
                $unpinnedSites[$site_id] = $site_id;
            }
        }

        $position = 0;
        foreach ($pinnedSites as $site) {
            if (! can_user_access_site($user_id, $site)) {
                $logger->warning("User '$eid' cannot access site '$site'; skipping favorite sites record.");
                continue;
            }
            echo_and_log("Pinning site $site at position $position for $eid");
            pin_site($user_id, $site, $position);
            $position++;
        }
    
        foreach ($unpinnedSites as $site) {
            if (array_key_exists($site, $pinnedSites)) {
                $logger->warning("Unfavorited conflict with user '$eid' and favorited site '$site'; skipping unfavorited sites record.");
                continue;
            }
            echo_and_log("Unpinning site $site for $eid");
            unpin_site($user_id, $site);
        }
    }    
}
catch (Exception $e) {
    $message = $e->getMessage();
    $logger->err($message);
    $logger->err("Stack Trace:\n" . $e->getTraceAsString());

    echo "$message\n";
}

$logger->info($_SERVER['PHP_SELF'] . " has ended.");

function echo_and_log($message)
{
    global $logger;
    $logger->info($message);
    echo "$message\n";
}

function pin_site($user_id, $site, $position) {
    global $dbc, $logger;
    $stmt = $dbc->prepare("INSERT INTO PINNED_SITES (USER_ID, SITE_ID, POSITION, HAS_BEEN_UNPINNED) VALUES(:user_id, :site_id, :position, b'0')");
    $return_value = $stmt->execute(array(':user_id' => $user_id,
                                         ':site_id' => $site,
                                         ':position' => $position));
    
    if (! $return_value)
        throw new Exception("A MySQL insertion error occurred for pin_site with user $user_id, site $site, and position $position: " .
                            print_r($stmt->errorInfo(), true) . "'.");
}

function unpin_site($user_id, $site) {
    global $dbc, $logger;
    $stmt = $dbc->prepare("INSERT INTO PINNED_SITES (USER_ID, SITE_ID, POSITION, HAS_BEEN_UNPINNED) VALUES(:user_id, :site_id, -1, b'1')");
    $return_value = $stmt->execute(array(':user_id' => $user_id,
                                         ':site_id' => $site));
    
    if (! $return_value)
        throw new Exception("A MySQL insertion error occurred for unpin_site with user $user_id and site $site: " .
                            print_r($stmt->errorInfo(), true) . "'.");
}

function can_user_access_site($user_id, $site) {
    global $dbc, $logger;
    //    $query = "SELECT role.USER_ID FROM SAKAI_REALM_RL_GR role, SAKAI_REALM realm WHERE realm.REALM_ID = '/site/$site' AND role.USER_ID = '$user_id' AND role.REALM_KEY = realm.REALM_KEY";
    $query = "SELECT su.* from SAKAI_SITE_USER su, SAKAI_SITE s WHERE su.SITE_ID='$site' and su.SITE_ID=s.SITE_ID AND su.USER_ID='$user_id' and (su.PERMISSION=-1 OR s.PUBLISHED=1)";
    $r = $dbc->query($query);
    $num_rows = $r->rowCount();
    $r->closeCursor();

    if ($num_rows > 1)
        throw new Exception("Unexpected number of SAKAI_SITE_USER rows ($num_rows) for site '$site' and user '$user_id'");

    
    return ($num_rows == 1);
}

?>
