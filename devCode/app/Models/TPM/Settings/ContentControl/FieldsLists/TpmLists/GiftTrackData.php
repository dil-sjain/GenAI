<?php
/**
 * Model for Gift Tracking data operations in TPM fields/lists.
 */

namespace Models\TPM\Settings\ContentControl\FieldsLists\TpmLists;

use Models\TPM\Settings\ContentControl\FieldsLists\TpmLists\TpmListsData;
use \Lib\Support\ValidationCustom;

/**
 * Class GiftTrackingData handles data modeling for Gift Tracking in the TPM application fields/lists.
 *
 * @keywords tpm, fields lists, model, settings, gift tracking, gifts
 */
#[\AllowDynamicProperties]
class GiftTrackData extends TpmListsData
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    protected $DB = null;

    /**
     * @var string Current scope (type) of custom field. (Allowed: `case` or `thirdparty`)
     */
    protected $scope = '';

    /**
     * @var integer The current tenantID
     */
    protected $tenantID = 0;

    /**
     * @var integer The current userID
     */
    protected $userID = 0;

    /**
     * @var object Tables used by the model.
     */
    protected $tbl = null;

    /**
     * @var array Translation text array
     */
    protected $txt = [];

    /**
     * @var boolean Tenant has 3P access
     */
    private $hasTpm = false;

    /**
     * @var boolean Tenant has Risk Tier(s)
     */
    private $hasRisk = false;


    /**
     * Init class constructor
     *
     * @param integer $tenantID   Current tenantID
     * @param integer $userID     Current userID
     * @param array   $initValues Any additional parameters that need to be passed in
     */
    public function __construct($tenantID, $userID, $initValues = [])
    {
        parent::__construct($tenantID, $userID, $initValues);
        $this->setTpAccessAndTier();
    }

    /**
     * Setup required table names in the tbl object. This overwrites parent stub method.
     *
     * @return void
     */
    #[\Override]
    protected function setupTableNames()
    {
        $clientDB  = $this->DB->getClientDB($this->tenantID);
        $this->tbl->giftRules = $clientDB .'.tpGiftRules';
        $this->tbl->riskTier  = $clientDB .'.riskTier';
        $this->tbl->tpType    = $clientDB .'.tpType';
        $this->tbl->tpTypeCat = $clientDB .'.tpTypeCategory';
    }

    /**
     * Get all data for initial display. Tiers, Types, Cats, Rules, etc.
     *
     * @see See individual methods for data format of returned key values.
     * @return array [Tiers => , Types => , Cats =>, DefaultRule => , Rules =>]
     */
    public function getInitData()
    {
        $typeCats = ($this->hasTpm) ? $this->getTypeCats() : '';
        $tiers = ($this->hasRisk) ? $this->getTiers() : '';
        return [
            'Tiers' => $tiers,
            'Types' => $typeCats['types'],
            'Cats'  => $typeCats['cats'],
            'Rules' => $this->setupRuleData($tiers, $typeCats['types'], $typeCats['cats']),
            'Tpm'   => $this->hasTpm,
            'Risk'  => $this->hasRisk,
        ];
    }

    /**
     * Public wrapper to handle save routine and returning appropriate ruleset.
     *
     * @param array $vals Values to be stored
     *
     * @return array
     */
    public function save($vals)
    {
        $isNew = true;
        $save = $this->saveRule($vals);
        if ($save['Result'] == 1) {
            if (!empty($vals['rid']) && $vals['rid'] > 0) {
                $this->deactivateRule($vals['rid'], $vals['name'], true);
                $isNew = false;
            }
            if (!$isNew) {
                $rule = $this->getSingleRule($vals['name']);
                $rule['order'] = $vals['order'];
                return [
                    'Result' => 1,
                    'isNew'  => 0,
                    'Rule'   => $rule,
                ];
            }
            $typeCats = ($this->hasTpm) ? $this->getTypeCats() : '';
            $tiers = ($this->hasRisk) ? $this->getTiers() : '';
            // when adding a new rule, too many things change to keep track of.
            // so we're going to just resupply the entire rule list to the dom
            // instead of just updating a single rule as above.
            return [
                'Result' => 1,
                'isNew'  => 1,
                'Rules'   => $this->setupRuleData($tiers, $typeCats['types'], $typeCats['cats']),
            ];
        }
        return [
            'Result' => 0,
            'ErrTitle' => $save['ErrTitle'],
            'ErrMsg'   => $save['ErrMsg'],
        ];
    }

    /**
     * Public wrapper to handle removing (soft delete) a rule
     *
     * @param integer $id   tpGiftRules.id
     * @param string  $name tpGiftRules.name
     *
     * @return array [Result => 1/0] (add errTitle/Msg if Result is 0)
     */
    public function remove($id, $name)
    {
        if ($this->deactivateRule($id, $name)) {
            return ['Result' => 1];
        }
        return [
            'Result' => 0,
            'ErrTitle' => $this->txt['one_error'],
            'ErrMsg'   => $this->txt['fl_error_gift_rule_remove_fail'],
        ];
    }

    /**
     * Get all risk tiers
     *
     * @return array Array of Objects  (db result)
     */
    protected function getTiers()
    {
        $sql = "SELECT id, tierName AS name FROM {$this->tbl->riskTier} WHERE clientID = :tenantID "
            ."ORDER BY tierName ASC";
        $tiers = $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID]);
        $rtn = [];
        $x = 0;
        foreach ($tiers as $t) {
            $rtn[$t->id] = ['id' => $t->id, 'name' => $t->name, 'order' => $x];
            $x++;
        }
        return $rtn;
    }

    /**
     * Get all 3P Types w/ categories
     *
     * @return array Array of Objects  (db result)
     */
    protected function getTypeCats()
    {
        $sql = "SELECT t.id AS typeID, t.name AS typeName, c.id AS catID, c.name AS catName \n"
            ."FROM {$this->tbl->tpType} AS t \n"
            ."LEFT JOIN {$this->tbl->tpTypeCat} AS c ON ( t.id = c.tpType AND t.clientID = c.clientID ) \n"
            ."WHERE t.clientID = :tenantID \n"
            ."ORDER BY t.name ASC , c.name ASC";
        $recs = $this->DB->fetchObjectRows($sql, [':tenantID' => $this->tenantID]);
        // now we organize
        $types = [];
        $cats = [];
        $i = $x = 0;
        $typeID = 0;
        foreach ($recs as $r) {
            if ($r->typeID != $typeID) {
                $types[$r->typeID] = ['id' => $r->typeID, 'name' => $r->typeName, 'order' => $i];
                $typeID = $r->typeID;
                $i++;
                $x = 0; //reset cat order for new type parent;
            }
            $cats[$r->typeID][$r->catID] = [
                'id'  => $r->catID,
                'name' => $r->catName,
                'order' => $x,
            ];
            $x++;
        }
        return ['types' => $types, 'cats' => $cats];
    }

    /**
     * Get all gift rules
     *
     * @return array Array of Objects  (db result)
     */
    protected function getRules()
    {
        $rules = $this->DB->fetchObjectRows(
            "SELECT * FROM {$this->tbl->giftRules} WHERE clientID = :tenantID AND status = :active "
            ."ORDER BY riskTierID ASC, tpTypeID ASC, tpCatID ASC",
            [':tenantID' => $this->tenantID, ':active' => 'active']
        );
        if (!$rules || $rules[0]->name != '0-0-0') {
            $this->createDefaultRule();
            return $this->getRules();
        }
        return $rules;
    }

    /**
     * Get single gift rule formatted for a return to view
     *
     * @param string $name tpGiftRules.name
     *
     * @return array formatted rule from (db result)
     */
    protected function getSingleRule($name)
    {
        [$rtID, $tptID, $tpcID] = explode('-', $name);
        $rule = $this->DB->fetchObjectRow(
            "SELECT gr.*, rt.tierName AS tierName, tt.name AS typeName, tc.name AS catName "
            ."FROM {$this->tbl->giftRules} AS gr "
            ."LEFT JOIN {$this->tbl->riskTier} AS rt ON (gr.riskTierID = rt.id) "
            ."LEFT JOIN {$this->tbl->tpType} AS tt ON (gr.tpTypeID = tt.id) "
            ."LEFT JOIN {$this->tbl->tpTypeCat} AS tc ON (gr.tpCatID = tc.id) "
            ."WHERE gr.clientID = :tenantID AND gr.riskTierID = :rtID AND gr.tpTypeID = :tptID "
                ."AND gr.tpCatID = :tpcID AND gr.status = :active LIMIT 1",
            [
                ':tenantID' => $this->tenantID,
                ':rtID'     => $rtID,
                ':tptID'    => $tptID,
                ':tpcID'    => $tpcID,
                ':active'   => 'active',
            ]
        );
        $tier = (!empty($rule->tierName)) ? $rule->tierName : $this->txt['all_capital_a'];
        $type = (!empty($rule->typeName)) ? $rule->typeName : $this->txt['all_capital_a'];
        $cat  = (!empty($rule->catName))  ? $rule->catName : $this->txt['all_capital_a'];
        unset($rule->tierName, $rule->typeName, $rule->catName);
        return [
            'tier' => $tier,
            'type' => $type,
            'cat'  => $cat,
            'vals' => $rule,
        ];
    }

    /**
     * Save rule to database
     *
     * @param array $vals Values to be saved
     *
     * @return array Return array with result = 1 on success, else 0 and set errTitle/Msg
     */
    protected function saveRule($vals)
    {
        $defaultVals = $this->getSingleRule('0-0-0');
        $defaultVals = $defaultVals['vals'];
        $sqlFields = [
            'status = :status',
            'clientID = :tenantID',
        ];
        $params = [
            ':status'   => 'active',
            ':tenantID' => $this->tenantID,
        ];
        foreach ($defaultVals as $dKey => $dVal) {
            if (in_array($dKey, ['id', 'status', 'tstamp', 'clientID', 'tierName', 'typeName', 'catName'])) {
                continue;
            }
            $sqlFields[] = $dKey .' = :'. $dKey;
            $params[':'.$dKey] = (!isset($vals[$dKey])) ? $dVal : $this->validateRuleData($dKey, $vals[$dKey], $dVal);
        }
        $params[':name'] = $params[':riskTierID'] .'-'. $params[':tpTypeID'] .'-'. $params[':tpCatID'];
        $sql = "INSERT INTO {$this->tbl->giftRules} SET ";
        $sql .= implode(', ', $sqlFields);
        $add = $this->DB->query($sql, $params);
        if ($add->rowCount() == 1) {
            return ['Result' => 1];
        }
        return [
            'Result'   => 0,
            'ErrTitle' => $this->txt['one_error'],
            'ErrMsg'   => $this->txt['fl_error_gift_rule_save_fail'],
        ];
    }

    /**
     * Validate rule values and pass back value or default value if not set/exists.
     *
     * @param string $field tpGiftRules.[$field]
     * @param mixed  $val   Value to be saved for tpGiftRules.[$field]
     * @param mixed  $dVal  Default value for the field being validated
     *
     * @return mixed Returns submitted value unless empty or invalid, which it then returns default value.
     */
    private function validateRuleData($field, mixed $val, mixed $dVal)
    {
        switch ($field) {
            case 'approvalMethod':
            case 'denialMethod':
                $value = ucfirst(strtolower((string) $val));
                if ($value == 'Auto' || $value == 'Manual') {
                    $val = $value;
                } else {
                    $val = $dVal;
                }
                break;
            case 'riskTierID':
            case 'tpTypeID':
            case 'tpCatID':
            case 'limitReceive':
            case 'limitGive':
            case 'limitReceiveAgg':
            case 'limitGiveAgg':
                $val = (int)$val;
                break;
            case 'emailNotification':
                $value = (int)$val;
                if ($value == 1 || $value == 0) {
                    $val = $value;
                } else {
                    $val = $dVal;
                }
                break;
            case 'email':
                $value = strtolower((string) $val);
                if ($value !== '') {
                    $validation = (new ValidationCustom)->validateEmail($value);
                    if ($validation['result'] && empty($validation['errMsg'])) {
                        $val = $value;
                    } else {
                        $val = $dVal;
                    }
                } else {
                    $val = $dVal;
                }
                break;
            case 'ytdMethod':
                $value = strtolower((string) $val);
                if ($value === 'ytd') {
                    $val = 'YTD';
                } elseif ($value === 'user') {
                    $val = 'User';
                } else {
                    $val = $dVal;
                }
                break;
            case 'ytdUser':
                $value = explode('-', (string) $val);
                if (count($value) == 2) {
                    $value[0] = (int)$value[0];
                    $value[1] = (int)$value[1];
                    if (($value[0] <= 12 && $value[0] > 0) && ($value[1] <= 31 && $value[1] > 0)) {
                        if ($value[0] < 10) {
                            $value[0] = '0'. $value[0];
                        }
                        if ($value[1] < 10) {
                            $value[1] = '0'. $value[1];
                        }
                        $val = $value[0] .'-'. $value[1];
                    } else {
                        $val = $dVal;
                    }
                } else {
                    $val = $dVal;
                }
                break;
            default:
                if (!empty($dVal)) {
                    $val = $dVal;
                }
        }
        return $val;
    }

    /**
     * Set a rule to the deleted state.
     *
     * @param integer $id       tpGiftRules.id
     * @param string  $name     tpGiftRules.name
     * @param boolean $isUpdate If a rule was saved before turning off the old one, pass true.
     *
     * @return boolean Return true on success, else false
     */
    private function deactivateRule($id, $name, $isUpdate = false)
    {
        $sql = "UPDATE {$this->tbl->giftRules} SET status = :status WHERE id = :id AND name = :name "
            ." AND clientID = :tenantID LIMIT 1";
        $params = [':status' => 'deleted', ':id' => $id, ':name' => $name, ':tenantID' => $this->tenantID];
        $rem = $this->DB->query($sql, $params);
        if ($rem->rowCount()) {
            return true;
        }
        $rule = $this->getSingleRule($name);
        $rule = $rule['vals'];
        if (!$rule->id) {
            return false;
        }
        $params[':id'] = $rule->id;
        $params[':name'] = $rule->name;
        $rem2 = $this->db->query($sql, $params);
        if ($rem2->rowCount()) {
            return true;
        }
        // final attempt to ensure it's cleaned up.
        $sql2 = "SELECT id, name, tstamp FROM {$this->tbl->giftRules} WHERE name = :name AND status = :status "
            ."AND clientID = :tenantID ORDER BY tstamp DESC";
        $params = [':name' => $name, ':status' => 'active', ':tenantID' => $this->tenantID];
        $actRules = $this->DB->fetchObjectRows($sql2, $params);
        if ($actRules) {
            if (!$isUpdate) {
                $remAll = "UPDATE {$this->tbl->giftRules} SET status = :status "
                    ."WHERE name = :name AND clientID = :tenantID";
                $params = [':status' => 'deleted', ':name' => $actRules[0]->name, ':tenantID' => $this->tenantID];
                $allSql = $this->DB->query($remAll, $params);
                if ($allSql->rowCount()) {
                    return true;
                }
                return false;
            } elseif (count($actRules) > 1) {
                $x = 0;
                foreach ($actRules as $ar) {
                    if ($x == 0) {
                        // most recent rule, so we want to keep this one.
                        $x++;
                        continue;
                    }
                    $params[':id'] = $ar->id;
                    $params[':name'] = $ar->name;
                    $remRule = $this->DB->query($sql, $params);
                    if (!$remRule->rowCount()) {
                        return false;
                    }
                }
                return true;
            }
        }
        return true; // either the save failed, and we wouldn't be here,
    }

    /**
     * Set up rules result from getRules into organized information for initialized display.
     *
     * @param array $tiers Output of this->getTiers
     * @param array $types Output of this->getTypeCats [types key]
     * @param array $cats  Output of this->getTypeCats [cats key]
     *
     * @return array [ruleID => values]
     */
    private function setupRuleData($tiers, $types, $cats)
    {
        $rules = $this->getRules();
        $rtn = [];
        foreach ($rules as $r) {
            unset($r->status, $r->tstamp, $r->clientID);
            $tmp[$r->name] = [
                'tier' => ($r->riskTierID > 0) ? $tiers[$r->riskTierID]['name'] : $this->txt['all_capital_a'],
                'type' => ($r->tpTypeID > 0)   ? $types[$r->tpTypeID]['name'] : $this->txt['all_capital_a'],
                'cat'  => ($r->tpCatID > 0)    ? $cats[$r->tpTypeID][$r->tpCatID]['name'] : $this->txt['all_capital_a'],
                'vals' => $r,
            ];
        }
        // now to set the order alphabetically by name.
        $tmpOrder = [];
        foreach ($tmp as $t) {
            if ($t['vals']->name == '0-0-0') {
                continue;
            }
            $tmpOrder[$t['tier'] .'-'. $t['type'] .'-'. $t['cat']] = $t['vals']->name;
        }
        ksort($tmpOrder);
        $rtn = [
            '0-0-0' => $tmp['0-0-0']
        ];
        $rtn['0-0-0']['order'] = 0;
        $x = 1;
        foreach ($tmpOrder as $to) {
            $rtn[$to] = $tmp[$to];
            $rtn[$to]['order'] = $x;
            $x++;
        }
        return $rtn;
    }

    /**
     * Can this record be deleted?
     *
     * @param integer $id customField.id
     *
     * @return boolean True if $id can be deleted, else false
     */
    private function canDelete($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        $rec = $this->fetchFields($id);
        if ($rec && $rec->canDel == 1) {
            return true;
        }
        return false;
    }

    /**
     * Check for a valid ruleset and create default if none exist
     *
     * @return void
     */
    private function createDefaultRule()
    {
        $test = $this->DB->fetchValue(
            "SELECT count(id) FROM {$this->tbl->giftRules} "
            ."WHERE clientID = :tenantID AND riskTierID = 0 AND tpTypeID = 0 AND tpCatID = 0 LIMIT 1",
            [':tenantID' => $this->tenantID]
        );
        if (!$test) {
            $sql = "INSERT INTO {$this->tbl->giftRules} SET "
                ."clientID = :tenantID, name = '0-0-0', emailNotification = 1, email = :email, status = 'active'";
            $params = [
                ':tenantID' => $this->tenantID,
                ':email' => $this->app->session->authUserEmail,
            ];
            $this->DB->query($sql, $params);
        }
    }

    /**
     * Set values for 3P access and tier
     *
     * @return void
     */
    private function setTpAccessAndTier()
    {
        $this->hasTpm = ($this->app->ftr->has(\Feature::TENANT_TPM) ? true : false);
        $this->hasRisk = ($this->app->ftr->has(\Feature::TENANT_TPM_RISK) ? true : false);
    }
}
