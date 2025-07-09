<?php
/**
 * Work with Note Category table
 *
 * @keywords case, case notes, model
 */

namespace Models\TPM;

use Models\Base\ReadWrite\TenantIdStrict;

#[\AllowDynamicProperties]
class NoteCategory extends TenantIdStrict
{
    /**
     * @var int The current client ID
     */
    protected $clientID = 0;

    /**
     * @var string Table containing Cases
     */
    protected $table = 'noteCategory';

    /**
     * @var \Skinny\Skinny App instance
     */
    protected $app = null;

    /**
     * @var \Lib\Database\MySqlPdo $DB
     */
    protected $DB = null;

    /**
     * @var string field name of the tenant ID
     */
    protected $tenantIdField = 'clientID';

    /**
     * CaseNotes constructor.
     *
     * @param int   $tenantID
     * @param array $params
     */
    public function __construct($tenantID, array $params = [])
    {
        $this->app = \Xtra::app();
        parent::__construct($tenantID, $params);
    }

    /**
     * Return array gettable/settable attributes w/validation rules
     *
     * @param string $context
     *
     * @return array
     */
    public static function rulesArray($context = '')
    {
        $rules = [
            'id'       => 'db_int',
            'clientID' => 'db_int|required',
            'name'     => 'max_len,255',
        ];

        return $rules;
    }

    /**
     * Get all note categories
     *
     * @return array
     */
    public function getList()
    {
        $clientDB = $this->DB->getClientDB($this->tenantIdValue);
        $sql = 'SELECT id, name FROM ' . $this->DB->prependDbName($clientDB, 'noteCategory') . ' '
            . 'WHERE clientID=:clientID AND id > 0 ORDER BY name ASC';
        $params = [':clientID' => $this->clientID];
        $categories = $this->DB->fetchKeyValueRows($sql, $params);

        if (empty($categories)) {
            $this->createDefaultCategory();
            return $this->getList();
        } else {
            return $categories;
        }
    }

    /**
     * Get all note categories
     *
     * @return array
     */
    public function getNoteCategoryIDs()
    {
        $rtn = [];
        $clientDB = $this->DB->getClientDB($this->tenantIdValue);
        $sql = "SELECT id FROM {$clientDB}.noteCategory WHERE clientID = :clientID AND id > 0 ORDER BY name ASC";
        $params = [':clientID' => $this->clientID];
        if ($categories = $this->DB->fetchValueArray($sql, $params)) {
            $rtn = $categories;
        }
        return $rtn;
    }

    /**
     * Create a default category for a tenant if none is defined.
     *
     * @return void
     * @throws \Exception
     */
    private function createDefaultCategory()
    {
        $name = 'General Information';

        if (!$this->addCategory($name)) {
            throw new \Exception('Unable to create default category for tenant.');
        }
    }

    /**
     * Add a new note category with specified name.
     *
     * @param string $name The name of the new note category.
     *
     * @return bool
     */
    public function addCategory($name)
    {
        $data = [
            'clientID' => $this->clientID,
            'name'     => $name
        ];

        $category = (new NoteCategory($this->clientID));
        $category->setAttributes($data);

        return $category->save();
    }

    /**
     * Remove a category by ID. If not found throw catchable exception.
     *
     * @param int $categoryID ID of the category to delete.
     *
     * @return bool
     * @throws \Exception
     */
    public function removeCategory($categoryID)
    {
        $category = (new NoteCategory($this->clientID))->findById($categoryID);

        if (is_object($category)) {
            return $category->delete();
        } else {
            throw new \Exception('Unable to find Note Category with ID: ' . $categoryID);
        }
    }
}
