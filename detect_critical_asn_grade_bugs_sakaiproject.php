<?php

// This script will print out CSV lines to identify sites, contacts, instrcutors, and assignments affected by
// Issue A: Released student grades not recorded in Gradebook, and
// Issue B: Assignments with severed gradebook associations
// Instances of Issue A will have an extra column, identifying the affected student (by EID).

// Enter your MySQL / MariaDB parameters
$sakai_host = "";
$sakai_db = "";
$sakai_user = "";
$sakai_pass = "";

// The following term is used in conjuction with a site query to limit the number of sites
// to a particular academic term. To query all sites, revise the query below.
$academic_term = "Fall 2022"; // Substite with a relevant term value 

try {
    $dbc = new PDO('mysql:host=' . $sakai_host . ';dbname=' . $sakai_db,
                   $sakai_user, $sakai_pass);
    if (! $dbc)
        throw new Exception ("Could not connect to database '" . $sakai_db . "'.");

    $site_hash = array(); // key-> site_id; value -> site title

    // This query assumes you want to check just one term.
    // If you want all sties, use instead "select SITE_ID, TITLE from SAKAI_SITE".
    $query = "select S.SITE_ID, S.TITLE from SAKAI_SITE_PROPERTY P, SAKAI_SITE S where UPPER(P.NAME)='TERM' AND UPPER(P.VALUE)='$academic_term' AND S.SITE_ID=P.SITE_ID";

    $r = $dbc->query($query);
	$num_rows = $r->rowCount();
    for ($i = 0; $i < $num_rows; $i++) {
        $row = $r->fetch();
        $site_id = $row['SITE_ID'];
        $title = $row['TITLE'];
        $site_hash[$site_id] = $title;
    }
    $r->closeCursor();

    $asn_hash = array(); // key -> asn_uri; value -> asn_id
    $asn2gbi_map = array(); // key -> asn_id; value -> gradebook item id
    
    // Get all assignments that have GB items originating from Assignments
    foreach ($site_hash as $site_id => $site_title) {

        $query = "select I.EXTERNAL_ID, I.ID FROM GB_GRADABLE_OBJECT_T I, GB_GRADEBOOK_T G WHERE G.NAME='$site_id' and I.GRADEBOOK_ID=G.ID and I.EXTERNAL_APP_NAME='sakai.assignment.grades'";
    
        $r = $dbc->query($query);
        $num_rows = $r->rowCount();

        for ($i = 0; $i < $num_rows; $i++) {
            $row = $r->fetch();
            $asn_uri = trim($row['EXTERNAL_ID']);
            $gbi_id = $row['ID'];
            $asn_id = parseAsnUri($dbc, $asn_uri);
            if ($asn_id == null) {
                echo "\n\nERROR: Unexpected assignment URI: $asn_uri\n";
                die();
            }

            if (isGradebookItemNotCounted($dbc, $gbi_id))
                continue; 
            $asn_hash[$asn_uri] = $asn_id;
            $asn2gbi_map[$asn_id] = $gbi_id;
        }
        $r->closeCursor();
    }


    foreach ($asn_hash as $asn_uri => $asn_id) {
        $gbi_id = $asn2gbi_map[$asn_id];

        $submissions = getScoredAndReleasedSubmissions($dbc, $asn_id);
        foreach ($submissions as $sub_id => $user_id) {
            if (! isScoreInGradebook($dbc, $gbi_id, $user_id)) {
                $eid = getEid($dbc, $user_id);
                $pair = getAsnAndSite($dbc, $asn_id);
                $asn_title = $pair[0];
                $site_id = $pair[1];
                if (isUserActiveInSite($dbc, $site_id, $user_id)) {
                    $ownerList = getOwnerList($dbc, $site_id);
                    $contact = get_site_contact($dbc, $site_id);
                    echo "$site_id, \"Issue A\", \"$contact\", \"$ownerList\", \"$asn_title\", $eid\n";
                }
            }
        }
    }
    
    // Now check assignments where the gradebook item originates from Gradebook
    $exception_arr = array(); // for capturing Issue B instances to print last

    foreach ($site_hash as $site_id => $site_title) {
        $query = "select A.ASSIGNMENT_ID, A.TITLE, P.VALUE FROM ASN_ASSIGNMENT A, ASN_ASSIGNMENT_PROPERTIES P WHERE A.CONTEXT='$site_id' and A.DRAFT=b'0' and A.DELETED=b'0' and P.ASSIGNMENT_ID=A.ASSIGNMENT_ID AND P.NAME='prop_new_assignment_add_to_gradebook'";

        $r = $dbc->query($query);
        $num_rows = $r->rowCount();

        for ($i = 0; $i < $num_rows; $i++) {
            $row = $r->fetch();
            $asn_id = trim($row['ASSIGNMENT_ID']);
            $asn_title = trim($row['TITLE']);
            $value = $row['VALUE']; // this could be either an asn_uri or a gradebook title

            if (! array_key_exists($value, $asn_hash)) {
                $gbi_id = getGradebookItemId($dbc, $site_id, $value);
                if ($gbi_id == null) {

                    // Consider that $value is an asn_uri and filter out gbi's which are marked as not
                    // counting toward the course grade
                    $asn_id = parseAsnUri($dbc, $value);
                    if ($asn_id != null) {
                        $gbi_id = getGradebookItemIdFromAsnId($dbc, $site_id, $value);
                        if (($gbi_id != null) & isGradebookItemNotCounted($dbc, $gbi_id))
                            continue;
                    }
                    
                    $ownerList = getOwnerList($dbc, $site_id);
                    $contact = get_site_contact($dbc, $site_id);
                    $line = "$site_id, \"Issue B\", \"$contact\", \"$ownerList\", \"$asn_title\"";
                    array_push($exception_arr, $line);
                }
                else if (! isGradebookItemNotCounted($dbc, $gbi_id)) {

                    $submissions = getScoredAndReleasedSubmissions($dbc, $asn_id);
                    foreach ($submissions as $sub_id => $user_id) {
                        if (! isScoreInGradebook($dbc, $gbi_id, $user_id)) {
                            $eid = getEid($dbc, $user_id);
                            $pair = getAsnAndSite($dbc, $asn_id);
                            $asn_title = $pair[0];
                            $site_id = $pair[1];
                        
                            if (isUserActiveInSite($dbc, $site_id, $user_id)) {
                                $ownerList = getOwnerList($dbc, $site_id);
                                $contact = get_site_contact($dbc, $site_id);
                                echo "$site_id, \"Issue A\", \"$contact\", \"$ownerList\", \"$asn_title\", $eid\n";
                            }
                        }
                    }
                }
            }
        }
        $r->closeCursor();
    }

    foreach ($exception_arr as $exception) {
        echo "$exception\n";
    }

}
catch (Exception $e) {
    $message = $e->getMessage();
    echo "\n\nERROR: $message\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
}

