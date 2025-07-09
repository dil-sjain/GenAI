<?php
/**
 * Script to send out Training Invitations
 *
 */
require_once __DIR__ . '/../includes/php/'.'cms_defs.php';
$session->cms_logged_in(true, -1);
if ($session->secure_value('userClass') == 'vendor') {
    exit('Access denied.');
}
require_once __DIR__ . '/../includes/php/'.'funcs.php';
require_once __DIR__ . '/../includes/php/Models/TPM/InsertUniqueUserNumRecord.php';
require_once __DIR__ . '/../includes/php/'.'ddq_funcs.php';
require_once __DIR__ . '/../includes/php/'.'funcs_log.php';
if ($_SESSION['b3pAccess']) {
    include_once __DIR__ . '/../includes/php/funcs_thirdparty.php';
}


require_once __DIR__ . '/../includes/php/'.'class_access.php';
$accCls = UserAccess::getInstance();

// See if we have a caseID, ie. in edit mode
$caseID = $ddqID = 0;
$clientID = $_SESSION['clientID'];
$caseRow = $ddqRow = [];

require_once __DIR__ . '/../includes/php/Lib/SettingACL.php';

if (!$accCls->allow('sendInvite')) {
    exit('Access denied.');
}

$formTitle = "Training Intake Form Invitation";
$formClass = 'training';
$legacyID = '';
$renew=$reInvite=$invite=0;

// We need to store the selected DDQ name and ID in the log file;
// Get this info here (stored later)
$log_legacy = "";

$formAction = $_SERVER['PHP_SELF'];
if ($caseID) {
    $formAction .= '?id=' . $caseID;
}
//----------------------------------------//
$emailAddr = $confirmEmail = '';

