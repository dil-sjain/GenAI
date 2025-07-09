<?php
/**
 * Multiple Address Data Handler for 3P Profile Summary
 *
 * This class was specifically written for Controllers\TPM\MultiAddr\MultipleAddress.
 * Its public methods may not be suitable for re-use in other contexts. If they are
 * re-used the caller must assume responsibility for argument validation and formatting.
 */

namespace Models\TPM\MultiAddr;

use Models\LogData;
use Models\Globals\Geography;

 /**
  * Multiple Address Data
  */
#[\AllowDynamicProperties]
class MultipleAddress
{
    /**
     * @var \Skinny\Skinny|null Application framework instance
     */
    protected $app = null;

    /**
     * @var int|null TPM tenant ID
     */
    protected $clientID = null;

    /**
     * @var null|MySqlPdo Instance of MySqlPdo
     */
    protected $DB = null;

    /**
     * @var null|int users.id for audit log
     */
    protected $userID = null;

    /**
     * @var null|array Address categories, client-defined
     */
    protected $categories = null;

    /**
     * @var Geography|null Instance of Geography
     */
    protected $geo = null;

    /**
     * Create instance and set class properties
     *
     * @param $clientID TPM tenant ID
     */
    public function __construct($clientID)
    {
        $this->clientID = (int)$clientID;
        $app = \Xtra::app();
        $this->DB = $app->DB;
        $this->userID = $app->ftr->user;
        $this->app = $app;
        $this->geo = Geography::getVersionInstance(null, $this->clientID);
    }

    /**
     * Get address list, address categories, country/state lookups for 3p Summary page
     *
     * @param int  $tpID         Is thirdPartyProfile.id
     * @param bool $convEntities If true, convert html entities to characters (set to true for React)
     *
     * @return array ['cats', 'addrs', 'lookups']
     */
    public function getSummaryInfo($tpID, $convEntities = false)
    {
        $cats = (new TpAddrCategory($this->clientID))->getRecords(['id', 'name', 'active']);
        $addresses = $this->getAllAddresses($tpID, $convEntities);
        if ($convEntities) {
            $cats = \Xtra::decodeAssocRowSet($cats);
            $addresses = \Xtra::decodeAssocRowSet($addresses);
        }
        $lookups = $this->getAddressLookups($addresses, $convEntities);
        $riskInfo = $this->getRiskInfo($tpID);
        return [
            'cats' => $cats,
            'addrs' => $addresses,
            'lookups' => $lookups,
            'risk' => $riskInfo,
        ];
    }

    /**
     * Get address list
     *
     * @param int  $tpID         Is thirdPartyProfile.id
     * @param bool $convEntities If true, convert html entities to characters (set to true for React)
     *
     * @return array ['cats', 'addrs', 'lookups']
     */
    public function getAllAddresses($tpID, $convEntities = false)
    {
        $addresses = (new TpAddrs($this->clientID))->getRecords(
            $tpID,
            [
                'id',
                'addr1',
                'addr2',
                'city',
                'country',
                'state',
                'postcode',
                'description',
                'addrCatID',
                'primaryAddr',
                'includeInRisk',
                'archived',
            ]
        );
        if ($convEntities) {
            $addresses = \Xtra::decodeAssocRowSet($addresses);
        }
        return $addresses;
    }