function parseAsnUri($dbc, $asn_uri)
{
    if (strlen($asn_uri) < 36)
        return null;
    $asn_id = substr($asn_uri, -36);
    if (strlen($asn_id) != 36)
        return null;
    if (! isValidAsnId($dbc, $asn_id))
        return null;
    return $asn_id;
}

function isValidAsnId($dbc, $asn_id)
{
	$query = "select ASSIGNMENT_ID from ASN_ASSIGNMENT where ASSIGNMENT_ID='$asn_id'";
	$r = $dbc->query($query);
	$num_rows = $r->rowCount();
    $r->closeCursor();
    return ($num_rows == 1);
}

function getScoredAndReleasedSubmissions($dbc, $asn_id)
{
    $result = array(); // key -> $sub_id; value -> submitter internal userid
    $query = "select M.USER_ID, S.SUBMISSION_ID, S.GRADE FROM SAKAI_USER_ID_MAP M, ASN_SUBMISSION S, ASN_SUBMISSION_SUBMITTER SS WHERE S.ASSIGNMENT_ID='$asn_id' and SS.SUBMITTER=M.USER_ID and S.SUBMISSION_ID=SS.SUBMISSION_ID and S.GRADE IS NOT NULL AND S.RETURNED_DATE IS NOT NULL";

	$r = $dbc->query($query);
	$num_rows = $r->rowCount();

    for ($i = 0; $i < $num_rows; $i++) {
        $row = $r->fetch();
        $user_id = trim($row['USER_ID']);
        $sub_id = trim($row['SUBMISSION_ID']);
        $grade = (int) trim($row['GRADE']);

        if ($grade > 0) {
            $result[$sub_id] = $user_id;
        }
    }
    $r->closeCursor();
    return $result;
}

function isUserActiveInSite($dbc, $site_id, $user_id)
{
    $query = "select * from SAKAI_SITE_USER where USER_ID='$user_id' AND SITE_ID='$site_id'";
	$r = $dbc->query($query);
	$num_rows = $r->rowCount();
    $r->closeCursor();
    return ($num_rows > 0);
}


function isScoreInGradebook($dbc, $gbi_id, $user_id)
{
    $query = "select * from GB_GRADE_RECORD_T where GRADABLE_OBJECT_ID=$gbi_id and STUDENT_ID='$user_id' AND (IS_EXCLUDED_FROM_GRADE = 1 OR POINTS_EARNED > 0)";
	$r = $dbc->query($query);
	$num_rows = $r->rowCount();
    $r->closeCursor();
    return ($num_rows > 0);
}


function isGradebookItemNotCounted($dbc, $gbi_id)
{
    $query = "select * from GB_GRADABLE_OBJECT_T where ID='$gbi_id' and NOT_COUNTED = 1";
	$r = $dbc->query($query);
	$num_rows = $r->rowCount();
    $r->closeCursor();
    return ($num_rows > 0);
}

