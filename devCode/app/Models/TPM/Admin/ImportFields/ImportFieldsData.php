<?php
/**
 * Model: admin tool subscribers and users
 *
 * @keywords ImportFields, records, data, subscribers
 */

namespace Models\TPM\Admin\ImportFields;

use Xtra;
use Lib\IO;
use Lib\Legacy\UserType;
use Models\User;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class ImportFieldsData
{

    private $DB = null;
    private $app = null;

    /**
     * Constructor - initialization
     *
     * @return void
     */
    public function __construct()
    {
        $this->app = Xtra::app();
        $this->DB = $this->app->DB;
    }

    /**
     * Remove extraneous characters from name and lower case
     *
     * @param string $nm Name to be be nromalize
     *
     * @return string Normarlize name
     */
    public function normalizeName($nm)
    {
        $ro1 = ['(r/o)', '(read-only)'];
        $ro2 = ['{r-o}', '{read-only}'];
        $nm = str_replace($ro1, $ro2, strtolower($nm));
        $srch = [' ', '/', ':', ';', '?', ',', '(', ')', '.', "\n", "\r", "\t", '&amp;', '&quot;', '&#039;', "'", '"'];
        $rplc = '';
        $nm = str_replace($srch, $rplc, $nm);
        return str_replace($ro2, $ro1, $nm);
    }

    /**
     * Test if normalizedName (colName) has only valid characters
     *
     * @param string $nn Normalized name
     *
     * @return boolean true if valid
     */
    public function validColName($nn)
    {
        return preg_match('/^[-0-9a-z_#]+$/i', $nn);
    }

    /**
     * Retrieves all users from DB
     *
     * @param string $params clean post
     *
     * @return array of users
     */
    public function importTableData($params)
    {
        $db = $this->DB;
        $sp_globaldb   = $db->spGlobalDB;
        $real_globaldb = $db->globalDB;
        $globaldb = $db->authDB;
        $this->app = Xtra::app();

        $_config = ['divs' => ['ImportType', 'Tables', 'Columns'], 'tableDef' => ['type' => ['div'   => 'ImportType', 'show'  => ['ImportType'], 'cols' => [['fld' => 'lnk:del', 'head' => 'Action', 'cls' => 'cent', 'th-colspan' => 4], ['fld' => 'lnk:edit', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'lnk:map', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'lnk:ref', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'refType', 'head' => 'Reference Type', 'cls' => ''], ['fld' => 'refTypeName', 'head' => 'Reference Name', 'cls' => ''], ['fld' => 'description', 'head' => 'Description', 'cls' => '']]], 'map' => ['div'   => 'Tables', 'show'  => ['ImportType', 'Tables'], 'cols' => [['fld' => 'lnk:del', 'head' => 'Action', 'cls' => 'cent', 'th-colspan' => 3], ['fld' => 'lnk:edit', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'lnk:col', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'mapTo', 'head' => 'Map ID', 'cls' => ''], ['fld' => 'colClass', 'head' => 'Import Class', 'cls' => ''], ['fld' => 'dataTable', 'head' => 'Mapped Table', 'cls' => ''], ['fld' => 'description', 'head' => 'Description', 'cls' => '']]], 'ref' => ['div'   => 'Columns', 'show'  => ['ImportType', 'Columns'], 'cols' => [['fld' => 'lnk:del', 'head' => 'Action', 'cls' => 'cent', 'th-colspan' => 3], ['fld' => 'lnk:edit', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'lnk:ref-alias', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'heading', 'head' => 'Heading', 'cls' => 'no-wrap'], ['fld' => '_aliases', 'head' => 'Aliases', 'cls' => ''], ['fld' => 'colTable', 'head' => 'DB Table', 'cls' => 'no-wrap'], ['fld' => 'colField', 'head' => 'DB Column', 'cls' => 'no-wrap'], ['fld' => 'colType', 'head' => 'Ref Type', 'cls' => 'no-wrap'], ['fld' => 'description', 'head' => 'Description', 'cls' => '']]], 'col' => ['div'   => 'Columns', 'show'  => ['ImportType', 'Tables', 'Columns'], 'cols' => [['fld' => 'lnk:del', 'head' => 'Action', 'cls' => 'cent', 'th-colspan' => 3], ['fld' => 'lnk:edit', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'lnk:col-alias', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'heading', 'head' => 'Heading', 'cls' => 'no-wrap'], ['fld' => '_aliases', 'head' => 'Aliases', 'cls' => ''], ['fld' => 'colType', 'head' => 'Col Type', 'cls' => 'no-wrap'], ['fld' => 'colField', 'head' => 'DB Field', 'cls' => 'no-wrap'], ['fld' => 'description', 'head' => 'Description', 'cls' => '']]], 'col-alias' => ['div'   => 'AliasList', 'show'  => ['ImportType', 'Tables', 'Columns'], 'cols' => [['fld' => 'lnk:del', 'head' => 'Action', 'cls' => 'cent', 'th-colspan' => 2], ['fld' => 'lnk:edit', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'heading', 'head' => 'Alias', 'cls' => 'no-wrap']]], 'ref-alias' => ['div'   => 'AliasList', 'show'  => ['ImportType', 'Columns'], 'cols' => [['fld' => 'lnk:del', 'head' => 'Action', 'cls' => 'cent', 'th-colspan' => 2], ['fld' => 'lnk:edit', 'head' => '&nbsp;', 'cls' => ''], ['fld' => 'heading', 'head' => 'Alias', 'cls' => 'no-wrap']]]], 'formFields' => ['type' => ['refType', 'refTypeName', 'description'], 'map' => [
            'mapTo',
            'colClass',
            'dataTable',
            //'seq',
            'description',
        ], 'col' => ['colType', 'colField', 'heading', 'lookupType', 'validation', 'description'], 'ref' => ['colType', 'colTable', 'colField', 'heading', 'lookupType', 'validation', 'description']]];

        $_tables = ['type' => $real_globaldb . '.g_importRefType', 'map' => $real_globaldb . '.g_importColMap', 'col' => $real_globaldb . '.g_importCol', 'ref' => $real_globaldb . '.g_importRef', 'col-alias' => $real_globaldb . '.g_importCol', 'ref-alias' => $real_globaldb . '.g_importRef', 'init' => ''];

        $Result = 0;
        $ErrorTitle = '';
        $ErrorMsg = '';
        $opReq = $params['opReq'];
        switch ($opReq) {
            case 'init':
                $Config = $_config;
                return ['Listing' => $opReq, 'Config' => $Config];
            break;
            case 'list':
                $listing = $params['a1'];
                $Listing = $listing;
                $tbl = $_tables[$listing];
                switch ($listing) {
                    case 'type':
                        $sql = "SELECT * FROM $tbl WHERE 1 ORDER BY id ASC";
                        $recs = $db->fetchAssocRows($sql);
                        for ($i = 0; $i < count($recs); $i++) {
                            $id = $recs[$i]['id'];
                            $tbl1 = $_tables['ref'];
                            $tbl2 = $_tables['map'];
                            $cdSql1 = "SELECT refTypeID FROM $tbl1 WHERE refTypeID = :id LIMIT 1";
                            $cdSql2 = "SELECT refTypeID FROM $tbl2 WHERE refTypeID = :id LIMIT 1";
                            $params = [':id' => $id];
                            if (($db->fetchValue($cdSql1, $params) === null)
                            && ($db->fetchValue($cdSql2, $params) === null)
                            ) {
                                $recs[$i]['lnk:del'] = ['img' => 'sm_delete.png', 'alt' => 'Delete', 'click' => "appNS.importFields.deleteRecord('type', $id)"];
                            } else {
                                $recs[$i]['lnk:del'] = ['img' => 'spacer.gif', 'alt' => ''];
                            }
                            $recs[$i]['lnk:edit'] = ['img' => 'sm_edit.png', 'alt' => 'Edit', 'click' => "appNS.importFields.addImportType($id)"];
                            $recs[$i]['lnk:map'] = ['img' => 'M-sm.png', 'alt' => 'Data mapping', 'click' => "appNS.importFields.opRequest('list', 'map', $id)"];
                            $recs[$i]['lnk:ref'] = ['img' => 'sm_list.png', 'alt' => 'Reference Columns', 'click' => "appNS.importFields.opRequest('list', 'ref', $id)"];
                        }
                        $Title = 'Import Type';
                        $Records = $recs;
                        $AddLink = ['text' => 'Add Import Type', 'click' => "appNS.importFields.addImportType(0)"];
                        return ['Records' => $Records, 'Title' => $Title, 'AddLink' => $AddLink, 'Listing' => $Listing, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg];
                    break;

                    case 'map':
                        $refTypeID = intval($params['a2']);
                        $sql = "SELECT * FROM $tbl WHERE refTypeID = :refTypeID ORDER BY seq ASC";
                        $params = [':refTypeID' => $refTypeID];
                        $recs = $db->fetchAssocRows($sql, $params);
                        for ($i = 0; $i < count($recs); $i++) {
                            $mapTo = $recs[$i]['mapTo'];
                            $mapToID = intval($recs[$i]['id']);
                            $cdTbl = $_tables['col'];
                            $cdSql = "SELECT mapTo FROM $cdTbl WHERE mapTo = :mapToID LIMIT 1";
                            $params = [':mapToID' => $mapToID];
                            if ($mapTo !== 'cscf'
                            && $mapTo !== '3pcf'
                            && ($db->fetchValue($cdSql, $params) === null)
                            ) {
                                $recs[$i]['lnk:del'] = ['img' => 'sm_delete.png', 'alt' => 'Delete', 'click' => "appNS.importFields.deleteRecord('map', $mapToID)"];
                            } else {
                                $recs[$i]['lnk:del'] = ['img' => 'spacer.gif', 'alt' => ''];
                            }
                            $recs[$i]['lnk:edit'] = ['img' => 'sm_edit.png', 'alt' => 'Edit', 'click' => "appNS.importFields.addMapping($mapToID)"];
                            if ($mapTo == '3pcf' || $mapTo == 'cscf') {
                                $recs[$i]['lnk:col'] = ['img' => 'spacer.gif', 'alt' => ''];
                            } else {
                                $recs[$i]['lnk:col'] = ['img' => 'sm_list.png', 'alt' => 'Data Columns', 'click' => "appNS.importFields.opRequest('list', 'col', $mapToID)"];
                            }
                        }
                        $typeTbl = $_tables['type'];
                        $sql = "SELECT refType, refTypeName "
                        . "FROM $typeTbl WHERE id = :refTypeID LIMIT 1";
                        $params = [':refTypeID' => $refTypeID];
                        $identifier = $db->fetchObjectRow($sql, $params);
                        $Title = "Data Mapping for $identifier->refTypeName ($identifier->refType)";
                        $Records = $recs;
                        $AddLink = ['text' => 'Add Mapping', 'click' => "appNS.importFields.addMapping(0)"];
                        return ['Records' => $Records, 'Title' => $Title, 'AddLink' => $AddLink, 'Listing' => $Listing, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg, 'refTypeID' => $refTypeID];
                    break;

                    case 'ref':
                        $refTypeID = intval($params['a2']);
                        $sql = "SELECT * FROM $tbl WHERE refTypeID = :refTypeID AND colType <> 'alias' "
                        . "ORDER BY heading ASC";
                        $params = [':refTypeID' => $refTypeID];
                        $recs = $db->fetchAssocRows($sql, $params);
                        for ($i = 0; $i < count($recs); $i++) {
                            $id = $recs[$i]['id'];
                            $recs[$i]['lnk:edit'] = ['img' => 'sm_edit.png', 'alt' => 'Edit', 'click' => "appNS.importFields.addColMap($id)"];
                            $recs[$i]['lnk:ref-alias'] = ['img' => 'A-sm.png', 'alt' => 'Aliases', 'click' => "appNS.importFields.addAlias('ref-alias', $id)"];
                            // get aliases
                            $aliasOf = intval($recs[$i]['id']);
                            $refTbl = $_tables['ref'];
                            $sql = "SELECT heading FROM $refTbl "
                                . "WHERE colType = 'alias' AND aliasOf = :aliasOf "
                                . "ORDER BY heading ASC";
                            $params = [':aliasOf' => $aliasOf];
                            if ($aRecs = $db->fetchValueArray($sql, $params)) {
                                $aliases = '<div class="no-wrap">'
                                    . join("</div>\n<div class=\"no-wrap\">", $aRecs) . '</div>';
                                $recs[$i]['lnk:del'] = ['img' => 'spacer.gif', 'alt' => ''];
                            } else {
                                $aliases = '(none)';
                                $recs[$i]['lnk:del'] = ['img' => 'sm_delete.png', 'alt' => 'Delete', 'click' => "appNS.importFields.deleteRecord('ref', $id)"];
                            }
                            $recs[$i]['_aliases'] = $aliases;
                        }
                        $typeTbl = $_tables['type'];
                        $sql = "SELECT refType, refTypeName "
                        . "FROM $typeTbl WHERE id = :refTypeID LIMIT 1";
                        $params = [':refTypeID' => $refTypeID];
                        $identifier = $db->fetchObjectRow($sql, $params);
                        $Title = "Reference Columns for $identifier->refTypeName ($identifier->refType)";
                        $Records = $recs;
                        $AddLink = ['text' => 'Add Reference Column', 'click' => "appNS.importFields.addColMap(0)"];
                        return ['Records' => $Records, 'Title' => $Title, 'AddLink' => $AddLink, 'Listing' => $Listing, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg, 'refTypeID' => $refTypeID];
                    break;

                    case 'col':
                        $mapTo = intval($params['a2']);
                        $sql = "SELECT * FROM $tbl WHERE mapTo = :mapTo AND colType <> 'alias' "
                        . "ORDER BY heading ASC";
                        $params = [':mapTo' => $mapTo];
                        $recs = $db->fetchAssocRows($sql, $params);
                        for ($i = 0; $i < count($recs); $i++) {
                            $id = $recs[$i]['id'];
                            $recs[$i]['lnk:edit'] = ['img' => 'sm_edit.png', 'alt' => 'Edit', 'click' => "appNS.importFields.addColData($id)"];
                            $recs[$i]['lnk:col-alias'] = ['img' => 'A-sm.png', 'alt' => 'Aliases', 'click' => "appNS.importFields.addAlias('col-alias', $id)"];
                            // get aliases
                            $aliasOf = intval($recs[$i]['id']);
                            $colTbl = $_tables['col'];
                            $sql = "SELECT heading FROM $colTbl "
                                . "WHERE colType = 'alias' AND aliasOf = :aliasOf "
                                . "ORDER BY heading ASC";
                            $params = [':aliasOf' => $aliasOf];
                            if ($aRecs = $db->fetchValueArray($sql, $params)) {
                                $aliases = '<div class="no-wrap">'
                                    . join("</div>\n<div class=\"no-wrap\">", $aRecs) . '</div>';
                                $recs[$i]['lnk:del'] = ['img' => 'spacer.gif', 'alt' => ''];
                            } else {
                                $aliases = '(none)';
                                $recs[$i]['lnk:del'] = ['img' => 'sm_delete.png', 'alt' => 'Delete', 'click' => "appNS.importFields.deleteRecord('col', $id)"];
                            }
                            $recs[$i]['_aliases'] = $aliases;
                        }
                        $mapTbl = $_tables['map'];
                        $sql = "SELECT colClass, dataTable "
                        . "FROM $mapTbl WHERE id = :mapTo LIMIT 1";
                        $params = [':mapTo' => $mapTo];
                        $identifier = $db->fetchObjectRow($sql, $params);
                        $Title = "Data Columns for $identifier->colClass ($identifier->dataTable)";
                        $Records = $recs;
                        $AddLink = ['text' => 'Add Data Column', 'click' => "appNS.importFields.addColData(0)"];
                        return ['Records' => $Records, 'Title' => $Title, 'AddLink' => $AddLink, 'Listing' => $Listing, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg];
                    break;

                    case 'ref-alias':
                        $id = intval($params['a2']);
                        $sql = "SELECT id, colName, heading "
                        . "FROM $tbl WHERE id = :id LIMIT 1";
                        $params = [':id' => $id];
                        $primary = $db->fetchObjectRow($sql, $params);
                        $aliasOf = intval($primary->id);
                        $sql = "SELECT id, heading FROM $tbl "
                        . "WHERE colType = 'alias' AND aliasOf = :aliasOf "
                        . "ORDER BY heading ASC";
                        $params = [':aliasOf' => $aliasOf];
                        $recs = $db->fetchAssocRows($sql, $params);
                        for ($i = 0; $i < count($recs); $i++) {
                            $id = $recs[$i]['id'];
                            $recs[$i]['lnk:del'] = ['img' => 'sm_delete.png', 'alt' => 'Delete', 'click' => "appNS.importFields.deleteRecord('ref-alias', $id)"];
                            $recs[$i]['lnk:edit'] = ['img' => 'sm_edit.png', 'alt' => 'Edit', 'click' => "appNS.importFields.opRequest('edit-alias', 'ref-alias', $id)"];
                        }
                        $Title = "Aliases of `$primary->heading`";
                        $Records = $recs;
                        $AddLink = ['text' => 'Add Alias', 'click' => "appNS.importFields.opRequest('edit', '$listing', 0)"];
                        return ['Records' => $Records, 'Title' => $Title, 'AddLink' => $AddLink, 'Listing' => $Listing, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg];
                    break;

                    case 'col-alias':
                        $id = intval($params['a2']);
                        $sql = "SELECT id, colName, heading "
                        . "FROM $tbl WHERE id = :id LIMIT 1";
                        $params = [':id' => $id];
                        $primary = $db->fetchObjectRow($sql, $params);
                        $aliasOf = intval($primary->id);
                        $sql = "SELECT id, heading FROM $tbl "
                        . "WHERE colType = 'alias' AND aliasOf = :aliasOf "
                        . "ORDER BY heading ASC";
                        $params = [':aliasOf' => $aliasOf];
                        $recs = $db->fetchAssocRows($sql, $params);
                        for ($i = 0; $i < count($recs); $i++) {
                            $id = $recs[$i]['id'];
                            $recs[$i]['lnk:del'] = ['img' => 'sm_delete.png', 'alt' => 'Delete', 'click' => "appNS.importFields.deleteRecord('col-alias', $id)"];
                            $recs[$i]['lnk:edit'] = ['img' => 'sm_edit.png', 'alt' => 'Edit', 'click' => "appNS.importFields.opRequest('edit-alias', 'col-alias', $id)"];
                        }
                        $Title = "Aliases of `$primary->heading`";
                        $Records = $recs;
                        $AddLink = ['text' => 'Add Alias', 'click' => "appNS.importFields.opRequest('edit', '$listing', 0)"];
                        return ['Records' => $Records, 'Title' => $Title, 'AddLink' => $AddLink, 'Listing' => $Listing, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg];
                    break;

                    default:
                        return ['ErrorTitle' => $ErrorTitle = 'Invalid Request', 'ErrorMsg' => $ErrorMsg = 'No listing  `' . $listing . '`'];
                }
                break;

            case 'delete-alias':
                $listing = $params['opReq'];
                $st = $params['a1'];
                $aliasID = intval($params['a2']);
                $primaryID = intval($params['a3']);
                $tbl = $_tables[$st];
                $sql = "DELETE FROM $tbl WHERE id = :aliasID AND colType = 'alias' "
                ."AND aliasOf = :primaryID LIMIT 1";
                $params = [':primaryID' => $primaryID, ':aliasID' => $aliasID];
                if ($db->query($sql, $params)) {
                    $Result = 1;
                } else {
                    $ErrorTitle = 'Alias Deletion Failed';
                    $ErrorMsg = 'Unable to find record.';
                }
                return ['Listing' => $listing, 'func' => $st, 'aliasID' => $aliasID, 'primaryID' => $primaryID, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg];
            break;

            case 'save-alias':
                $st = $params['a1'];
                $aliasID = intval($params['a2']);
                $primaryID = intval($params['a3']);
                $heading = trim((string) $params['a4']);
                $opReq = $params['opReq'];
                $tbl = $_tables[$st];
                $colName = (new ImportFieldsData())->normalizeName($heading);
                do {
                    $err = false;
                    if ($heading == '') {
                        $err = 'Alias name is empty.';
                        break;
                    }
                    if (!(new ImportFieldsData())->validColName($colName)) {
                        $err = 'Alias name contains unsupported characters.';
                        break;
                    }
                    if ($aliasID < 0) {
                        $err = 'Alias not related to existing column.';
                        break;
                    }
                    $pcSql = "SELECT * FROM $tbl WHERE id = :primaryID LIMIT 1";
                    $params = [':primaryID' => $primaryID];
                    if (!($primaryRow = $db->fetchObjectRow($pcSql, $params))) {
                        $err = 'Alias must refer to an existing column.';
                        break;
                    }
                    if ($primaryRow->colType == 'alias') {
                        $err = 'Alias can not refer to another alias.';
                        break;
                    }
                    if ($aliasID) {
                        $aSql = "SELECT * FROM $tbl WHERE id = :aliasID LIMIT 1";
                        $params = [':aliasID' => $aliasID];
                        if (!($aRow = $db->fetchObjectRow($aSql, $params))) {
                            $err = 'Alias record not found.';
                            break;
                        }
                        if ($st == 'ref-alias') {
                            if ($primaryRow->refTypeID != $aRow->refTypeID) {
                                $err = 'Alias and reference column must be the same import type.';
                                break;
                            }
                            // check for colName existence
                            $sameSql = "SELECT id FROM $tbl WHERE colName = :colName "
                            . "AND refTypeID = :refTypeID AND id <> :aliasID";
                            $params = [':refTypeID' => $primaryRow->refTypeID, ':aliasID' => $aliasID, ':colName' => $colName];
                        } else {
                            if ($primaryRow->mapTo != $aRow->mapTo) {
                                $err = 'Alias and data column must have the same mapping.';
                                break;
                            }
                            // check for colName existence
                            $sameSql = "SELECT id FROM $tbl WHERE colName = :colName "
                            . "AND mapTo = :mapTo AND id <> :aliasID";
                            $params = [':mapTo' => $primaryRow->mapTo, ':aliasID' => $aliasID, ':colName' => $colName];
                        }
                    } else {
                        // check for colName existence
                        if ($st == 'ref-alias') {
                            $sameSql = "SELECT id FROM $tbl WHERE colName = :colName "
                            . "AND refTypeID = :refTypeID";
                            $params = [':refTypeID' => $primaryRow->refTypeID, ':colName' => $colName];
                        } else {
                            $sameSql = "SELECT id FROM $tbl WHERE colName = :colName "
                            . "AND mapTo = :mapTo";
                            $params = [':mapTo' => $primaryRow->mapTo, ':colName' => $colName];
                        }
                    }
                    if ($db->fetchValue($sameSql, $params)) {
                        $err = 'Alias is already defined.';
                    }
                    break;
                } while (0);

                if ($err) {
                    $ErrorTitle = 'Save Operation Failed';
                    $ErrorMsg = $err;
                } else {
                    if ($aliasID == 0) {
                        $params = [':colName' => $colName, ':heading' => $heading, ':aliasOf' => $primaryRow->id];
                        if ($st == 'ref-alias') {
                            $scope = "refTypeID = :refTypeID, ";
                            $params[':refTypeID'] = $primaryRow->refTypeID;
                        } else {
                            $scope = "mapTo = :mapTo, ";
                            $params[':mapTo'] = $primaryRow->mapTo;
                        }
                        $sql = "INSERT INTO $tbl SET "
                        . "id = NULL, "
                        . "colName = :colName, "
                        . "colType = 'alias', "
                        . "heading = :heading, "
                        . $scope
                        . "aliasOf = :aliasOf";
                    } else {
                        $sql = "UPDATE $tbl SET "
                        . "colName = :colName, "
                        . "heading = :heading "
                        . "WHERE id = :id AND aliasOf = :primaryID LIMIT 1";
                        $params = [':colName' => $colName, ':heading' => $heading, ':primaryID' => $primaryID, ':id' => $aliasID];
                    }
                    if (!$db->query($sql, $params)) {
                        $ErrorTitle = 'Save Operation Failed';
                        $ErrorMsg = 'An unexpected error occurred.';
                    }
                }
                return ['func' => $st, 'ErrorMsg' => $ErrorMsg, 'ErrorTitle' => $ErrorTitle, 'Listing' => $opReq];
            break;

            case 'edit-alias':
                $et = $params['a1'];
                $aliasID = intval($params['a2']);
                $primaryID = intval($params['a3']);
                $listing = $params['opReq'];
                $tbl =  $_tables[$et];
                $sql = "SELECT id, heading FROM $tbl "
                . "WHERE id = :aliasID AND aliasOf = :primaryID LIMIT 1";
                $params = [':aliasID' => $aliasID, ':primaryID' => $primaryID];
                $row = $db->fetchAssocRows($sql, $params);
                if (!$row) {
                    $ErrorTitle = 'Edit Failed';
                    $ErrorMsg = 'Requested alias not found.';
                } else {
                    $heading = $row[0]['heading'];
                    $id = $row[0]['id'];
                }
                return ['AliasID' => $id, 'ErrorMsg' => $ErrorMsg, 'ErrorTitle' => $ErrorTitle, 'Listing' => $listing, 'Heading' => $heading, 'primaryID' => $primaryID, 'et' => $et];
            break;

            case 'maptable-cols':
                $colID = intval($params['c']);
                $mapID = intval($params['am']);
                $tbl = $_tables['map'];
                $sql = "SELECT dataTable FROM $tbl WHERE id = :mapID";
                $params = [':mapID' => $mapID];

                $tblName = $db->fetchValue($sql, $params);
                $sql = "SHOW COLUMNS FROM `$tblName`";
                if ($cols = $db->fetchValueArray($sql)) {
                    natcasesort($cols);
                    $tf = [['t' => 'Choose...', 'v' => '']];
                    foreach ($cols as $c) {
                        $tf[] = ['t' => $c, 'v' => $c];
                    }
                    $Fields = $tf;
                    $Current = '';
                    if ($colID) {
                        $tbl = $_tables['col'];
                        $sql = "SELECT colField FROM $tbl WHERE id = :colID";
                        $params = [':colID' => $colID];
                        $Current = $db->fetchValue($sql, $params);
                    }
                } else {
                    $ErrorTitle = 'No Table Columns';
                    $ErrorMsg = 'Failed to retrieve fields for `' . $tblName . '`';
                }
                return ['Listing' => $opReq, 'mapID' => $mapID, 'Fields' => $Fields, 'Current' => $Current, 'ErrorMsg' => $ErrorMsg, 'ErrorTitle' => $ErrorTitle];
            break;

            case 'edit':
                // get requested record
                $et = $params['a1'];
                $listing = $params['opReq'];
                $recID = intval($params['a2']);
                if (array_key_exists($et, $_tables)) {
                    $tbl = $_tables[$et];
                    $flds = join(', ', $_config['formFields'][$et]);
                    $sql = "SELECT $flds FROM $tbl WHERE id = :recID LIMIT 1";
                    $params = [':recID' => $recID];
                    if ($row = $db->fetchAssocRow($sql, $params)) {
                        $Result = 1;
                        $Form = $et;
                        $Values = $row;
                        $RecID = $recID;
                    } else {
                        $ErrorTitle = 'Edit Failed';
                        $ErrorMsg = 'Record not found.';
                    }
                } else {
                    $ErrorTitle = 'Edit Failed';
                    $ErrorMsg = 'Unknown record type.';
                }
                return ['Listing' => $listing, 'Form' => $Form, 'Values' => $Values, 'RecID' => $RecID, 'ErrorMsg' => $ErrorMsg, 'ErrorTitle' => $ErrorTitle];
            break;

            case 'save':
                $frm = $params['frm'];
                $tbl = $_tables[$frm];
                $recID = intval($params['rec']);
                $activeMapID = intval($params['am']);
                $activeRefTypeID = intval($params['art']);
                $err = [];
                $baseSql = false;
                switch ($frm) {
                    case 'type':
                        // refType must be lowercase alpha and unique, 4-10 chars.
                        $refType = strtolower(trim((string) $params['refType']));
                        $refTypeName = trim((string) $params['refTypeName']);
                        $description = trim((string) $params['description']);
                        if (!preg_match('/^[a-z0-9]{4,10}$/', $refType)) {
                            $err[] = 'Import key must be 4-10 lowercase letters and numbers.';
                        } else {
                            // unique?
                            $sql = "SELECT id FROM $tbl WHERE id <> :recID AND refType = :refType";
                            $params = [':recID' => $recID, ':refType' => $refType];
                            if ($db->fetchValue($sql, $params) !== null) {
                                $err[] = 'Import key is already in use.';
                            }
                        }
                        // refTypeName is required
                        if ($refTypeName === '') {
                            $err[] = 'Import Name is blank.';
                        }
                        if ($description === '') {
                            $err[] = 'Description is blank.';
                        }
                        if (count($err)) {
                            break;
                        }
                        $baseSql = "$tbl SET\n"
                        . "refType = '$refType',\n"
                        . "refTypeName = '$refTypeName',\n"
                        . "description = '$description'";
                        break;

                    case 'map':
                        $mapTo = trim((string) $params['mapTo']);
                        $colClass = trim((string) $params['colClass']);
                        $dataTable = trim((string) $params['dataTable']);
                        $description = trim((string) $params['description']);
                        if (!preg_match('/^[a-z0-9]{2,10}$/i', $mapTo)) {
                            $err[] = 'Map key must be 2-10 letters and numbers.';
                        } else {
                            $sql = "SELECT id FROM $tbl WHERE id <> :recID AND mapTo = :mapTo";
                            $params = [':mapTo' => $mapTo, ':recID' => $recID];
                            if ($db->fetchValue($sql, $params) !== null) {
                                $err[] = 'Map key is already in use.';
                            }
                        }
                        if ($colClass === '') {
                            $err[] = 'Map class is blank.';
                        }
                        if ($dataTable === '') {
                            $err[] = 'Data table is blank.';
                        } else {
                            // table must exist
                            if (!$db->tableExists($dataTable)) {
                                $err[] = 'Data table does not exist.';
                            }
                        }
                        if ($description === '') {
                            $err[] = 'Description is blank.';
                        }
                        if (count($err)) {
                            break;
                        }
                        $seq = $db->fetchValue("SELECT MAX(seq) FROM $tbl WHERE 1") + 1;
                        $baseSql = "$tbl SET\n"
                        . "seq = '$seq',\n"
                        . "refTypeID = '$activeRefTypeID',\n"
                        . "mapTo = '$mapTo',\n"
                        . "colClass = '$colClass',\n"
                        . "dataTable = '$dataTable',\n"
                        . "description = '$description'";
                        break;

                    case 'ref':
                        $colType = trim((string) $params['colType']);
                        $heading = trim((string) $params['heading']);
                        $colName = (new ImportFieldsData())->normalizeName($heading);
                        $colTable = trim((string) $params['colTable']);
                        $colField = trim((string) $params['colField']);
                        $lookupType = trim((string) $params['lookupType']);
                        $validation = trim((string) $params['validation']);
                        $description = trim((string) $params['description']);
                        if (!in_array($colType, ['direct', 'lookup'])) {
                            $err[] = 'Reference type must be Direct or Lookup.';
                        }
                        if ($heading == '') {
                            $err[] = 'Column heading is blank.';
                        } else {
                            if (!(new ImportFieldsData())->validColName($colName)) {
                                $err[] = 'Reference name contains unsupported characters.';
                            }
                            // unique?
                            $sql = "SELECT id FROM $tbl WHERE id <> :recID "
                            . "AND colName = :colName AND refTypeID = :refTypeID";
                            $params = [':recID' => $recID, ':colName' => $colName, ':refTypeID' => $activeRefTypeID];
                            if ($db->fetchValue($sql, $params) !== null) {
                                $err[] = 'Reference name is already in use.';
                            }
                        }
                        $hasTable = false;
                        if ($colTable === '') {
                            $err[] = 'Database table is blank.';
                        } else {
                            // table must exist
                            if (!$db->tableExists($colTable)) {
                                $err[] = 'Database table does not exist.';
                            } else {
                                $hasTable = true;
                            }
                        }
                        if ($colField === '') {
                            $err[] = 'Database column is blank.';
                        } elseif ($hasTable) {
                            if (!$db->columnExists($colField, $colTable)) {
                                $err[] = 'Database column does not exist.';
                            }
                        }
                        // not requireing description, lookupType or validation
                        if (count($err)) {
                            break;
                        }
                        $baseSql = "$tbl SET\n"
                        . "colName = '$colName',\n"
                        . "colType = '$colType',\n"
                        . "heading = '$heading',\n"
                        . "refTypeID = '$activeRefTypeID',\n"
                        . "colTable = '$colTable',\n"
                        . "colField = '$colField',\n"
                        . "lookupType = '$lookupType',\n"
                        . "validation = '$validation',\n"
                        . "description = '$description'";
                        break;

                    case 'col':
                        $colType = trim((string) $params['colType']);
                        $heading = trim((string) $params['heading']);
                        $colName = (new ImportFieldsData())->normalizeName($heading);
                        $colField = trim((string) $params['colField']);
                        $lookupType = trim((string) $params['lookupType']);
                        $validation = trim((string) $params['validation']);
                        $description = trim((string) $params['description']);
                        if (!in_array($colType, ['direct', 'lookup'])) {
                            $err[] = 'Column type must be Direct or Lookup.';
                        }
                        if ($heading == '') {
                            $err[] = 'Column heading is blank';
                        } else {
                            if (!(new importFieldsData())->validColName($colName)) {
                                $err[] = 'Reference name contains unsupported characters';
                            }
                            // unique?
                            $sql = "SELECT id FROM $tbl WHERE id <> :recID "
                            . "AND colName = :colName AND mapto = :activeMapID";
                            $params = [':recID' => $recID, ':colName' => $colName, ':activeMapID' => $activeMapID];
                            if ($db->fetchValue($sql, $params) !== null) {
                                $err[] = 'Column name is already in use';
                            }
                        }

                        if ($colField === '') {
                            $err[] = 'Database column is blank';
                        }
                        // not requireing description, lookupType or validation
                        if (count($err)) {
                            break;
                        }
                        $baseSql = "$tbl SET\n"
                        . "colName = '$colName',\n"
                        . "colType = '$colType',\n"
                        . "heading = '$heading',\n"
                        . "mapTo = '$activeMapID',\n"
                        . "colField = '$colField',\n"
                        . "lookupType = '$lookupType',\n"
                        . "validation = '$validation',\n"
                        . "description = '$description'";
                        break;
                }
                if ($baseSql && !count($err)) {
                    if ($recID) {
                        $sql = 'UPDATE ' . $baseSql . "\nWHERE id = '$recID' LIMIT 1";
                    } else {
                        $sql = 'INSERT INTO ' . $baseSql . ", id = NULL";
                    }
                    if ($db->query($sql)) {
                        $Result = 1;
                        $Listing = $frm;
                        $Sql = $sql;
                    } else {
                        $err[] = 'Unexpected database error.';
                    }
                }
                if ($errcnt = count($err)) {
                    $ErrorTitle = 'Save Operation Failed';
                    if ($errcnt == 1) {
                        $txt = $err[0];
                    } else {
                        $txt = "The following error were detected: ";
                        foreach ($err as $e) {
                            $txt .= $e . ", ";
                        }
                    }
                    $ErrorMsg = $txt;
                }
                return ['Listing' => $frm, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg, 'activeMapID' => $activeMapID, 'activeRefTypeID' => $activeRefTypeID, 'recID' => $recID];
            break;

            case 'delete':
                $dt = $params['a1'];
                $recID = intval($params['a2']);
                $tbl  = $_tables[$dt];
                switch ($dt) {
                    case 'type':
                        $delSql = "DELETE FROM $tbl WHERE id = :recID LIMIT 1";
                        $delparams = [':recID' => $recID];
                        // has mapping records?
                        $mapTbl = $_tables['map'];
                        $refTbl = $_tables['ref'];
                        $sql1 = "SELECT id FROM $mapTbl WHERE refTypeID = :recID LIMIT 1";
                        $params1 = [':recID' => $recID];
                        // has referenc columns?
                        $sql2 = "SELECT id FROM $refTbl WHERE refTypeID = :recID LIMIT 1";
                        $params2 = [':recID' => $recID];
                        if ($db->fetchValue($sql1, $params1) || $db->fetchValue($sql2, $params2)) {
                            $ErrorTitle = 'Deletion Failed';
                            $ErrorMsg = 'Can not delete while it has mapping records '
                            . 'or reference columns.';
                        } elseif ($db->query($delSql, $delparams)) {
                            $Result = 1;
                            // $Modified = $db->affected_rows();
                            $Listing = $dt;
                            $ListID  = 0;
                        } else {
                            $ErrorTitle = 'Deletion Failed';
                            $ErrorMsg = 'An unexpected error occurred.';
                        }
                        break;

                    case 'map':
                        $listID = intval($params['a3']);
                        $delSql = "DELETE FROM $tbl WHERE id = :recID LIMIT 1";
                        $delparams = [':recID' => $recID];
                        // has mapped data columns?
                        $mapTbl = $_tables['map'];
                        $sql = "SELECT id FROM $mapTbl WHERE mapTo = :recID LIMIT 1";
                        $params = [':recID' => $recID];
                        if ($db->fetchValue($sql, $params)) {
                            $ErrorTitle = 'Deletion Failed';
                            $ErrorMsg = 'Can not delete while it has mapped data columns.';
                        } elseif ($db->query($delSql, $delparams)) {
                            $Listing = $dt;
                            $ListID  = $listID;
                        } else {
                            $ErrorTitle = 'Deletion Failed';
                            $ErrorMsg = 'An unexpected error occurred.';
                        }
                        break;

                    case 'ref':
                    case 'col':
                        $listID = intval($params['a3']);
                        $delSql = "DELETE FROM $tbl WHERE colType <> 'alias' AND id = :recID LIMIT 1";
                        $delparams = [':recID' => $recID];
                        // has aliases?
                        $sql = "SELECT id FROM $tbl WHERE colType = 'alias' AND aliasOf = :recID LIMIT 1";
                        $params = [':recID' => $recID];
                        if ($db->fetchValue($sql, $params)) {
                            $ErrorTitle = 'Deletion Failed';
                            $ErrorMsg = 'Can not delete column while it has aliases.';
                        } elseif ($db->query($delSql, $delparams)) {
                            $Result = 1;
                            $Listing = $dt;
                            $ListID  = $listID;
                        } else {
                            $ErrorTitle = 'Deletion Failed';
                            $ErrorMsg = 'An unexpected error occurred.';
                        }
                        break;
                }
                return ['delSql' => $delSql, 'ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg, 'Listing' => $Listing, 'ListID' => $ListID, 'recID' => $recID];
            break;

            default:
                return ['ErrorTitle' => $ErrorTitle, 'ErrorMsg' => $ErrorMsg];
        }
    }
}