    /**
     * Get risk tier, score and timestamp appropriate to enabled features and user permissions
     *
     * @param int $tpID Is thirdPartyProfile.id
     *
     * @return array ['level' => (string), 'title' => (string)]
     */
    protected function getRiskInfo($tpID)
    {
        // Get risk level and title, as appropriate for user
        $riskLevel = $riskTitle = $riskTime = $riskScore = '';
        // b3pRisk (TENANT_TPM_RISK), title if accRiskInv (CONFIG_RISK_NVENTORY)
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            // get risk row
            $sql = <<< EOT
SELECT ra.normalized AS rating, LEFT(ra.tstamp, 10) AS `tstamp`, rt.tierName
FROM thirdPartyProfile AS tp
INNER JOIN (riskAssessment AS ra, riskTier AS rt)
ON (ra.tpID = tp.id AND ra.model = tp.riskModel AND ra.status = 'current' AND rt.id = ra.tier)
WHERE tp.id = :tpID AND tp.clientID = :cid LIMIT 1
EOT;
            $params = [':tpID' => $tpID, ':cid' => $this->clientID];
            if ($riskRow = $this->DB->fetchAssocRow($sql, $params)) {
                $riskLevel = $riskRow['tierName'];
                if ($this->app->ftr->has(\Feature::CONFIG_RISK_INVENTORY)) {
                    $riskTitle = "Rating: {$riskRow['rating']} ({$riskRow['tstamp']})";
                }
            }
        }
        return [
            'level' => $riskLevel,
            'title' => $riskTitle,
        ];
    }

    /**
     * Resolve names for country and state values in addresses
     *
     * @param array $addresses    Address data from tpAddrs
     * @param bool  $convEntities Convert html entities to characters if true
     *
     * @return array ['countryLookup', 'stateLookup']
     */
    public function getAddressLookups($addresses, $convEntities = false)
    {
        // Build lists from country and state values in addresses
        $stateList = [];
        $countryList = [];
        foreach ($addresses as $addr) {
            $c = $addr['country'];
            if (empty(trim((string) $c))) {
                continue;  // this shouldn't happen because country is a required field
            }
            if (!in_array($c, $countryList)) {
                $countryList[] = $c;
            }
            if (!array_key_exists($c, $stateList)) {
                $stateList[$c] = [];
            }
            $s = $addr['state'];
            if (empty(trim((string) $s))) {
                continue;
            }
            if (!in_array($s, $stateList[$c])) {
                $stateList[$c][] = $s;
            }
        }

        $countryLookup = $stateLookup = [];
        if (!empty($countryList)) {
            foreach ($countryList as $code) {
                $countryLookup[] = [
                    'country' => $code,
                    'name' => $this->geo->getLegacyCountryName($code),
                ];
            }
            if ($convEntities) {
                $countryLookup = \Xtra::decodeAssocRowSet($countryLookup);
            }
        }
        if (!empty($stateList)) {
            foreach ($stateList as $country => $states) {
                foreach ($states as $stateCode) {
                    $stateLookup[] = [
                        'country' => $country,
                        'state' => $stateCode,
                        'name' => $this->geo->getLegacyStateName($stateCode, $country),
                    ];
                }
            }
            if ($convEntities) {
                $stateLookup = \Xtra::decodeAssocRowSet($stateLookup);
            }
        }
        return [
            'stateLookup' => $stateLookup,
            'countryLookup' => $countryLookup,
        ];
    }

    /**
     * Get array of iso_code, name records
     *
     * @param bool $convEntities If true, replace entities with character
     * @param int  $tpID         thirdPartyProfile.id
     *
     * @return array
     */
    public function getCountries($convEntities = false, $tpID = 0)
    {
        $lookups = [];
        if ($tpID > 0) {
            $lookups = $this->getAddressLookups($this->getAllAddresses($tpID));
        }
        $langCode = $this->app->session->languageCode ?? 'EN_US';
        $keys = ['codeAlias' => 'iso', 'nameAlias' => 'name'];
        $countries = $this->geo->countryListFormatted('', $langCode, false, 'assoc', $keys);
        foreach ($lookups['countryLookup'] as $lookup) {
            $found = false;
            foreach ($countries as $c) {
                if ($c['iso'] === $lookup['country']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $countries[] = ['iso' => $lookup['country'], 'name' => $lookup['name']];
            }
        }
        if ($convEntities) {
            $countries = \Xtra::decodeAssocRowSet($countries);
        }
        return $countries;
    }

    /**
     * Get array of abbrev, name records for a country's states
     *
     * @param string $country      ISO code (2-char) for country
     * @param bool   $convEntities If true, replace entities with character
     * @param int    $tpID         thirdPartyProfile.id
     *
     * @return array ['country' => requested country, 'states =>states_array]
     */
    public function getStatesForCountry($country, $convEntities = false, $tpID = 0)
    {
        $lookups = [];
        if ($tpID > 0) {
            $lookups = $this->getAddressLookups($this->getAllAddresses($tpID));
        }
        $langCode = $this->app->session->languageCode ?? 'EN_US';
        $country = $this->geo->getLegacyCountryCode($country);
        $keys = ['codeAlias' => 'state', 'nameAlias' => 'name'];
        $states = $this->geo->stateListFormatted($country, '', $langCode, false, 'assoc', $keys);
        foreach ($lookups['stateLookup'] as $lookup) {
            if ($lookup['country'] === $country) {
                $found = false;
                foreach ($states as $state) {
                    if ($state['state'] === $lookup['state']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $states[] = ['state' => $lookup['state'], 'name' => $lookup['name']];
                }
            }
        }
        if ($convEntities) {
            $states = \Xtra::decodeAssocRowSet($states);
        }

        // include country
        return ['country' => $country, 'states' => $states];
    }

    /**
     * Get name and active status of address category by id
     *
     * @param int $id tpAddrCategory.id
     *
     * @return mixed array | null
     */
    private function lookupCategory($id)
    {
        if (empty($this->categories)) {
            // populate class properties
            $this->categories = [];
            $this->categoryStatus = [];
            $cats = (new TpAddrCategory($this->clientID))->getRecords(['id', 'name', 'active']);
            foreach ($cats as $cat) {
                $this->categories[$cat['id']] = ['name' => $cat['name'], 'active' => $cat['active']];
            }
        }
        if (array_key_exists($id, $this->categories)) {
            return $this->categories[$id];
        }
        return null;
    }

    /**
     * Update or insert tpAddrs record
     * Caller must validate arguments (e.g., app/Controllers/TPM/MultiAddr/MultipleAddress.php)
     *
     * @param int   $id      Is tpAddrs.id
     * @param array $sets    Values to set
     * @param array $params  SQL placeholers
     * @param array $origRec Original tpAddr record before update
     *
     * @return false | PDOStatement
     */
    public function upsertAddress($id, $sets, $params, $origRec = [])
    {
        $tpAddrs = new TpAddrs($this->clientID);
        $tpAddrsTbl = $tpAddrs->getTableName();
        $logger = new LogData($this->clientID, $this->userID);
        $isPrimary = (bool)$params[':primaryAddr'];
        // Set up sql, params and logMsg
        if ($id) {
            $setList = implode(', ', $sets);
            // update
            $sql = "UPDATE $tpAddrsTbl SET $setList\n"
            . "WHERE id = :id AND tpID = :tpID AND clientID = :cid LIMIT 1";
            $params[':id'] =  $id;
            // compare to orig
            $logMsg = "Address (#{$id})";
            $changes = [];
            $flds = [
                'addrCatID',
                'addr1',
                'addr2',
                'city',
                'state',
                'country',
                'postcode',
                'description',
                'primaryAddr',
            ];
            foreach ($flds as $fld) {
                if ($origRec[$fld] !== $params[':' . $fld]) {
                    if ($fld !== 'primaryAddr') {
                        if ($fld === 'addrCatID') {
                            $oldCatProps = $this->lookupCategory($origRec[$fld]);
                            $newCatProps = $this->lookupCategory($params[':' . $fld]);
                            $oldCat = $oldCatProps ? $oldCatProps['name'] : "({$origRec[$fld]})";
                            $newCat = $newCatProps ? $newCatProps['name'] : "({$params[':' . $fld]})";
                            $changes[] = "Category: `$oldCat` =&gt; `$newCat`";
                        } else {
                            $changes[] = "$fld: " . '`' . $origRec[$fld] . '`'
                                . " =&gt; " . '`' . $params[':' . $fld] . '`';
                        }
                    } elseif ($params[':primaryAddr']) {
                        $changes[] = 'NEW PRIMARY';
                    }
                }
            }
            if ($changes) {
                $logMsg .= ' &mdash; ' . implode('; ', $changes);
            }
            if (strpos($sql, 'archived = 1')) {
                if ($changes) {
                    $logMsg .= "; ARCHIVED";
                } else {
                    $logMsg .= " &mdash; ARCHIVED";
                }
            }
        } else {
            // insert
            $logMsg = 'Address (#???)'; // complete after new ID is known
            $sets[] = "clientID = :cid";
            $sets[] = "tpID = :tpID";
            $sets[] = "createdAt = NOW()";
            $setList = implode(', ', $sets);
            $sql = "INSERT INTO $tpAddrsTbl SET " . $setList;
        }
        if ($isPrimary) {
            $params[':includeInRisk'] = 1;  // force this on primary
        }

        // Transaction values
        $rtn = false;
        $funcObj = (object)compact('rtn', 'id', 'sql', 'params', 'isPrimary', 'logMsg');


        // Transaction statements
        $func = function ($db, $o, &$finish) use ($logger, $tpAddrs) {
            $logEvent = 173; // Update 3P Address

            if (($o->rtn = $db->query($o->sql, $o->params)) && $o->rtn->rowCount()) {
                $logged = $primed = $synced = true;
                $newId = 0;
                if (empty($o->id)) {
                    // Now $logMsg can be constructed
                    $newId = $db->lastInsertId();
                    $o->logMsg = "Address (#{$newId})";
                    if (strpos($o->sql, 'archived = 1')) {
                        $logEvent = 174; // Archive 3P Address
                        $o->logMsg .= " &mdash; ARCHIVED";
                    } else {
                        $logEvent = 172; // Add 3P Address
                    }
                } elseif (strpos($o->sql, 'archived = 1')) {
                    $logEvent = 174; // Archive 3P Address
                    $o->logMsg .= " &mdash; ARCHIVED";
                }
                // Log it - log method does not begin or end a transactoin
                $tpID = (int)$o->params[':tpID'];
                $logged = $logger->save3pLogEntry(
                    $logEvent, // event #174 archive/unarchive
                    $o->logMsg,
                    $tpID
                );
                // sync primary address
                if ($o->isPrimary) {
                    // Note: as required, the foloowing methods do NOT begin or end a transaction
                    $targetId = $newId ?: $o->id;
                    $primed = $tpAddrs->setExclusivePrimaryAddress($targetId, $tpID);
                    $synced = $tpAddrs->syncEmbeddedAddressFromTpAddrs($tpID, $targetId);
                }
                if ($logged && $primed && $synced) {
                    $db->commit();
                    $finish = true;
                } else {
                    $db->rollback();
                }
            } else {
                // no record affected; nothing to do , so get out
                $db->rollback();
                $finish = true;
            }
        };

        // Exectue transaction
        if ($this->DB->transact($func, $funcObj)) {
            return $funcObj->rtn;
        }
        return false;
    }

    /**
     * Re-instate an archived address to the active address list
     *
     * @param int $tpID Is thirdPartyProfileAddress.id
     * @param int $id   Is tpAddrs.id
     *
     * @return false | PDOStatement
     */
    public function restoreAddress($tpID, $id)
    {
        $tpAddrsTbl = (new TpAddrs($this->clientID))->getTableName();
        $sql = "UPDATE $tpAddrsTbl SET archived = 0\n"
            . "WHERE id = :id AND archived = 1 AND primaryAddr <> 1\n"
            . "AND tpID = :tpID AND clientID = :cid LIMIT 1";
        $params = [
            ':id' => $id,
            ':tpID' => $tpID,
            ':cid' => $this->clientID,
        ];
        $rtn = $this->DB->query($sql, $params);
        if ($rtn && $rtn->rowCount() === 1) {
            (new LogData($this->clientID, $this->userID))->save3pLogEntry(
                174, // event #174 archive/unarchive
                "Address (#$id) &mdash; RESTORED",
                $tpID
            );
        }
        return $rtn;
    }

    /**
     * Delete an address from the tpAddrs table
     *
     * @param int $id tpAddrs.id
     *
     * @return null|\PDOStatement
     */
    public function deleteAddress($id)
    {
        return (new TpAddrs($this->clientID))->deleteAddress($id);
    }
}
