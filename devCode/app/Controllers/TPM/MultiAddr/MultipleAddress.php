<?php
/**
 * Controller: Multiple Address
 *
 * Feature: TENANT_3P_MULTIPLE_ADDRESS
 * Caution: originally written for data requests from legacy
 */

namespace Controllers\TPM\MultiAddr;

use Models\TPM\MultiAddr\MultipleAddress as AddrData;
use Lib\IO;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\MultiAddr\TpAddrs;
use Models\TPM\MultiAddr\TpAddrCategory;
use Models\Globals\Geography;
use Lib\Validation\ValidateFuncs;

/**
 * Handles data requests for Multiple Address feature
 */
#[\AllowDynamicProperties]
class MultipleAddress
{
    use AjaxDispatcher;

    /**
     * @var \Skinny\Skinny|null Application framework instance
     */
    protected $app = null;

    /**
     * @var int|null TPM tenant ID
     */
    protected $clientID = null;

    /**
     * @var int|null Profile to which address belongs
     */
    protected $tpID = null;  // current thirdPartyProfile.id

    /**
     * @var AddrData|null class instance
     */
    protected $dataMdl = null;

    /**
     * @var bool User permission to edit addresses
     */
    protected $canEdit = false;

    /**
     * @var array Values to be validated and processed
     */
    protected $inputFlds = [];

    /**
     * @var Geography|null class instance
     */
    protected $geo = null;

    /**
     * @var ValidateFuncs|null class instance
     */
    protected $validFuncs = null;

    /**
     * Init class properties
     *
     * @param int $tpID     thirdPartyProfile.id
     * @param int $clientID TPM tenant ID
     */
    public function __construct($tpID = 0, $clientID = 0)
    {
        $app = \Xtra::app();
        $this->app = $app;
        $tpID = (int)$tpID;
        $clientID = (int)$clientID;

        // Get constructor args from session if not supplied
        $sess = $app->session;
        $this->clientID = $clientID ?: $sess->clientID;
        $this->tpID = $tpID ?: $sess->get('currentID.3p'); // legacy 'currentThirdPartyID'

        // Bail on empty values
        if (empty($this->tpID) || empty($this->clientID)) {
            $msg = 'Invalid arguments supplied for Multiple Address feature';
            $app->log->error(__FILE__ . ':' . __LINE__ . ' - ' . $msg);
            throw new \InvalidArgumentException($msg);
        }

        // Reference to data manager
        $this->dataMdl = new AddrData($this->clientID);

        // check permission to edit 3P profile
        $this->canEdit = $app->ftr->has(\FEATURE::TP_PROFILE_EDIT);

        $this->geo = Geography::getVersionInstance();
        $this->validFuncs = new ValidateFuncs();
    }

    /**
     * Legacy request for data on Summary page
     *
     * @return void
     */
    protected function ajaxSummaryInit()
    {
        $convEntities = $this->getBoolPostVar('clean');
        $summaryInfo = $this->dataMdl->getSummaryInfo($this->tpID, $convEntities);
        // Must have
        //   at least one (primary) address
        //   at least one address category
        //   at least one element in country lookup
        //   stateLookup must be an array

        // Add translation text
        $trans = $this->app->trans;
        $summaryInfo['trTxt'] = $trans->group('multi_addr');
        $err = []; // should remain empty if all is well

        // addresses
        if (!array_key_exists('addrs', $summaryInfo) || (count($summaryInfo['addrs']) < 1)) {
            $this->addMultiError(
                $err,
                $trans->codeKey('address_pc_pl'),
                $trans->codeKey('mulAddr_err_no_address')
            );
        }
        // categories
        if (!array_key_exists('cats', $summaryInfo) || (count($summaryInfo['cats']) < 1)) {
            $this->addMultiError(
                $err,
                $trans->codeKey('flTitle_AddrCats'),
                $trans->codeKey('mulAddr_err_no_category')
            );
        }
        // country lookup
        if (!array_key_exists('lookups', $summaryInfo)
            || !array_key_exists('countryLookup', $summaryInfo['lookups'])
            || (count($summaryInfo['lookups']['countryLookup']) < 1)
        ) {
            $this->addMultiError(
                $err,
                $trans->codeKey('mulAddr_Country_Lookup'),
                $trans->codeKey('mulAddr_err_no_country_lookup')
            );
        }
        // state lookup
        if (!array_key_exists('lookups', $summaryInfo)
            || !array_key_exists('stateLookup', $summaryInfo['lookups'])
            || !is_array($summaryInfo['lookups']['stateLookup'])
        ) {
            $this->addMultiError(
                $err,
                $trans->codeKey('mulAddr_State_Lookup'),
                $trans->codeKey('mulAddr_err_no_state_lookup')
            );
        }

        // go or no-go
        if (!empty($err)) {
            $this->jsObj->ErrTitle = $trans->codeKey('mulAddr_invalid_setup');
            $this->jsObj->MultiErr = $err;
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [$summaryInfo];
        }
    }

