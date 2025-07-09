<?php
/**
 * Base Class for Data Tiles for use with the Dashboard data ribbon widget.
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\Globals\Department;
use Models\Globals\Region;

/**
 * Interface DataTileBase
 *
 * @package Controllers\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
abstract class DataTileBase implements DataTileInterface
{
    public const CLICK_NO    = 0;
    public const CLICK_LINK  = 1;
    public const CLICK_TABLE = 2;

    protected $title     = '';
    protected $tenantID  = 0;
    protected $clickType = DataTileBase::CLICK_NO;

    /**
     * @var array Regions user is allowed to access
     */
    protected $userRegions = [];

    /**
     * @var array Departments user is allowed to access
     */
    protected $userDepartments = [];

    /**
     * @var Current DB instance
     */
    protected $db       = null;

    /**
     * @var \Slim\Slim Current app instance
     */
    protected $app      = null;

    /**
     * Retrieve list of found items
     *
     * @return DataTileList
     */
    abstract public function getList();

    /**
     * Get URL for link redirects
     *
     * @return string
     */
    abstract protected function getUrl();

    /**
     * DataTileBase constructor.
     */
    public function __construct()
    {
        $this->app      = \Xtra::app();
        $this->tenantID = $this->app->ftr->tenant;
        $this->db       = $this->app->DB;
    }

    /**
     * Get Data Tile title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Retrieve Region and Department restrictions for user
     *
     * @return void
     */
    protected function loadRestrictions()
    {
        $regions = new Region($this->app->ftr->tenant);
        if ($regions->hasAllRegions($this->app->ftr->user, $this->app->ftr->role)) {
            $this->userRegions = [];
        } else {
            $regions = $regions->getUserRegions($this->app->ftr->user, $this->app->ftr->role);
            $this->userRegions = array_map(
                fn($el) => $el['id'],
                $regions
            );
        }

        $departments = new Department($this->app->ftr->tenant);
        if ($departments->hasAllDepartments($this->app->ftr->user, $this->app->ftr->role)) {
            $this->userDepartments = [];
        } else {
            $departments = $departments->getUserDepartments($this->app->ftr->user, $this->app->ftr->role);
            $this->userDepartments = array_map(
                fn($el) => $el['id'],
                $departments
            );
        }
    }

    /**
     * Get formatted Data Tile data
     *
     * @return array
     */
    public function getData()
    {
        /**
         * @var DataTileList $list
         */
        $list = $this->getList();

        // Generic types return a single value to be displayed instead of list count.
        if (!$list->getDisplayFields()) {
            $return = [
                'tileClick' => $list->getClickType(),
                'tileCount' => $list->getCount(),
                'tileItems' => $list->getItems(),
            ];
        } else {
            $return = [
                'tileClick'      => $list->getClickType(),
                'tileCount'      => $list->getCount(),
                'linkUrl'        => $list->getUrl(),
                'tileFieldTypes' => $list->getFieldTypes(),
                'tileDisplay'    => $list->getDisplayFields(),
                'tileItems'      => $list->getItems(),
            ];
        }

        return $return;
    }
}
