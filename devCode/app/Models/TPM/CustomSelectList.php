<?php
/**
 * Manage records for customSelectList
 */

namespace Models\TPM;

/**
 * Manage records for customSelectList
 *
 * Parent extends \Models\BaseLite\RequireClientID
 *
 * Methods inherited from parent
 *   public function getCustomSelectLists()
 *   public function validListItem($listName, $itemID)
 *   public function namesFromCsv($itemList, $separator = ':=:')
 *   public function getCustomSelectListItems($listName, $inSwagger)
 */
class CustomSelectList extends \Models\API\Endpoints\CustomSelectList
{
    /**
     * Get customSeletList items for one listName
     *
     * @param string $listName Name of hte list
     *
     * @return array Item records
     */
    public function customSelectListItems($listName)
    {
        if (empty($listName)) {
            return [];
        }
        return parent::getCustomSelectListItems($listName, false);
    }

    /**
     * Test if item is valid list member
     *
     * @param integer $iid      Item id
     * @param string  $listName Name of the list
     *
     * @return boolean
     */
    public function validListItemByID($iid, $listName)
    {
        if (empty($iid) || empty($listName)) {
            return false;
        }
        $rtn = false;
        if ($item = $this->selectByID($iid, ['listName'])) {
            $rtn = ($listName === $item['listName']);
        }
        return $rtn;
    }
}