$altErrorList = [];
if (!PageAuth::validToken('addinvAuth', $_POST['addinvAuth'])) {
    // If form was submitted, but invalid authorization provided, quit here
    $altErrorList[0] = PageAuth::getFailMessage($_SESSION['languageCode']);
    session_write_close();
    header("Location: $formAction");
    exit;
} elseif (isset($_POST['formClass']) && ($_POST['formClass'] == 'training')) {
    // This conditional clause was added specifically for support of hosted
    // training (SEC-52)
    $error_counter = 0;

    // Make sure a valid tpID was passed
    $tpID = (int)$_POST['tpID'];
    $sql = "SELECT count(*) FROM thirdPartyProfile WHERE clientID='$clientID' AND id='$tpID' LIMIT 1";
    if ($dbCls->fetchValue($sql) != 1) {
        $altErrorList[$error_counter++] = 'Invalid thirdparty selected';
    }

    $sql = "SELECT tpPersonMap.id as id FROM tpPerson, tpPersonMap WHERE \n"
        . "tpPersonMap.personID=tpPerson.id AND recType='Person' AND status='active' \n"
        . "AND tpPerson.clientID='$clientID' AND tpPersonMap.tpID='$tpID'";
    $valid_recipient_ids = $dbCls->fetchValueArray($sql);
    if (isset($_POST['recipient_ids']) && is_array($_POST['recipient_ids']) &&
        is_array($valid_recipient_ids) && (count($valid_recipient_ids) > 0)
        ) {
        $recipient_ids = [];
        foreach ($_POST['recipient_ids'] as $id) {
            $temp_id = (int)$id;
            if ($temp_id > 0) {
            // Make sure a valid recipient_id is passed
                if (in_array($temp_id, $valid_recipient_ids)) {
                    $recipient_ids[] = $temp_id;
                }
            }
        }
        unset($temp_id);

        if (count($recipient_ids) == 0) {
            $altErrorList[$error_counter++] = 'At least one valid training recipient must be selected';
        }
    } elseif (!isset($_POST['recipient_ids']) || !is_array($_POST['recipient_ids'])) {
        $altErrorList[$error_counter++] = 'At least one training recipient must be selected';
    }

    // Proceed if no errors
    if ($error_counter == 0) {
        // Loop through each selected recipient
        $postedLangs = [];
        $postedDdqNames = [];
        if (isset($_POST['ddqLangs'])) {
            $postedLangs = $_POST['ddqLangs'];
        }
        if (isset($_POST['ddqNames'])) {
            $postedDdqNames = $_POST['ddqNames'];
        }
        foreach ($recipient_ids as $mapID) {
            $inviteRecord = [];
            $sql = "SELECT personID FROM tpPersonMap WHERE id='$mapID' AND clientID='$clientID'";
            $personID = $dbCls->fetchValue($sql);

            // Make sure a valid ddqID was passed
            $valid_ddq = false;
            $ddqName = $postedDdqNames[$mapID];

            if (isValidDdqFormat($ddqName)) {
                $ddqID = $ddqName;
                $e_ddqID = $dbCls->esc($ddqID);
                // Get DDQ version number
                $sql = "SELECT count(*) FROM ddqName WHERE legacyID='$e_ddqID' \n"
                    . "AND clientID='$clientID' AND status='1' LIMIT 1";
                if ($dbCls->fetchValue($sql) == 1) {
                    $valid_ddq = true;
                }
            }

            if (!$valid_ddq) {
                $altErrorList[$error_counter++] = 'Invalid training form specified';

                // Don't go further with this invite; go to next invite
                break;
            } else {
                $ddqNameType = 0;
                $ddqNameVersion = '';
                $nameParts = splitDdqLegacyID($ddqName);
                $ddqNameType = $nameParts['caseType']; // integer
                $ddqNameVersion = $nameParts['ddqQuestionVer'];
            }
            // Make sure a valid language was specified
            if (isset($postedLangs[$mapID])) {
                $EMlang = $postedLangs[$mapID];
            } else {
                // Default to English if no language was specified
                $EMlang = 'EN_US';
            }
            $e_EMlang = $dbCls->esc($EMlang);
            $sql = "SELECT count(*) FROM onlineQuestions WHERE clientID='$clientID' \n"
                . "AND caseType='$ddqNameType' AND ddqQuestionVer='$ddqNameVersion' AND \n"
                . "languageCode='$e_EMlang' LIMIT 1";
            if ($dbCls->fetchValue($sql) == 0) {
                // Couldn't find matching language associated with this training invite, so default to English
                $EMlang = 'EN_US';
                $e_EMlang = $dbCls->esc($EMlang);
            }

            // Now get info associated with person
            $sql = "SELECT gender, firstName, lastName, fullName, altScript, email, \n"
                . "phone, altPhone, nationality, country, position FROM tpPerson, tpPersonMap \n"
                . "WHERE tpPerson.id=tpPersonMap.personID AND tpPersonMap.id='$mapID' AND \n"
                . "tpPerson.clientID='$clientID' AND status='active' AND recType='Person' LIMIT 1";
            $personRow = $dbCls->fetchObjectRow($sql);
            $inviteRecord['tf_POCemail'] = $inviteRecord['POCemail'] = $confirmEmail = $emailAddr = trim((string) $personRow->email);
            $inviteRecord['tf_POCname'] = $inviteRecord['POCname'] = $tf_POCname   = $personRow->fullName;
            $inviteRecord['tf_POCposi'] = $inviteRecord['POCposi'] = $tf_POCposi   = $personRow->position;
            $inviteRecord['tf_POCphone'] = $inviteRecord['POCphone'] = $tf_POCphone  = $personRow->phone;

            // Escape for SQL
            //$e_caseName  = $dbCls->esc($tf_caseName);
            $e_formClass = $dbCls->esc($formClass);
            $e_emailAddr = $dbCls->esc($emailAddr);
            $e_POCname   = $dbCls->esc($tf_POCname);
            $e_POCposi   = $dbCls->esc($tf_POCposi);
            $e_POCphone  = $dbCls->esc($tf_POCphone);
            $e_userid    = $dbCls->esc($_SESSION['userid']);

            // Get associated 3p profile info
            $sql = "SELECT legalName, DBAname, addr1, addr2, city, country, state, postcode, region, website, \n"
                . "bPublicTrade, stockExchange, tickerSymbol, POCname, POCposi, POCphone1, POCphone2, POCmobile, \n"
                . "POCfax, POCemail, department FROM thirdPartyProfile WHERE clientID='$clientID' AND id='$tpID'";
            $tpRow = $dbCls->fetchObjectRow($sql);

            //website, bPublicTrade, POCphone2, POCmobile, POCfax

            // Escape values in $_SESSION['ddqInvite']
            $inviteRecord['DBAname']      = $tpRow->DBAname;
            $inviteRecord['street']       = $tpRow->addr1;
            $inviteRecord['addr2']        = $tpRow->addr2;
            $inviteRecord['city']         = $tpRow->city;
            $inviteRecord['postCode']     = $tpRow->postcode;
            $inviteRecord['companyPhone'] = $tpRow->POCphone1;
            $inviteRecord['stockExchange'] = $tpRow->stockExchange;
            $inviteRecord['tickerSymbol']  = $tpRow->tickerSymbol;
            $inviteRecord['caseName']    = $tpRow->legalName;
            $inviteRecord['region']      = $tpRow->region;
            $inviteRecord['dept']        = $tpRow->department;
            $inviteRecord['caseState']   = $tpRow->state;
            $inviteRecord['caseCountry'] = $tpRow->country;

            $inviteRecord['userid'] = $_SESSION['userid'];
            $inviteRecord['ddqName'] = $ddqName;
            $inviteRecord['clientID'] = $clientID;
            $inviteRecord['emailAddr'] = $emailAddr;
            $inviteRecord['EMlang'] = $EMlang;

            $inviteRecord['regionID'] = $tpRow->region; // this is actually an ID
            $inviteRecord['deptID'] = $tpRow->department; // this is actually an ID
            $inviteRecord['ddqNameType'] = $ddqNameType;
            $inviteRecord['ddqNameVersion'] = $ddqNameVersion;
            $inviteRecord['tpID'] = $tpID;
            $inviteRecord['ddqID'] = $ddqID;
            $inviteRecord['personID'] = $personID;

            // Require 3P association
            if ($_SESSION['b3pAccess']) { // If 3P is turned on, make sure there is an association
                if (!$tpID) {
                    $altErrorList[$error_counter++] = "You must link a Third Party Profile"
                        . "to this invitation"
                        . ((!$accCls->allow('assoc3p')) ? ',<br />but your assigned role does not'
                        . ' grant that permission.': '.');
                }
            }

            // Don't have to make a guess about training anymore w/ addition
            // of tpPersonQuestionnaire table
            $existingDdqId = 0;
            $existingCaseId = 0;
            $existingCaseStage = 0;
            $sql = "SELECT ddqID, casesID FROM tpPersonQuestionnaire AS tpq \n"
                    . " INNER JOIN ddq \n"
                    . " ON (ddq.id = ddqID \n"
                    . " AND   ddq.subByDate = '0000-00-00 00:00:00') \n"
                    . " INNER JOIN cases \n"
                    . " ON cases.id = tpq.casesID \n"
                . "WHERE cases.tpID='$tpID' AND tpPersonID='$personID' AND tpq.clientID='$clientID' AND \n"
                . "tpq.formClass='training' AND tpq.ddqQuestionVer='$ddqNameVersion' \n"
                . "AND tpq.caseType='$ddqNameType' ORDER BY tpq.rowID DESC LIMIT 1";
            if ($invitation = $dbCls->fetchObjectRow($sql)) {
                $existingDdqId = (int)$invitation->ddqID;
                $existingCaseId = (int)$invitation->casesID;
            }

            if ($invitation) {
                $sql = "SELECT caseStage FROM cases WHERE id = {$existingCaseId} AND clientID = {$clientID}";
                $existingCaseStage = $dbCls->fetchValue($sql);
            }

            // Proceed if no errors
            if ($error_counter == 0) {
                if ((($existingDdqId == 0) && ($existingCaseId == 0)) || ($existingCaseStage == 104 || $existingCaseStage == 13)) {
                    $eventID = 149; // Send Training Invite E-mail
                    $caseID = locCreateTrainingInvite($inviteRecord);
                    // Add training record
                    $clientID = $dbCls->esc($clientID);
                    $tpID = $dbCls->esc($tpID);
                    $caseID = $dbCls->esc($caseID);
                    $sql = sprintf('SELECT name FROM ddqName WHERE legacyID=\'%s\' AND formClass=\'%s\' AND status=\'%d\' AND clientID=\'%d\' LIMIT 1 ; ',
                        $dbCls->esc($e_ddqID), 'training', 1, $clientID
                    );
                    $trainingName = $dbCls->fetchObjectRow($sql);

                    $trnID = locInsertTableRow(
                        'training',
                        ['clientID' => $clientID, 'tpID' => $tpID, 'name' => $dbCls->esc($trainingName->name), 'description' => '', 'trainingType' => 0, 'provider' => 'Online', 'providerType' => 'internal', 'linkToMaterial' => '']
                    );
                    $trnID = $dbCls->esc($trnID);
                    if ($trnID) {
                        $sql = sprintf('SELECT region.name as location, userCaseNum FROM cases LEFT JOIN region ON region.id = cases.region WHERE cases.id = \'%d\' AND cases.clientID = \'%d\' AND cases.tpID=\'%d\' LIMIT 1 ; ',
                            $caseID, $clientID, $tpID
                        );

                        $currCaseData = $dbCls->fetchObjectRow($sql);
                        locInsertTableRow(
                            'trainingData',
                            ['dateProvided' => date('Y-m-d H:i:s'), 'location' => $currCaseData->location, 'clientID' => $clientID, 'trainingID' => $trnID, 'tpID' => $tpID, 'userTrNum' => $currCaseData->userCaseNum]
                        );
                        // Event 71 - Add Training Record
                        $logMsg = "record: `$currCaseData->userCaseNum`, Training: `$trainingName->name ($ddqID)`";
                        log3pUserEvent(71, $logMsg, $tpID);
                        logUserEvent(71, $logMsg, $caseID);
                    }

                } else {
                    $caseID = $existingCaseId;
                    $eventID = 148; // Re-send Training Invite E-mail

                    // SEC-2787 Check if the subject's contact info is the same as the existing DDQ contact information
                    // for training invites. If the information differs, use the new association name and email
                    $sql = "SELECT POCname, POCemail FROM ddq WHERE id = {$existingDdqId} AND clientID = {$clientID}";
                    $ddqInfo = $dbCls->fetchObjectRow($sql);
                    include_once __DIR__ . '/../includes/php/'.'funcs_ddqinvite.php';
                    $nameChanged = $emailChanged = false;
                    if ($e_POCname != $ddqInfo->POCname) {
                        $sql = "UPDATE ddq SET POCname = '{$e_POCname}' WHERE id = {$existingDdqId} "
                            . "AND clientID = {$clientID}";
                        $dbCls->query($sql);
                        $nameChanged = true;
                    }
                    if ($e_emailAddr != $ddqInfo->POCemail) {
                        $sql = "UPDATE ddq SET POCemail = '{$e_emailAddr}' WHERE id = {$existingDdqId} "
                            . "AND clientID = {$clientID}";
                        $dbCls->query($sql);
                        $emailChanged = true;
                    }
                    if ($emailChanged) {
                        // generate new ddq login information
                        $DDQpassWord = getDdqPassword($e_emailAddr, $clientID, [$existingDdqId]);
                        $e_DDQpassWord = $dbCls->esc($DDQpassWord);
                        $sql = "UPDATE ddq SET loginEmail='{$e_emailAddr}', passWord = '{$e_DDQpassWord}' "
                            . "WHERE id = {$existingDdqId} AND clientID = {$clientID}";
                        $dbCls->query($sql);
                    }
                }

                // Send an email to the entity being invited
                $alertMailFailed = '';
                include_once __DIR__ . '/../includes/php/'.'funcs_sysmail2.php';
                $params = ['caseID' => $caseID, 'EMlang' => $EMlang];
                sendSysEmail(EMAIL_SEND_DDQ_INVITATION, $params);

                // Update Modified date
                $caseID = $dbCls->esc($caseID);
                $clientID = $dbCls->esc($clientID);
                $tpID = $dbCls->esc($tpID);
                $sql = sprintf('SELECT modified FROM cases WHERE id = \'%s\' AND clientID = \'%d\' LIMIT 1 ; ',
                    $caseID, $clientID
                );
                // Event 148 - Re-send Training Invite E-mail
                if (($inviteDateModif = $dbCls->fetchValue($sql)) && $eventID === 148) {
                    $sql = sprintf('UPDATE cases SET modified = \'%s\' WHERE id = \'%d\' AND clientID = \'%d\' LIMIT 1 ; ',
                        date('Y-m-d H:i:s'), $caseID, $clientID
                    );
                    $dbCls->query($sql);

                    $sql = sprintf('SELECT legalName FROM thirdPartyProfile WHERE id=\'%s\' AND clientID=\'%s\' LIMIT 1 ; ',
                        $tpID, $clientID
                    );
                    $tpName = $dbCls->fetchValue($sql);

                    $sql = sprintf('SELECT name FROM ddqName WHERE legacyID=\'%s\' AND formClass=\'%s\' AND status=\'%d\' AND clientID=\'%d\' LIMIT 1 ; ',
                        $dbCls->esc($e_ddqID), 'training', 1, $clientID
                    );
                    $trainingName = $dbCls->fetchObjectRow($sql);
                    $logMsg = sprintf("3P: `%s`, Training: `%s (%s)`, date: `%s` => `%s`",
                        $tpName, $trainingName->name, $ddqID, $inviteDateModif, date('Y-m-d H:i:s')
                    );
                    // Event 72 - Update Training Record
                    log3pUserEvent(72, $logMsg, $tpID);
                    logUserEvent(72, $logMsg, $caseID);
                }
                if (strlen($alertMailFailed) == 0) {
                    $sql = "SELECT name FROM ddqName WHERE legacyID='$e_ddqID' \n"
                        . "AND formClass='training' AND status='1' AND clientID='$clientID'";
                    $trainingName = $dbCls->fetchValue($sql);
                    $sql = "SELECT legalName FROM thirdPartyProfile WHERE id='$tpID' \n"
                        . "AND clientID='$clientID'";
                    $tpName = $dbCls->fetchValue($sql);
                    $logMsg = "3P: `$tpName`, Training: `$trainingName ($ddqID)`, "
                        . "pointOfContact: `$tf_POCname`, "
                        . "email: `$emailAddr`";
                    log3pUserEvent($eventID, $logMsg, $tpID);
                    logUserEvent($eventID, $logMsg, $caseID);
                }

            } else {
                foreach ($altErrorList as $err) {
                    echo $err . "<br />\n";
                }
            }

            unset($inviteRecord);
        }
        // Redirect user to
        session_write_close();
        if (count($altErrorList) == 0) {
            echo "<script type=\"text/javascript\">\n"
            . $alertMailFailed . "\n"
            . "parent.YAHOO.trainifr.reloadTbl();\n"
            . "parent.YAHOO.trainifr.closePanel();\n"
            . "</script>";
        } else {
            foreach ($altErrorList as $err) {
                echo $err . "<br />\n";
            }
        }
        exit;
    } else {
        foreach ($altErrorList as $err) {
            echo $err . "<br />\n";
        }
    }
}