    /**
     * Get countries and/or states for initial country
     *
     * @return void
     */
    protected function ajaxDataForEdit()
    {
        $convEntities = (bool)$this->getPostVar('clean');
        $needCountries = (bool)$this->getPostVar('nc');
        $needStates = (bool)$this->getPostVar('ns');
        $country = $this->getPostVar('c');
        $data = [
            'countries' => null,
            'statesObj' => null,
        ];
        if ($needCountries) {
            $data['countries'] = $this->dataMdl->getCountries($convEntities, $this->tpID);
        }
        if ($needStates) {
            $data['statesObj'] = $this->dataMdl->getStatesForCountry($country, $convEntities, $this->tpID);
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [$data];
    }

    /**
     * Delete an address
     *
     * @return void
     */
    protected function ajaxDeleteAddress()
    {
        $id = (int)$this->getPostVar('addrID');
        if ($this->dataMdl->deleteAddress($id)) {
            $this->jsObj->Result = 1;
        } else {
            $this->jsObj->ErrTitle = 'Unable to delete';
            $this->jsObj->ErrMsg = 'An error occurred deleting the address.';
            $this->jsObj->ErrMsgWidth = 430;
        }
    }

    /**
     * Restore an archived address
     *
     * @return void
     */
    protected function ajaxRestoreAddress()
    {
        $id = (int)$this->getRawPostVar('aid');
        // Does user have permission to edit profile?
        if (!$this->canEdit) {
            $this->noAccessMsg();
            return;
        }
        // Do the deed
        $trans = $this->app->trans;
        if ($rtn = $this->dataMdl->restoreAddress($this->tpID, $id)) {
            if ($rtn->rowCount() === 1) {
                $this->jsObj->AppNotice = [$trans->codeKey('mulAddr_Address_restored')];
                $wantCleanData = $this->getBoolPostVar('clean');
                $this->jsObj->Result = 1;
                $this->jsObj->Args = [
                    $this->dataMdl->getSummaryInfo($this->tpID, $wantCleanData),
                ];
            } else {
                $this->jsObj->ErrTitle = $trans->codeKey('mulAddr_Nothing_Restored');
                $this->jsObj->ErrMsg = $trans->codeKey('mulAddr_Nothing_Restored_msg');
                $this->jsObj->ErrMsgWidth = 430;
            }
        } else {
            $this->jsObj->ErrTitle = $trans->codeKey('unexpected_error');
            $this->jsObj->ErrMsg = $trans->codeKey('mulAddr_failed_restore');
        }
    }

    /**
     * Update or Insert address record
     *
     * @return void
     */
    protected function ajaxUpsertAddress()
    {
        // Does user have permission to edit profile?
        if (!$this->canEdit) {
            $this->noAccessMsg();
            return;
        }

        // Validate
        $err = [];
        $trans = $this->app->trans;
        if ($id = (int)$this->getPostVar('id')) {
            $origRec = (new TpAddrs($this->clientID))->selectByID($id);
            if (empty($origRec)) {
                $this->jsObj->ErrTitle = $trans->codeKey('errTitle_invalidRequest');
                $this->jsObj->ErrMsg = $trans->codeKey('mulAddr_ref_not_found');
                return;
            }
        } else {
            $origRec = [];
        }

        // Expected inputs
        $flds = [
            'id' => $trans->codeKey('mulAddr_address_rec_id'),
            'addr1' => $trans->codeKey('addr1') . ':',
            'addr2' => $trans->codeKey('addr2') . ':',
            'city' => $trans->codeKey('city') . ':',
            'state' => $trans->codeKey('state_province_colon'),
            'country' => $trans->codeKey('country_colon'),
            'description' => $trans->codeKey('form_description'),
            'postcode' => $trans->codeKey('postcode') . ':',
            'addrCatID' => $trans->codeKey('mulAddr_Address_Category'),
            'primaryAddr' => $trans->codeKey('lbl_Make_Primary'),
            'archived' => $trans->codeKey('lbl_Archive'),
            'includeInRisk' => $trans->codeKey('lbl_Include_in_Ris'),
        ];
        $this->inputFlds = $flds;

        // get all the posted fields into local vars and set up query
        $boolFlds = ['primaryAddr', 'archived', 'includeInRisk'];
        $intFlds = ['id', 'addrCatID'];
        $sets = [];
        $params = [
            ':tpID' => $this->tpID,
            ':cid' => $this->clientID,
        ];
        foreach ($flds as $fld => $lbl) {
            if ($fld === 'id') {
                continue;
            } elseif ($fld === 'archived') {
                $archived = $this->getBoolPostVar($fld);
                if ($id) {
                    if ($primaryAddr || $origRec['primaryAddr']) {
                        // Nope, can't archive primary
                        $archived = 0;
                        $sets[] = "archived = 0";
                    } else {
                        // only if it is changing
                        if (!$archived && $origRec['archived']) {
                            $sets[] = "archived = 0";
                        } elseif ($archived && !$origRec['archived']) {
                            $sets[] = "archived = 1";
                        }
                    }
                } elseif ($archived) {
                    if ($primaryAddr) {
                        // Nope, can't archive primary
                        $archived = 0;
                        $sets[] = "archived = 0";
                    } else {
                        // new address is immediately archived?!
                        $sets[] = "archived = 1";
                    }
                }
                continue;
            } elseif ($fld === 'description') {
                $description = \Xtra::normalizeLF(trim((string) $this->getPostVar('description', '')));
                $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
                $sets[] = "`$fld` = :{$fld}";
                $params[":{$fld}"] = $description;
                continue;
            } elseif (in_array($fld, $boolFlds)) {
                // change boolean to 0 | 1
                $val = (int)$this->getBoolPostVar($fld);
            } elseif (in_array($fld, $intFlds)) {
                $val = (int)$this->getRawPostVar($fld);
            } else {
                $val = trim((string) $this->getPostVar($fld));
            }
            ${$fld} = $val;
            $sets[] = "`$fld` = :{$fld}";
            $params[":{$fld}"] = $val;
        }

        $maxLenErr = $trans->codeKey('invalid_MaxLength');
        $max255 = str_replace('{#}', '255', (string) $maxLenErr);
        $max50 = str_replace('{#}', '50', (string) $maxLenErr);
        $max4000 = str_replace('{#}', '4000', (string) $maxLenErr);

        // Primary address can't be archived
        if ($primaryAddr && $archived) {
            $this->addMultiError(
                $err,
                $flds['archived'],
                $trans->codeKey('mulAddr_err_noArchivedPrimary')
            );
        }

        // addrCatID - address category
        if ($addrCatID) {
            if ($catRec = (new TpAddrCategory($this->clientID))->selectByID($addrCatID)) {
                if (!$catRec['active']) {
                    // new address can't select inactive category
                    // update can't select inactive cat, unless it is currently assigned
                    if (!$id || ($origRec['addrCatID'] !== $addrCatID)) {
                        $this->addMultiError(
                            $err,
                            $flds['addrCatID'],
                            $trans->codeKey('mulAddr_err_no_assign_inactive_cat')
                        );
                    }
                }
            } else {
                $this->addMultiError(
                    $err,
                    $flds['addrCatID'],
                    $trans->codeKey('mulAddr_err_bad_category_ref')
                );
            }
        }

        // Validate text inputs
        $this->validateText($addr1, 'addr1', 255, $err);
        $this->validateText($addr2, 'addr2', 255, $err);
        $this->validateText($city, 'city', 255, $err);
        $this->validateText($postcode, 'postcode', 50, $err);
        $this->validateText($description, 'description', 4000, $err);

        if ($addr1 && !$this->validFuncs->checkInputSafety($addr1)) {
            $this->addMultiError(
                $err,
                $flds['addr1'],
                'Invalid Input: Address Line 1 contains unsafe characters'
            );
        }
        if ($addr2 && !$this->validFuncs->checkInputSafety($addr2)) {
            $this->addMultiError(
                $err,
                $flds['addr2'],
                'Invalid Input: Address Line 2 contains unsafe characters'
            );
        }
        if ($city && !$this->validFuncs->checkInputSafety($city)) {
            $this->addMultiError(
                $err,
                $flds['city'],
                'Invalid Input: City contains unsafe characters'
            );
        }
        if ($postcode && !$this->validFuncs->checkInputSafety($postcode)) {
            $this->addMultiError(
                $err,
                $flds['postcode'],
                'Invalid Input: Postcode contains unsafe characters'
            );
        }
        if ($description && !$this->validFuncs->checkInputSafety($description)) {
            $this->addMultiError(
                $err,
                $flds['description'],
                'Invalid Input: Description contains unsafe characters'
            );
        }

        // country - required, must be valid
        if (empty($country)
            || ($this->geo->getLegacyCountryCode($country) !== $country)
        ) {
            $this->addMultiError(
                $err,
                $flds['country'],
                $trans->codeKey('invalid_country_ref')
            );
        }

        // state - if not empty must be valid for country?
        if (!empty($state)
            && ($this->geo->getLegacyStateCode($state, $country) !== $state)
        ) {
            $this->addMultiError(
                $err,
                $flds['state'],
                $trans->codeKey('invalid_state_province')
            );
        }

        // Stop on errors!
        $trans = $this->app->trans;
        if ($err) {
            $this->jsObj->ErrTitle = $trans->codeKey('error_input_dialogTtl');
            $this->jsObj->MultiErr = $err;
            return;
        }

        // Time to attempt the upsert
        if ($rtn = $this->dataMdl->upsertAddress($id, $sets, $params, $origRec)) {
            if ($rtn->rowCount() === 1) {
                    $this->jsObj->AppNotice = $id
                        ? [$trans->codeKey('mulAddr_Address_updated')]
                        : [$trans->codeKey('mulAddr_Address_added')];
            } else {
                $this->jsObj->AppNotice = [$trans->codeKey('data_nothing_changed_title')];
            }
            // Provide fresh data for React components
            $wantCleanData = $this->getBoolPostVar('clean');
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [
                $this->dataMdl->getSummaryInfo($this->tpID, $wantCleanData),
            ];
        } else {
            $msg = $id
                ? $trans->codeKey('mulAddr_failed_update')
                : $trans->codeKey('mulAddr_failed_add');
            $this->jsObj->ErrTitle = $trans->codeKey('unexpected_error');
            $this->jsObj->ErrMsg = $msg;
        }
    }

    /**
     * Return states for specified country
     *
     * @return void
     */
    protected function ajaxFetchStates()
    {
        $country = $this->getPostVar('c');
        $convEntities = (bool)$this->getPostVar('clean');
        $statesObj = $this->dataMdl->getStatesForCountry($country, $convEntities, $this->tpID);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [$statesObj];
    }

    /**
     * Test ajax connection
     *
     * @return void
     */
    protected function ajaxTestRoute()
    {
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [
            'Success',
            'Route reached controller successfully.',
        ];
    }

    /**
     * Set no access error message
     *
     * @return void
     */
    protected function noAccessMsg()
    {
        $trans = $this->app->trans;
        $this->jsObj->ErrTitle = $trans->codeKey('title_access_denied');
        $this->jsObj->ErrMsg = $trans->codeKey('no_permission');
    }

    /**
     * Test for valid UTF-8 encoding
     *
     * @param string $str String to test
     *
     * @return bool
     */
    protected function badUtf8($str)
    {
        $rtn = false;
        if (!empty($str)) {
            $rtn = (preg_match('//u', $str) !== 1);
        }
        return $rtn;
    }

    /**
     * Test for url hex encoding in raw string input (e.g. %4B)
     *
     * @param string $postKey Key for use in getRawPostVar() to get raw posted value
     *
     * @return bool
     */
    protected function hasHexChar($postKey)
    {
        $rtn = false;
        if ($str = $this->getRawPostVar($postKey, '')) {
            $rtn = (strlen((string) $str) !== strlen(rawurldecode((string) $str)));
        }
        return $rtn;
    }

    /**
     * Validate text field for max length, valid UTF-8, and no url hex encoding
     *
     * @param string $txt     Posted value from form
     * @param string $postKey Array key for getRawPostVar()
     * @param int    $maxLen  Maximum length
     * @param array  $err     Error array for MultiError to build
     *
     * @return void
     */
    protected function validateText($txt, $postKey, $maxLen, &$err)
    {
        static $maxLenMsg = null;
        $trans = $this->app->trans;
        if (!$maxLenMsg) {
            $maxLenMsg = $trans->codeKey('invalid_MaxLength');
        }
        $fldName = $this->inputFlds[$postKey];
        if (mb_strlen($txt, 'UTF-8') > $maxLen) {
            $this->addMultiError(
                $err,
                $fldName,
                str_replace('{#}', $maxLen, (string) $maxLenMsg)
            );
        }
        if ($this->badUtf8($txt)) {
            $this->addMultiError(
                $err,
                $fldName,
                $trans->codeKey('invalid_UTF8')
            );
        }
        if ($this->hasHexChar($postKey)) {
            $this->addMultiError(
                $err,
                $fldName,
                $trans->codeKey('invalid_HasHexCharCode')
            );
        }
    }
}
