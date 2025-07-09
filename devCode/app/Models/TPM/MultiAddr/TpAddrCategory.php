<?php
/**
 * Manage data for client table tpAddrCategory
 */

namespace Models\TPM\MultiAddr;

/**
 * Manage data for client table tpAddrs
 */
#[\AllowDynamicProperties]
class TpAddrCategory extends \Models\BaseLite\RequireClientID
{
    protected $tbl = 'tpAddrCategory';
    protected $tableInClientDB = true;

    /**
     * Return rows with html entities removed
     *
     * @param array  $cols    Columns to include
     * @param string $orderBy Optional sort order
     *
     * @rturn array assoc rows
     */
    public function getRecords($cols = [], $orderBy = 'ORDER BY name')
    {
        $records = $this->selectMultiple($cols, [], $orderBy);
        if (empty($records)) {
            if ($this->createDefaults()) {
                // There should be some records now
                $records = $this->selectMultiple($cols, [], $orderBy);
            }
        }
        return $records;
    }

    /**
     * Add default address categories for client
     */
    protected function createDefaults()
    {
        $tbl = $this->getTableName();
        $sql = "INSERT INTO $tbl (createdAt, clientID, active, name) VALUES\n"
            . "(NOW(), :cid1, 1, 'Business'),\n"
            . "(NOW(), :cid2, 1, 'Legal'),\n"
            . "(NOW(), :cid3, 1, 'Physical');";
        $params = [
            ':cid1' => $this->clientID,
            ':cid2' => $this->clientID,
            ':cid3' => $this->clientID,
        ];
        return $this->DB->query($sql, $params);
    }
}
