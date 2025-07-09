<?php
/**
 * Interface for Data Tiles for use with the Dashboard data ribbon widget.
 *
 * @keywords dashboard, data ribbon
 */
namespace Models\TPM\Dashboard\Subs\DataTiles;

/**
 * Interface DataTileBase
 *
 * @package Controllers\TPM\Dashboard\Subs\DataTiles
 */
interface DataTileInterface
{
    /**
     * Get a list of data associated with tile
     *
     * @return array
     */
    public function getList();

    /**
     * Define any needed WHERE clauses for SQL
     *
     * @return void
     */
    public function setWhere();

    /**
     * Define any table joins needed for SQL
     *
     * @return void
     */
    public function setJoins();
}
