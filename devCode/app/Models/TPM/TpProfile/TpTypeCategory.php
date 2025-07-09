<?php
/**
 * Provide acess to 3P Profile Category (related to 3P Profile Type)
 */

namespace Models\TPM\TpProfile;

use Lib\Support\Format;
use Lib\Validation\Validator\CsvIntList;

/**
 * Read/write access to tpTypeCategory
 *
 * @keywords 3p category
 */
#[\AllowDynamicProperties]
class TpTypeCategory extends \Models\BaseLite\RequireClientID
{
    /**
     * Table name (required by base class)
     *
     * @var string
     */
    protected $tbl = 'tpTypeCategory';

    /**
     * @var boolean flag table in clinetDB
     */
    protected $tableInClientDB = true;

    /**
     * Protect against orphaned category
     *
     * @param integer $typeID tpType.id
     * @param boolean $active active flag
     *
     * @return array of id/name assoc rows
     */
    public function getCleanCategoriesByType($typeID, $active = false)
    {
        $addon = $active ? ' AND c.active = 1 ' : '';
        $sql = "SELECT c.id, c.name FROM $this->tbl AS c \n"
            . "LEFT JOIN $this->clientDB.tpType AS t ON (t.id = c.tpType AND t.clientID = :cliID) \n"
            . "WHERE c.tpType = :typeID AND c.clientID = :clientID $addon \n"
            . "AND t.id IS NOT NULL ORDER BY c.name ASC";
        $params = [':typeID' => $typeID, ':cliID' => $this->clientID, ':clientID' => $this->clientID];
        return $this->DB->fetchAssocRows($sql, $params);
    }

    /**
     * Validate list of categories belong to tpType and to client
     *
     * @param integer $typeID    tpType.id
     * @param mixed   $catIdList array or csv string of category ID values to test
     *
     * @return boolean true if all are valid, otherwise, false
     */
    public function validateCategoryList($typeID, mixed $catIdList)
    {
        if (is_array($catIdList)) {
            $csvList = implode(',', $catIdList);
        } elseif (is_string($catIdList)) {
            $csvList = $catIdList;
        } else {
            return false;
        }
        if (!(new CsvIntList($csvList))->isValid()) {
            return false;
        }
        // $csvList is valid and can be used directly in SQL safely
        $sql = "SELECT id FROM $this->tbl\n"
            . "WHERE clientID = :clientID AND tpType = :typeID\n"
            . "AND id IN($csvList)";
        $cats = $this->DB->fetchValueArray($sql, [':clientID' => $this->clientID, ':typeID' => $typeID]);
        return (count($cats) === count(explode(',', $csvList)));
    }

    /**
     * Get combined list of tpCategories, includeing tpType.id and tpType.name
     *
     * @return array of tpType.id:tpTypeCategofi.id, tpType.name:tpTypeCategory.name elements
     */
    public function getCategoriesWithType()
    {
        $sql = "SELECT CONCAT('', t.id, ':', c.id) AS `ref`,\n"
            . "CONCAT(t.name, ':', c.name) AS `fullName`\n"
            . "FROM $this->tbl AS c\n"
            . "INNER JOIN tpType AS t ON t.id = c.tpType\n"
            . "WHERE c.clientID = :cid AND t.clientID = :cid2 ORDER BY t.name, c.name";
        return $this->DB->fetchAssocRows($sql, [':cid' => $this->clientID, ':cid2' => $this->clientID]);
    }
}
