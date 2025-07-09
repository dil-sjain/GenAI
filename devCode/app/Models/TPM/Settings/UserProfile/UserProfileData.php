<?php
/**
 * Class UserProfile modifies User record
 */

namespace Models\TPM\Settings\UserProfile;

use Lib\Legacy\ConfReader;
use Lib\Crypt\Crypt64;
use Models\Globals\Geography;
use Models\ThirdPartyManagement\ClientProfile;
use Lib\TwoFactorAuthenticator;

/**
 * Class UserProfile modifies User record
 */
#[\AllowDynamicProperties]
class UserProfileData
{
    /**
     * @var \Skinny\Skinny|null Instance of application framework
     */
    protected $app  = null;

    /**
     * @var object|null Instance of app->DB
     */
    protected $DB   = null;

    /**
     * @var object|null Instance of app->sesion
     */
    protected $sess = null;

    /**
     * @var ConfReader|null Instance of ConfReader
     */
    protected $ConfReader = null;

    /**
     * @var object|null Table names
     */
    protected $tbl = null;

    /**
     * @var int 3PM tenant ID
     */
    protected $tenantID = 0;

    /**
     * TwoFactorAuthenticator instance
     *
     * @var object
     */
    public $authenticator = null;

    /**
     * class constructor
     *
     * @param integer $tenantID 3PM tenant ID
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        $tenantID = (int)$tenantID;
        $this->app  = \Xtra::app();
        $this->DB   = $this->app->DB;
        $this->sess = $this->app->session;
        $this->ConfReader = new ConfReader();
        $this->tenantID   = $tenantID;

        $this->tbl = (object)null;
        $this->tbl->users = $this->DB->authDB . '.users';
        $this->tbl->features = $this->DB->globalDB . '.g_features';
        $this->tbl->featuresGroup = $this->DB->globalDB . '.g_featuresGroup';
        $this->tbl->pwHistory = $this->DB->authDB . '.pwHistory';

        $tfaOptional = $this->app->ftr->tenantHas(\Feature::TENANT_2FA_OPTIONAL);
        $tfaEnforced = $this->app->ftr->tenantHas(\Feature::TENANT_2FA_ENFORCED);

        $this->authenticator = ($tfaOptional || $tfaEnforced) ? new TwoFactorAuthenticator() : null;
    }

    /**
     * gather DB record of user databits
     *
     * @param integer $userID userID
     *
     * @return array one row, multiple columns
     */
    public function getUserData($userID)
    {
        $userID = intval($userID);
        $bindArr = [':userID' => $userID];
        $ret = $this->DB->fetchAssocRow(
            "SELECT userid, firstName, lastName, userEmail, userAddr1, userAddr2, userCity, userPC, "
            . "userCountry, userState, userPhone, userMobile, userFax, landPgPref, userNote, "
            . "userPhoneCountry, userMobileCountry, twoFactorAuth, tfaDevice "
            . "FROM {$this->tbl->users} "
            . "WHERE id=:userID "
            . "LIMIT 1",
            $bindArr
        );
        return $ret;
    }



    /**
     * get language options
     *
     * @return array value-text dropdown array
     */
    public function getlangOptions()
    {
        return $this->DB->fetchObjectRows(
            "SELECT langCode AS v, langNameNative AS t, rtl AS d FROM {$this->DB->globalDB}.g_languages WHERE core=1",
            []
        );
    }



    /**
     * was this password ever used by this User?
     *
     * @param integer $userID This User
     * @param string  $hash   MD5 hash of the password in question
     *
     * @return boolean
     */
    public function passEverUsed($userID, $hash)
    {
        $sql = "SELECT COUNT(*) AS cnt FROM {$this->tbl->pwHistory} \n"
            . "WHERE userID = :userID AND hash = :hash LIMIT 1";
        $bindArr = [':userID' => $userID, ':hash' => $hash];
        return $this->DB->fetchValue($sql, $bindArr);
    }


    /**
     * Add the given password (current?) to the historical password DB
     *
     * @param integer $userID    userID
     * @param string  $currentPw MD5 hash of password
     *
     * @return boolean
     */
    public function moveCurrentPwToHistorical($userID, $currentPw)
    {
        $sql = "INSERT INTO {$this->tbl->pwHistory} "
            . "SET userID = :userID, hash = :currentPw, expired = CURDATE()";
        $bindArr = [':userID' => $userID, ':currentPw' => $currentPw];
        $resQ = $this->DB->query($sql, $bindArr);
        return $resQ->rowCount();
    }


    /**
     * update the password in the user record
     *
     * @param integer $userID userID
     * @param string  $hash   md5 hash of new password
     *
     * @return boolean
     */
    public function updatePw($userID, $hash)
    {
        $confKey = $this->ConfReader->get('cms.acc');
        $bSql = "SELECT access FROM {$this->tbl->users} WHERE id = :uid LIMIT 1";
        $bindSql = [':uid' => $userID];
        $oldRec = $this->DB->fetchValue($bSql, $bindSql);
        $c64Dec = Crypt64::decrypt64($confKey, $oldRec, false);
        $access = unserialize($c64Dec);
        $sql = "UPDATE {$this->tbl->users} SET ";
        $sql .= "userpw = :hash, pwSetDate = CURDATE()";
        $access['pw'] = $hash;
        $access['st'] = 'active';
        $serAcc = serialize($access);
        $acc64 = Crypt64::encrypt64($confKey, $serAcc, false);
        $sql .= ", access = :acc64 ";
        $sql .= ", status = 'active' ";
        $sql .= "WHERE id = :userID \n"
            . "LIMIT 1";
        $bindArr = [':hash'   => $hash, ':acc64'  => $acc64, ':userID' => $userID];
        $resQ = $this->DB->query($sql, $bindArr);
        return $resQ->rowCount();
    }