function getAsnAndSite($dbc, $asn_id)
{
    $query = "select TITLE, CONTEXT from ASN_ASSIGNMENT WHERE ASSIGNMENT_ID='$asn_id'";
	$r = $dbc->query($query);
	$num_rows = $r->rowCount();
    if ($num_rows != 1)
        throw new Exception("Unexpected assignment id: $asn_id");
    
    $row = $r->fetch();
    $title = trim($row['TITLE']);
    $site_id = trim($row['CONTEXT']);
    $r->closeCursor();
    return array($title, $site_id);
}

function getEid($dbc, $user_id)
{
    $query = "select EID from SAKAI_USER_ID_MAP WHERE USER_ID='$user_id'";
	$r = $dbc->query($query);
	$num_rows = $r->rowCount();
    if ($num_rows != 1)
        throw new Exception("Unexpected user id: $user_id");
    
    $row = $r->fetch();
    $eid = trim($row['EID']);
    $r->closeCursor();
    return $eid;
}

function getGradebookItemId($dbc, $site_id, $gb_title)
{
    $gbi_id = null;

    $query = "select I.ID FROM GB_GRADABLE_OBJECT_T I, GB_GRADEBOOK_T G WHERE G.NAME=:site_id AND I.GRADEBOOK_ID=G.ID AND I.NAME=:gb_title AND I.REMOVED != 1";
    $stmt = $dbc->prepare($query);
    $return_value = $stmt->execute(array(':site_id' => $site_id, ':gb_title' => $gb_title));

    if (! $return_value) {
        $msg = "ERROR: Unexpected PDO error when calling getGradebookItemId with site_id [$site_id] and gb_title [$gb_title]";
        echo "\n\n$msg";
        throw new Exception($msg);
    }

    $r = $stmt->fetchAll();
	$num_rows = count($r);

    if ($num_rows == 1) {
        $row = $r[0];
        $gbi_id = $row['ID'];
    }

    return $gbi_id;
}

function getGradebookItemIdFromAsnId($dbc, $site_id, $asn_id)
{
    $gbi_id = null;

    $query = "select I.EXTERNAL_ID, I.ID FROM GB_GRADABLE_OBJECT_T I, GB_GRADEBOOK_T G WHERE G.NAME=:site_id and I.GRADEBOOK_ID=G.ID and I.EXTERNAL_APP_NAME='sakai.assignment.grades' and I.EXTERNAL_ID=:asn_id";
    $stmt = $dbc->prepare($query);
    $return_value = $stmt->execute(array(':site_id' => $site_id, ':asn_id' => $asn_id));

    if (! $return_value) {
        $msg = "ERROR: Unexpected PDO error when calling getGradebookItemIdFromAsnId with site_id [$site_id] and asn_id [$asn_id]";
        echo "\n\n$msg";
        throw new Exception($msg);
    }

    $r = $stmt->fetchAll();
	$num_rows = count($r);

    if ($num_rows == 1) {
        $row = $r[0];
        $gbi_id = $row['ID'];
    }

    return $gbi_id;
}


function getOwnerList($dbc, $site_id)
{
    $owners = get_site_owners($dbc, $site_id);
    $ownerList = "";
    $owner_count = count($owners);
    $x = 0;
    foreach ($owners as $owner_eid) {
        $ownerList .= $owner_eid;
        if ($x < ($owner_count - 1))
            $ownerList .= "; ";
        $x++;
    }
    return $ownerList;
}
    

// Return an array of EIDs for instructors or site maintainers for a site 
function get_site_owners($dbc, $site_id)
{
    $result = array();

    $query = "SELECT EID FROM SAKAI_USER_ID_MAP WHERE USER_ID IN (SELECT role.USER_ID FROM SAKAI_REALM_RL_GR role, SAKAI_REALM realm WHERE realm.REALM_ID = '/site/$site_id' AND role.REALM_KEY = realm.REALM_KEY AND role.ROLE_KEY = realm.MAINTAIN_ROLE)";

    $r = $dbc->query($query);
    $num_rows = $r->rowCount();
    for ($i = 0; $i < $num_rows; $i++) {
        $row = $r->fetch();
        $eid = $row['EID'];
        array_push($result, $eid);
    }
    $r->closeCursor();

    return $result;
}

function get_site_contact($dbc, $site_id)
{
    $name = "";
    $email = "";
    
    $query = "SELECT VALUE FROM SAKAI_SITE_PROPERTY WHERE SITE_ID='$site_id' and NAME='contact-name'";

    $r = $dbc->query($query);
    $num_rows = $r->rowCount();
    for ($i = 0; $i < $num_rows; $i++) {
        $row = $r->fetch();
        $name = trim($row['VALUE']);
    }

    $query = "SELECT VALUE FROM SAKAI_SITE_PROPERTY WHERE SITE_ID='$site_id' and NAME='contact-email'";

    $r = $dbc->query($query);
    $num_rows = $r->rowCount();
    for ($i = 0; $i < $num_rows; $i++) {
        $row = $r->fetch();
        $email = trim($row['VALUE']);
    }

    $r->closeCursor();

    $result = "$name <$email>";
    
    return $result;
}

?>
