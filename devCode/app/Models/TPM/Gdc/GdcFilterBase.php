<?php
/**
 * Provide access to GDC filter exclusions
 *
 * @see public_html/cms/includes/php/Models/TPM/Gdc/GdcFilterBase.php
 */

namespace Models\TPM\Gdc;

/**
 * Read/write access to gdc filter tables
 *
 * @keywords GDC, GDC filter, filter, bse, model base
 */
#[\AllowDynamicProperties]
abstract class GdcFilterBase
{
    /**
     * @var string table name (required in sub class)
     */
    protected $tbl = null;

    /**
     * @var string id column name (required in sub class)
     */
    protected $idCol = null;

    /**
     * @var object MySqlPdo instance
     */
    protected $DB = null;

    /**
     * @var integer Client ID
     */
    protected $clientID = 0;

    /**
     * @var string Client's database name
     */
    protected $clientDB = '';

    /**
     * Constructor
     *
     * @param integer $clientID clientProfile.id
     *
     * @return void
     */
    public function __construct($clientID)
    {
        if (!is_string($this->tbl) || !is_string($this->idCol)) {
            throw new \Exception('$tbl and $idCol must be defined in extended class.');
        }
        \Xtra::requireInt($clientID);
        $this->clientID = $clientID;
        $this->DB = \Xtra::app()->DB;
        $this->clientDB = $this->DB->getClientDB($this->clientID);
    }

    /**
     * Count records matching clientID, tpType and tpTypeCategory
     *
     * @param integer $tpType tpType.id
     * @param integer $tpCat  tpTypeCategory.id
     *
     * @return integer record count
     */
    public function countRecords($tpType, $tpCat)
    {
        $sql = "SELECT COUNT($this->idCol) FROM $this->clientDB.$this->tbl\n"
            . "WHERE tpTypeID = :tpType AND tpCatID = :tpCat AND clientID = :clientID";
        $params = [':tpType' => $tpType, ':tpCat' => $tpCat, ':clientID' => $this->clientID];
        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Returns array of listIDs matching clientID, tpType, and tyTypeCategory
     *
     * @param $tpType
     * @param $tpCat
     *
     * @return mixed
     */
    public function getRecordsByTypeAndCategory($tpType, $tpCat)
    {
        $sql = "SELECT {$this->idCol} FROM {$this->clientDB}.{$this->tbl}\n"
            . "WHERE tpTypeID = :tpType AND tpCatID = :tpCat AND clientID = :clientID ORDER BY {$this->idCol} ASC";
        $params = [':tpType' => $tpType, ':tpCat' => $tpCat, ':clientID' => $this->clientID];
        return $this->DB->fetchValueArray($sql, $params);
    }

    /**
     * Get effective filter csv. Tries type + cat, then type + 0, then 0 + 0.
     * Uses first non-empty result.
     * Limitation: Can't define no exclusions for a type + cat if type + 0 or 0 + 0 has exclusions
     *             Same for type + 0
     *
     * @param integer $tpType tpType.id
     * @param integer $tpCat  tpTypeCategory.id
     *
     * @return string csv id values to exclude
     */
    public function getEffectiveFilter($tpType, $tpCat)
    {
        $tpType = (int)$tpType;
        $tpCat = (int)$tpCat;
        $ids = [];
        if ($tpType > 0 && $tpCat > 0) {
            $ids = $this->getFilterIDs($tpType, $tpCat);
        }
        if (empty($ids) && $tpType > 0) {
            $ids = $this->getFilterIDs($tpType, 0); // filter on type only
        }
        if (empty($ids)) {
            $ids = $this->getFilterIDs(0, 0); // default filter for all 3P profiles
        }
        return implode(',', $ids);
    }

    /**
     * Get all excluded ID values
     *
     * @param integer $tpType  tpType.id
     * @param integer $tpCat   tpTypeCategory.id
     * @param string  $limitTo csv list of ID values to search in
     *
     * @return array of ID values
     */
    public function getFilterIDs($tpType, $tpCat, $limitTo = false)
    {
        $limitCond = 1;
        if (!empty($limitTo) && preg_match('/^\d+(,\d+)*$/', $limitTo)) {
            $limitCond = "$this->idCol IN($limitTo)";
        }
        $sql = "SELECT $this->idCol FROM $this->clientDB.$this->tbl\n"
            . "WHERE $limitCond AND tpTypeID = :tpType AND tpCatID = :tpCat AND clientID = :clientID\n"
            . "ORDER BY $this->idCol";
        $params = [':tpType' => $tpType, ':tpCat' => $tpCat, ':clientID' => $this->clientID];
        return $this->DB->fetchValueArray($sql, $params);
    }

    /**
     * Update filter records
     *
     * @param integer $tpType tpType.id
     * @param integer $tpCat  tpTypeCategory.id
     * @param string  $cks    csv id:0|1 pairs
     *
     * @return integer record count
     */
    public function updateFilter($tpType, $tpCat, $cks)
    {
        if (preg_match('/^(\d+:(0|1))(,\d+:(0|1))*$/', $cks)) {
            $checks = explode(',', $cks);
            foreach ($checks as $c) {
                [$id, $ck] = explode(':', $c);
                $ck = (int)$ck;
                $params = [
                    ':tpType' => $tpType,
                    ':tpCat' => $tpCat,
                    ':clientID' => $this->clientID,
                    ':itemID' => $id,
                ];
                if ($ck) {
                    // insert if missing
                    $sql = "SELECT $this->idCol FROM $this->clientDB.$this->tbl\n"
                        . "WHERE $this->idCol = :itemID AND clientID = :clientID\n"
                        . "AND tpTypeID = :tpType AND tpCatID = :tpCat LIMIT 1";
                    if ($this->DB->fetchValue($sql, $params) != $id) {
                        $sql = "INSERT INTO $this->clientDB.$this->tbl SET\n"
                            . "clientID = :clientID,\n"
                            . "$this->idCol = :itemID,\n"
                            . "tpTypeID = :tpType,\n"
                            . "tpCatID = :tpCat";
                        $this->DB->query($sql, $params);
                    }
                } else {
                    // remove if present
                    $sql = "DELETE FROM $this->clientDB.$this->tbl\n"
                        . "WHERE $this->idCol = :itemID AND clientID = :clientID\n"
                        . "AND tpTypeID = :tpType AND tpCatID = :tpCat";
                    $this->DB->query($sql, $params);
                }
            }
        }
        return $this->countRecords($tpType, $tpCat);
    }
}
