<?php
/**
 * Provide acess to 3P Profile
 *
 * This model, TpProfile, was first commited in Dec 2016 for thirdPartyProfile
 * A year later (Jan 2018), another BaseLite model was created for thirdPartyProfile
 * Ideally, there should only be one model derived from BaseLite.
 */

namespace Models\TPM\TpProfile;

use Models\TPM\MultiAddr\TpAddrs;
use Models\BaseLite\RequireClientID;
use Lib\Support\Xtra;
use InvalidArgumentException;
use Exception;

/**
 * Read/write access to thirdPartyProfile
 *
 * @keywords 3p profie
 */
#[\AllowDynamicProperties]
class TpProfile extends RequireClientID
{
    /**
     * @var string table name (required by base class)
     */
    protected $tbl = 'thirdPartyProfile';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;

    /**
     * Override base class method
     *
     * @param mixed $tpID      Is thiredPartyProfile.id
     * @param array $setValues column / value pairs to set
     *
     * @return mixed false or rowCount()
     */
    public function updateByID($tpID, array $setValues)
    {
        $syncTpAddrs = $this->shouldSyncTpAddrs($setValues);
        $rtn = parent::updateByID($tpID, $setValues);
        if ($rtn && $syncTpAddrs) {
            (new TpAddrs($this->clientID))->syncTpAddrsFromEmbeddedAddress($tpID);
        }
        return $rtn;
    }

    /**
     * Override base class insert method
     *
     * @param array $setValues column / value pairs to set
     *
     * @return false | thirdPartyProfile.id
     */
    public function insert(array $setValues)
    {
        if ($tpID = parent::insert($setValues)) {
            (new TpAddrs($this->clientID))->syncTpAddrsFromEmbeddedAddress($tpID);
        }
        return $tpID;
    }

    /**
     * Determine if address fields are affected
     *
     * @param arryay $setValues Values to be set
     *
     * @return bool
     */
    protected function shouldSyncTpAddrs(array $setValues)
    {
        static $addrFlds = [];
        if (empty($addrFlds)) {
            $addrFlds = [
                'addr1',
                'addr2',
                'city',
                'state',
                'country',
                'postcode',
            ];
        }
        $sync = false;
        foreach ($addrFlds as $fld) {
            if (property_exists((object)$setValues, $fld)) {
                $sync = true;
                break;
            }
        }
        return $sync;
    }

    /**
     * Get thirdPartyProfile record by id or userTpNum
     *
     * @param int|string $reference thirdPartyProfile.id or thirdPartyProfile.userTpNum
     * @param array      $columns   Which columns to return
     *
     * @return array|false
     */
    public function getProfileByReference($reference, $columns = [])
    {
        if (is_string($reference)) {
            if (preg_match('/^\d+$/', $reference)) {
                $reference = (int)$reference;
            }
        } elseif (!is_int($reference)) {
            throw new InvalidArgumentException('First argument must be integer or string');
        }
        try {
            if (is_int($reference)) {
                $profile = $this->selectByID($reference, $columns);
            } else {
                $profile = $this->selectOne($columns, ['userTpNum' => $reference]);
            }
        } catch (Exception $e) {
            Xtra::track([
                'event' => 'Get 3P profile record by reference',
                'error' => $e->getMessage(),
            ]);
        }
        return !empty($profile) ? $profile : false;
    }
}
