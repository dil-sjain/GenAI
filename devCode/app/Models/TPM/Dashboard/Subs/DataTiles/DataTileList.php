<?php
/**
 * Class to represent a list of data to be returned by a Data Tile for display on the dashboard Data Ribbon
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Class DataTileList
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class DataTileList
{
    /**
     * @var int $count Total number of items found.
     */
    private $count      = 0;

    /**
     * @var array $items An array of the items found.
     */
    private $items      = [];

    /**
     * @var array $fieldTypes An array of field types for use with data tables
     */
    private $fieldTypes = [];

    /**
     * @var string $url The URL to use for tile links
     */
    private $url        = '';

    /**
     * @var bool|array $displayFields Array of fields and their sizes to display in data table. If false, then no
     * fields should be displayed
     */
    private $displayFields = false;

    /**
     * @var int $clickType Type of click action the tile can use
     */
    private $clickType  = DataTileBase::CLICK_NO;

    /**
     * Set the total item count
     *
     * @param int $count Set total count of items (may be larger than actual list of items)
     *
     * @return void
     */
    public function setCount($count)
    {
        $this->count = (int)$count;
    }

    /**
     * Retrieve the total item count. This number may be larger than actual number of items found.
     *
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * Set list of items found. Must be an array.
     *
     * @param array $items Array containing the items found.
     *
     * @return void
     */
    public function setItems($items)
    {
        if (!is_array($items)) {
            $items = [];
        } else {
            foreach ($items as $key => $item) {
                foreach ($item as $k => $v) {
                    $items[$key][$k] = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', (string) $v);
                }
            }
        }

        $this->items = $items;
    }

    /**
     * Get the array of items found. An empty array is returned if none were provided.
     *
     * @return array
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * Set the field types to be defined for the Data Tile
     *
     * @param array $fieldTypes An array of datatable field types to be defined
     *
     * @return void
     */
    public function setFieldTypes($fieldTypes)
    {
        // Available field types that can be set.
        $types = ['string', 'number'];

        // Make sure a valid field type is passed in. If not default to string.
        foreach ($fieldTypes as &$ft) {
            if (!in_array($ft['type'], $types)) {
                $ft['type'] = 'string';
            }
        }

        $this->fieldTypes = $fieldTypes;
    }

    /**
     * An array of field types defined for use with datatables
     *
     * @return array
     */
    public function getFieldTypes()
    {
        return $this->fieldTypes;
    }

    /**
     * Set the URL to use for tile links
     *
     * @param string $url The URL to use for links from tile
     *
     * @return void
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * Get the URL to use for tile links
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set the fields to display
     *
     * @param array $displayFields Array of the fields and sizes to display in data table
     *
     * @return void
     */
    public function setDisplayFields($displayFields)
    {
        $this->displayFields = $displayFields;
    }

    /**
     * Get the list of fields and sizes for display in data tables
     *
     * @return array|bool
     */
    public function getDisplayFields()
    {
        $fields = $this->displayFields;
        if (is_array($fields) && count($fields) > 0) {
            if (isset($fields[count($fields) - 1]['width'])) {
                unset($fields[count($fields) - 1]['width']);
            }
        }

        return $fields;
    }

    /**
     * Set the type of click action enabled on tile
     *
     * @param int $click Click action to take
     *
     * @return void
     */
    public function setClickType($click)
    {
        $clicks = [DataTileBase::CLICK_NO, DataTileBase::CLICK_LINK, DataTileBase::CLICK_TABLE];

        if (!in_array($click, $clicks)) {
            $this->clickType = DataTileBase::CLICK_NO;
        } else {
            $this->clickType = $click;
        }
    }

    /**
     * Get the type of click action to take with tile.
     * NOTE: This accessor WILL override currently set click type if count == 0.
     *
     * @return int
     */
    public function getClickType()
    {
        // Return early with click type for no popup if count is 0.
        if ($this->count == 0) {
            return DataTileBase::CLICK_NO;
        }

        return $this->clickType;
    }
}