    /**
     * Convert a list of countries non-standard ISO codes to standard ISO codes
     *
     * @param array $countries A list of countries with the Legacy convention of v and t keys
     *
     * @return array
     */
    private function convertAppISOsToStdISOs($countries)
    {
        $isoCountries = (Geography::getVersionInstance())->getISOCountriesMap();
        foreach ($countries as $idx => $row) {
            $isoIdx = array_search($row->v, array_column($isoCountries, 'code'));
            if ($isoIdx) {
                $countries[$idx]->v = $isoCountries[$isoIdx]['isoAlpha2Code'];
            }
        }
        return $countries;
    }

    /**
     * Get list of countries
     *
     * @param string  $langCode    language code
     * @param string  $selected    selected country option
     * @param boolean $standardISO Whether to return standard ISO codes or app ISO codes
     *
     * @return array of countries data
     */
    public function getCountriesList($langCode = 'EN_US', $selected = '', $standardISO = false)
    {
        $rtn = [];
        $geo = Geography::getVersionInstance(null, $this->tenantID);
        if (!empty($selected)) {
            $selected = $geo->getLegacyCountryCode($selected);
        }
        if ($list = $geo->countryListFormatted($selected, $langCode, false, 'object')) {
            foreach ($list as $idx => $row) {
                if ($row->v == $selected) {
                    $list[$idx]->s = 1;
                } else {
                    $list[$idx]->s = 0;
                }
            }
            if ($standardISO) {
                $list = $this->convertAppISOsToStdISOs($list);
            }
            $rtn = $list;
        }
        return $rtn;
    }

    /**
     * Get list of countries from isoDB
     * that have a matching callingCode
     *
     * @param string $selected Current calling code
     *
     * @return array of countries data
     */
    public function getCallingCodeCountriesList($selected = '')
    {
        $rtn = [];
        if ($list = (Geography::getVersionInstance())->getCallingCodeCountries()) {
            foreach ($list as $idx => $row) {
                if ($row->code == $selected) {
                    $list[$idx]->s = 1;
                } else {
                    $list[$idx]->s = 0;
                }
            }
            $rtn = $list;
        }
        return $rtn;
    }

    /**
     * Get list of states
     *
     * @param string $isoCode  country ISO
     * @param string $langCode language code
     * @param string $selected selected state option
     *
     * @return array of states data
     */
    public function getStatesList($isoCode, $langCode = 'EN_US', $selected = '')
    {
        $rtn = [];
        $geo = Geography::getVersionInstance(null, $this->tenantID);
        $country = $geo->getLegacyCountryCode($isoCode);
        $selected = $geo->getLegacyStateCode($selected, $country);
        if ($list = $geo->stateListFormatted($country, $selected, $langCode, false, 'object')) {
            foreach ($list as $idx => $row) {
                if ($row->v == $selected) {
                    $list[$idx]->s = 1;
                } else {
                    $list[$idx]->s = 0;
                }
            }
            $rtn = $list;
        }
        return $rtn;
    }




    /**
     * retrieve the MD5 password hash for userID=x
     *
     * @param integer $userID userID
     *
     * @return string
     */
    public function userPwGet($userID)
    {
        $sql = "SELECT userpw FROM {$this->tbl->users} WHERE id = :uid LIMIT 1";
        $bindArr = [':uid' => $userID];
        $res = $this->DB->fetchValue($sql, $bindArr);
        return $res;
    }


    /**
     * Get tenant status.
     *
     * @return string Returns one of pending|active|deactivated|deleted or '' if not TPM
     */
    public function getClientProfileStatus()
    {
        if (!$this->app->ftr->appIsTPM()) {
            return '';
        }
        $cpModel = new ClientProfile();
        $cp = $cpModel->findByID($this->tenantID);
        return $cp->get('CPstatus');
    }

    /**
     * Set tenant status to active.
     *
     * @return bool pass/fail
     */
    public function activateClientProfile()
    {
        $cpModel = new ClientProfile();
        $cp = $cpModel->findByID($this->tenantID);
        $cp->set('CPstatus', 'active');
        return $cp->save();
    }

    /**
     * Reset the current authy user associated with the user so
     * it can be restored later with createAuthyUser
     *
     * @param object $user The User instance of the logged in user
     *
     * @return void
     */
    private function resetCurrentAuthyUser($user)
    {
        $authyID = $user->get('authyID');
        $this->authenticator->deleteAuthyUser($authyID);
        $user->set('authyID', 0);
        $user->save();
    }

    /**
     * Create or restore authy user associated with phone number
     *
     * @param object $user        The User instance of the logged in user
     * @param string $phoneNumber The phone number to send codes to
     * @param string $countryISO  The ISO2 code for the phone number
     *
     * @return string The authyID of the user
     */
    public function createOrRestoreAuthyUser($user, $phoneNumber, $countryISO)
    {
        $this->resetCurrentAuthyUser($user);
        $callingCode = (Geography::getVersionInstance())->getCallingCodeFromISO2($countryISO);
        $authyID = $this->authenticator->createAuthyUser(
            $user->get('userEmail'),
            $phoneNumber,
            $callingCode
        );
        return $authyID;
    }
}