// ------------------------- LOCAL FUNCTIONS FOLLOW -------------------------------------

function locInsertTableRow($table_name, $elements)
{
    // Neat little function to create a new DB row and get back
    // row ID by passing an associative array (col=>val) - Matt
    $dbCls = dbClass::getInstance();

    if (is_array($elements)) {
        $sql = 'INSERT INTO `' . $table_name . '` SET ';
        foreach ($elements as $col=>$val) {
            $sql .= "`$col`" . "='$val', ";
        }
        if (!$dbCls->query(rtrim($sql, ', '))) {
            return false;
        } else {
            return $dbCls->insert_id();
        }
    } else {
        return false;
    }
}

function locCreateTrainingInvite($record)
{
    // This function creates a brand new DDQ. This can start as
    // an empty new DDQ or a DDQ renewal keyed off an existing DDQ.
    // Because this function explicity creates a DDQ, it is NOT
    // intended for re-invites or a subscriber opting to renew from
    // a historical DDQ when a new one already exists.

    global $tf_caseName; // SESSION or POST origin
    global $e_substat; // POST origin

    $dbCls = dbClass::getInstance();
    $formClass = 'training';

    foreach ($record as $key=>$val) {
        ${$key} = trim((string) $val);
        $safe_key = 'e_' . $key;
        if (!is_array($val)) {
            ${$safe_key} = $dbCls->esc($val);
        }
    }
    $clientID = (int)$clientID;
    $caseStage = TRAINING_INVITE;

    // insert new case into DB
    // grh: Don't set tpID here!
    $caseAttributes = ['clientID'    => $clientID, 'caseName'    => $caseName, 'region'      => $regionID, 'dept'        => $deptID, 'caseCountry' => $caseCountry, 'caseState'   => $caseState, 'caseStage'   => $caseStage, 'requestor'   => $userid, 'caseCreated' => date('Y-m-d H:i:s'), 'creatorUID'  => $userid];
    $newCaseResult = (new InsertUniqueUserNumRecord($clientID))->insertUniqueTrainingCase($caseAttributes);
    $caseID = $newCaseResult['caseID'];
    if (!$caseID) {
        $dbCls->exitApp(__FILE__, __LINE__);
    }

    // log it
    $eventID = 150; // code for 'add training record'
    $sql = "SELECT name FROM ddqName WHERE legacyID='$e_ddqID' AND \n"
        . "formClass='training' AND status='1' AND clientID='$clientID'";
    $trainingName = $dbCls->fetchValue($sql);
    $sql = "SELECT legalName FROM thirdPartyProfile WHERE id='$e_tpID' AND clientID='$clientID'";
    $tpName = $dbCls->fetchValue($sql);
    $logMsg = "3P: `$tpName`, Training: `$trainingName ($ddqID)`, pointOfContact: `$tf_POCname`, "
        . "email: `$emailAddr`";
    log3pUserEvent($eventID, $logMsg, $tpID);

    // Insert record into the subjectInfoDD table
    $subjectinfo_elements = ['caseID'=>$caseID, 'clientID'=>$clientID, 'substat'=>$e_substat, 'name'=>$e_caseName, 'country'=>$e_caseCountry, 'state'=>$e_caseState, 'pointOfContact'=>$e_POCname, 'POCposition'=>$e_POCposi, 'phone'=>$e_POCphone, 'emailAddr'=>$e_emailAddr, 'street'=>$e_street, 'addr2'=>$e_addr2, 'city'=>$e_city, 'postCode'=>$e_postCode, 'DBAname'=>$e_DBAname];
    $subjInfoID = locInsertTableRow('subjectInfoDD', $subjectinfo_elements);
    if (!$subjInfoID) {
        $dbCls->exitApp(__FILE__, __LINE__);
    }

    // Create a new DDQ
    // Create Password (pseudo-acct: use existing, if present)
    $sql = "SELECT passWord FROM ddq WHERE loginEmail = '$e_emailAddr' AND"
        . " clientID = '$clientID' ORDER BY creationStamp DESC LIMIT 1";
    if (!($szDDQpw = $dbCls->fetchValue($sql))) {
        $szDDQpw = fRandomString(8);
    }
    $e_DDQpw = $dbCls->esc($szDDQpw);

    // Create DDQ DB entry (table: ddq)
    $ddq_elements = ['clientID'=>$clientID, 'caseID'=>$caseID, 'loginEmail'=>$e_emailAddr, 'passWord'=>$e_DDQpw, 'name'=>$e_caseName, 'country'=>$e_caseCountry, 'state'=>$e_caseState, 'POCname'=>$e_POCname, 'POCposi'=>$e_POCposi, 'POCphone'=>$e_POCphone, 'POCemail'=>$e_emailAddr, 'returnStatus'=>'pending', 'caseType'=>$ddqNameType, 'street'=>$e_street, 'addr2'=>$e_addr2, 'city'=>$e_city, 'postCode'=>$e_postCode, 'DBAname'=>$e_DBAname, 'companyPhone'=>$e_companyPhone, 'stockExchange'=>$e_stockExchange, 'tickerSymbol'=>$e_tickerSymbol, 'ddqQuestionVer'=>$ddqNameVersion, 'subInLang'=>$e_EMlang, 'formClass'=>$formClass, 'origin'=>'invitation', 'status'=>'active'];

    $ddqID = locInsertTableRow('ddq', $ddq_elements);
    if (!$ddqID) {
        $dbCls->exitApp(__FILE__, __LINE__);
    }

    // If needed, let's insert a thirdPartyElement
    // grh: This also sets cases.tpID
    if ($_SESSION['b3pAccess'] && $tpID) {
        bLink3pToCase($tpID, $caseID);
    }

    // Now map this to tpPerson
                $inviteRecord['ddqNameType'] = $ddqNameType;
            $inviteRecord['ddqNameVersion'] = $ddqNameVersion;

    $sql = "INSERT INTO tpPersonQuestionnaire (clientID, tpPersonID, ddqID, \n"
        . "casesID, formClass, caseType, ddqQuestionVer) \n"
        . "VALUES ($clientID, $e_personID, $ddqID, $caseID, 'training', \n"
        . "'$e_ddqNameType', '$e_ddqNameVersion')";
    $result = $dbCls->query($sql);

    return $caseID;
}
