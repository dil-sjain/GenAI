<?php
/**
 * Manage data for client table tpAddrs
 */

namespace Models\TPM\MultiAddr;

use Models\API\RESTdata;
use Models\Globals\Features\TenantFeatures;
use Models\TPM\TpProfile\TpProfile;
use Models\ThirdPartyManagement\ThirdParty;
use Lib\Support\Xtra;
use PDOStatement;
use Exception;

/**
 * Manage data for client table tpAddrs
 */
#[\AllowDynamicProperties]
class TpAddrs extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string 3P address table
     */
    protected $tbl = 'tpAddrs';

    /**
     * @var bool If true table is in client database
     */
    protected $tableInClientDB = true;

    /**
     * Get all tpAddrs records. Syncs embedded address to tpAddrs primary.
     *
     * @param int    $tpID    Is thirdPartyProfile.id
     * @param array  $cols    Columns to include
     * @param string $orderBy Optional sort order
     *
     * @return array assoc rows
     *
     * @throws Exception
     */
    public function getRecords($tpID, $cols = [], $orderBy = 'ORDER BY primaryAddr DESC,id ASC')
    {
        $this->syncTpAddrsFromEmbeddedAddress($tpID);
        return $this->selectMultiple($cols, ['tpID' => $tpID], $orderBy);
    }

    /**
     * Get active tpAddrs records. Syncs embedded address to tpAddrs primary.
     *
     * @param int    $tpID    Is thirdPartyProfile.id
     * @param array  $cols    Columns to include
     * @param string $orderBy Optional sort order
     *
     * @return array assoc rows
     *
     * @throws Exception
     */
    public function getActiveRecords($tpID, $cols = [], $orderBy = 'ORDER BY primaryAddr DESC,id ASC')
    {
        $records = $this->getRecords($tpID, $cols, $orderBy);
        $active = array_filter($records, fn($rec) => empty($rec['archived']));
        return array_values($active); // re-index
    }

    /**
     * Get All active tpAddrs records.
     *
     * @param array  $cols    Columns to include
     * @param boolean $swaggerUI If true, this is SwaggerUI
     * @param integer $page      Page number of results if tpID == 0
     * @param integer $perPage   Number of records per page if tpID == 0
     *
     * @return array assoc rows
     *
     * @throws Exception
     */
    public function getAllActiveThirdpartyAddresses($cols = [], $swaggerUI = false, $page = 1, $perPage = null)
    {
        $rtn = [];
        $restApiPerPageDefault = \Xtra::app()->confValues['cms']['restApiPerPageDefault'];
        $restApiPerPageMinimum = \Xtra::app()->confValues['cms']['restApiPerPageMinimum'];
        $restApiPerPageMaximum = \Xtra::app()->confValues['cms']['restApiPerPageMaximum'];
        $page = (int)$page;
        $perPage = (!is_null($perPage)) ? (int)$perPage : $restApiPerPageDefault;
        if ($perPage < $restApiPerPageMinimum || $swaggerUI) {
            $perPage = $restApiPerPageMinimum;
        } elseif ($perPage > $restApiPerPageMaximum) {
            $perPage = $restApiPerPageMaximum;
        }
        $tbl = $this->getTableName();
        $clientDB = $this->DB->getClientDB($this->clientID);
        $countSQL = "SELECT COUNT(1) FROM {$tbl} AS a\n"
            . "LEFT JOIN {$clientDB}.thirdPartyProfile AS t ON t.id = a.tpID\n"
            . "WHERE a.clientID = :clientID AND a.archived = 0 AND t.status IN('active','inactive')";
        $totalCount = $this->DB->fetchValue($countSQL, [':clientID' => $this->clientID]);
        if (!$totalCount) {
            return $rtn;
        }
        $pageCount = (int)ceil($totalCount / $perPage);
        $joinClause = "LEFT JOIN {$clientDB}.thirdPartyProfile AS t ON t.id = a.tpID\n";
        $whereClause = "WHERE a.id > :id AND a.clientID = :clientID AND a.archived = 0 "
            . "AND t.status IN('active','inactive')";
        $orderByClause = "ORDER BY a.id ASC LIMIT {$perPage}";
        $colSql = 'a.' . implode(', a.', $cols);
        $sql = "SELECT {$colSql} FROM {$tbl} AS a {$joinClause} {$whereClause} {$orderByClause}";
        $params = [':id' => 0, ':clientID' => $this->clientID];
        if ($page > 1) {
            // Get the highest id that can be used as the starting point to query the correct page
            $idSQL = "SELECT MAX(id) FROM\n"
                . "(SELECT a.id FROM {$tbl} AS a {$joinClause} {$whereClause} {$orderByClause}) AS id";
            for ($pageNumber = 1; $pageNumber < $pageCount; $pageNumber++) {
                if ($pageNumber < $page) {
                    $id = $this->DB->fetchValue($idSQL, $params);
                    $params[':id'] = $id;
                } else {
                    break;
                }
            }
        }
        $records = $this->DB->fetchAssocRows($sql, $params);
        $rtn = (new RESTdata())->paginateData($page, $perPage, $pageCount, $totalCount, $records);
        return $rtn;
    }

    /**
     * Get archived tpAddrs records. Syncs embedded address to tpAddrs primary.
     *
     * @param int    $tpID    Is thirdPartyProfile.id
     * @param array  $cols    Columns to include
     * @param string $orderBy Optional sort order (does not include primaryAddr)
     *
     * @return array assoc rows
     *
     * @throws Exception
     */
    public function getArchivedRecords($tpID, $cols = [], $orderBy = 'ORDER BY id')
    {
        $records = $this->getRecords($tpID, $cols, $orderBy);
        $archived = array_filter($records, fn($rec) => !empty($rec['archived']));
        return array_values($archived); // re-index
    }

    /**
     * There can only be one primary address per profile.
     * This method must not use transaction methods.
     *
     * @param int $id   Is tpAddrs.id of primary address record
     * @param int $tpID Is thirdPartyProfile.id
     *
     * @return PDOStatement|false|null
     */
    public function setExclusivePrimaryAddress($id, $tpID)
    {
        /*
         * ### NOTICE ###
         * Caller may be executing a transaction. This method, including
         * anything called within this method, MUST NOT USE
         * any of DB->beginTransaction(), DB->rollback(), DB->commit(), or DB->transact()
         */

        // make sure record exists and is a primary
        if ($this->selectValueByID($id, 'primaryAddr') !== 1) {
            return false;
        }
        // remove primaryAddr on all other addresses for this profile
        $params = [':cid' => $this->clientID, ':id' => $id, ':tpID' => $tpID];
        $sql = "UPDATE $this->tbl SET primaryAddr = 0\n"
            . "WHERE clientID = :cid AND tpID = :tpID AND id <> :id";

        return $this->DB->query($sql, $params);
    }

    /**
     * Update embedded address fields in profile from primary tpAddrs
     * This method must not use transaction methods.
     *
     * @param int      $tpID       Is thirdPartyProfile.id
     * @param int|null $id         (optional) tpAddrs.id of primary address, otherwise it must be looked up
     * @param int|null $authUserID (optional) authorized user ID
     *
     * @return false|PDOStatement
     *
     * @throws Exception
     */
    public function syncEmbeddedAddressFromTpAddrs($tpID, $id = null, $authUserID = null)
    {
        /*
         * ### NOTICE ###
         * Caller may be executing a transaction. This method, including
         * anything called within this method, MUST NOT USE
         * any of DB->beginTransaction(), DB->rollback(), DB->commit(), or DB->transact()
         */

        // is there a primaryAddr record in tpAddrs
        if (!empty($id)) {
            $addrRec = $this->selectByID($id, ['id', 'country', 'primaryAddr']);
            if (!$addrRec || $addrRec['primaryAddr'] !== 1) {
                $addrRec = null; // try harder
            }
        }
        if (empty($addrRec)) {
            $addrRec = $this->selectOne(['id', 'country'], ['tpID' => $tpID, 'primaryAddr' => 1]);
            if (empty($addrRec)) {
                return false;
            }
        }
        $country = $addrRec['country'];
        $id = $addrRec['id'];

        // update embedded fields in profile to catch any changes
        $tppData = new TpProfile($this->clientID);
        $tppTbl = $tppData->getTableName();
        $tppCountry = $tppData->selectValueByID($tpID, 'country');
        $sql = "UPDATE $tppTbl as p\n"
            . "INNER JOIN $this->tbl AS a ON p.id = a.tpID SET\n"
            . "  p.addr1 = a.addr1, p.addr2 = a.addr2, p.city = a.city,\n"
            . "  p.state = a.state, p.country = a.country, p.postcode = a.postcode\n"
            . "WHERE p.id = :tpID AND p.clientID = :cid AND a.id = :id";
        $rtn = $this->DB->query($sql, [':tpID' => $tpID, ':cid' => $this->clientID, ':id' => $id]);

        $feat = new TenantFeatures($this->clientID);
        if ($feat->tenantHasFeature(\Feature::TENANT_TPM_RISK, \Feature::APP_TPM)
            && $country !== $tppCountry
        ) {
            if (!isset($authUserID)) {
                $authUserID = Xtra::fallbackAuthUserID();
            }
            $params = ['authUserID' => $authUserID];
            (new ThirdParty($this->clientID, $params))->updateCurrentRiskAssessment($tpID);
        }
        return $rtn;
    }

    /**
     * Update or create primary address in tpAddrs table from
     * embedded address in profile when Multiple Address is not enabled.
     *
     * @param int $tpID thirdPartyProfile.id
     *
     * @return PDOStatement|null
     *
     * @throws Exception
     */
    public function syncTpAddrsFromEmbeddedAddress($tpID)
    {
        $addrTbl = $this->getTableName();
        $profileTbl  = (new TpProfile($this->clientID))->getTableName();

        $sql = "SELECT id FROM $addrTbl\n"
            . "WHERE clientID = :cid AND tpID = :tpID AND primaryAddr = 1 LIMIT 1";
        $params = [':tpID' => $tpID, ':cid' => $this->clientID];
        if ($id = $this->DB->fetchValue($sql, $params)) {
            // update existing primary address in tpAddrs
            $params[':id'] = $id;
            $sql = <<< EOT
UPDATE $addrTbl AS a
INNER JOIN $profileTbl AS p ON p.id = a.tpID SET includeInRisk = 1,
a.addr1 = p.addr1, a.addr2 = p.addr2, a.city = p.city,
a.state = p.state, a.country = p.country, a.postcode = p.postcode
WHERE a.id = :id AND p.id = :tpID AND p.clientID = :cid
EOT;
        } else {
            // create primary address in tpAddrs
            // $params are already set

            // Add internal code flag (Emerson)?
            $icVal = '';
            $siteVal = 'no';
            $feat = new TenantFeatures($this->clientID);
            $params[':icVal'] = $icVal;
            $params[':siteVal'] = $siteVal;


            $sql = <<< EOT
INSERT INTO $addrTbl
(clientID, tpID, createdAt, primaryAddr, includeInRisk,
addr1, addr2, city, state, country, postcode, siteName, internalCode)
SELECT clientID, id, NOW(), 1, 1,
addr1, addr2, city, state, country, postcode,
IF((:siteVal) = 'yes', legalName, ''), (:icVal) AS `icVal`
FROM $profileTbl WHERE id = :tpID AND clientID = :cid LIMIT 1
EOT;
        }
        return $this->DB->query($sql, $params);
    }

    /**
     * Merge addresses, keeping only non-identical addresses.
     * Does not replace primary address of winning (keep) profile.
     *
     * @param int $keepTpID 3P profile ID to keep
     * @param int $elimTpID ID of losing 3P profile ID
     *
     * @return int number of addresses moved over from losing profile
     *
     * @throws Exception
     */
    public function mergeAddresses($keepTpID, $elimTpID)
    {
        $rtn = 0; // assume no addresses moved over from losing profile
        $keepTpID = (int)$keepTpID;
        $elimTpID = (int)$elimTpID;
        $profileTbl = (new TpProfile($this->clientID))->getTableName();
        $existSql = "SELECT COUNT(*) FROM $profileTbl WHERE id IN($keepTpID, $elimTpID) LIMIT 2";
        if ($keepTpID === $elimTpID || ($this->DB->fetchValue($existSql) !== 2)) {
            return $rtn;
        }

        // Make sure primary addresses are in sync
        $this->syncTpAddrsFromEmbeddedAddress($keepTpID);
        $this->syncTpAddrsFromEmbeddedAddress($elimTpID);

        // Build comparison list of addresses
        // Ignore html entities, capitalization, whitespace, and punctuation
        $addrTbl = $this->getTableName();
        $sql = "SELECT id, LOWER(CONCAT_WS('', addr1, addr2, city, state, country, postcode, description)) AS cmp\n"
            . "FROM $addrTbl WHERE clientID = :cid AND tpID = :tpID ORDER BY id";
        $params = [':cid' => $this->clientID, ':tpID' => $keepTpID];
        $keep = Xtra::decodeAssocRowSet($this->DB->fetchAssocRows($sql, $params));
        $keepList = [];
        foreach ($keep as $row) {
            $hash = md5(preg_replace('/[[:space:][:punct:]]/', '', (string) $row['cmp']));
            $keepList[] = $hash;
        }

        // Compare each address from losing profile for sameness
        $params[':tpID'] = $elimTpID;
        $elim = Xtra::decodeAssocRowSet($this->DB->fetchAssocRows($sql, $params));
        $idsToMove = [];
        foreach ($elim as $row) {
            $hash = md5(preg_replace('/[[:space:][:punct:]]/', '', (string) $row['cmp']));
            if (!in_array($hash, $keepList)) {
                $idsToMove[] = $row['id'];
                $keepList[] = $hash;  // add only one like this one
            }
        }

        if ($idsToMove) {
            // update tpID and clear primaryAddr on all addresses to move
            $idCsv = implode(',', $idsToMove); // int list doesn't need escaping
            $sql = "UPDATE $addrTbl SET tpID = :tpID, primaryAddr = 0\n"
                . "WHERE id IN($idCsv)";
            if ($result = $this->DB->query($sql, [':tpID' => $keepTpID])) {
                $rtn = $result->rowCount();

                // Selectively move over userLog events
                // 172 Add 3P Address
                // 173 Update 3P Address
                // 174 Archive 3P Address
                $sql = "UPDATE userLog SET tpID = :keep WHERE tpID = :elim\n"
                    . "AND eventID IN(172,173,174) AND clientID = :cid\n"
                    . "AND (brief LIKE :idRef\n"
                    . "  OR (details IS NOT NULL AND details LIKE :idRef2)\n"
                    . ")";
                $params = [
                    ':cid' => $this->clientID,
                    ':keep' => $keepTpID,
                    ':elim' => $elimTpID
                ];
                foreach ($idsToMove as $id) {
                    $idRef = "%(#{$id})%";
                    $params[':idRef'] = $idRef;
                    $params[':idRef2'] = $idRef;
                    $this->DB->query($sql, $params);
                }
            }
        }

        return $rtn;
    }

    /**
     * Delete an address from the tpAddrs table
     *
     * @param int $id tpAddrs.id
     *
     * @return null|PDOStatement
     */
    public function deleteAddress($id)
    {
        return $this->DB->query(
            "DELETE FROM tpAddrs WHERE id = :id AND clientID = :clientID",
            [':id' => (int)$id, ':clientID' => $this->clientID]
        );
    }
}
