<?php
/**
 * Provide data for Risk Model Details, Assessment Detail and Assessment History
 */

namespace Models\TPM\RiskModel;

use Models\TPM\Settings\ContentControl\RiskInventory\RiskInventory;
use Models\TPM\RiskModel\RiskAssessment;
use Models\TPM\RiskModel\RiskModels;
use Models\ThirdPartyManagement\RiskModel;
use Models\TPM\RiskModel\RiskModelTier;
use Models\TPM\RiskModel\RiskTier;
use Models\TPM\CaseTypeClientBL;
use Models\TPM\TpProfile\TpType;
use Models\TPM\TpProfile\TpTypeCategory;
use Models\ThirdPartyManagement\ThirdParty;
use Models\Globals\Geography;
use Models\TPM\IntakeForms\DdqName;
use Models\TPM\IntakeForms\Legacy\OnlineQuestions;
use Models\TPM\CustomField;
use Models\TPM\CustomSelectList;
use Lib\Validation\Validator\CsvIntList;
use Lib\Services\BbTag;

/**
 * Provide data for various dialogs and PDFs related to risk model and assessments
 *
 * @keywords risk, assessment, history, risk model, risk assessment, pdf
 */
class RiskDetails extends RiskInventory
{
    /**
     * Get date need to display Risk Model Detail widget
     *
     * @param integer $rmodelID riskModel.id
     *
     * @return mixed false on no match or assoc array of data
     */
    public function modelDetail($rmodelID)
    {
        $model =  (new RiskModels($this->tenantID))->getRecord($rmodelID);
        if (empty($model)) {
            return false;
        }
        $factors = $this->getFactorData($rmodelID);
        $vars = [
            'published' => (($model['status'] == 'setup')
                ? $this->trans->codeKey('value_not_yet_paren')
                : $model['updated']),
            'status' => ucfirst((string) $model['status']),
            'tpTypeName' => (new TpType($this->tenantID))->selectValueByID($model['tpType'], 'name'),
            'pdfRef' => $rmodelID,
            'hasCat' => false,
            'hasCpi' => false,
            'hasDdq' => false,
            'hasCufld' => false,
            'hasCatError' => false,
            'hasDdqError' => false,
            'hasCufldError' => false
        ];
        $errors = ['cat' => '', 'ddq' => [], 'cufld' => []];
        $cats = [];
        $catMdl = new TpTypeCategory($this->tenantID);
        $catIDs = explode(',', (string) $model['categories']);
        foreach ($catIDs as $catID) {
            $cat = $catMdl->selectByID($catID, ['id', 'name']);
            if (is_array($cat)) {
                $cats[] = $cat;
            } else {
                $vars['hasCatError'] = true;
                $errors['cat'] .= $catID;
            }
        }
        $scopes = (new CaseTypeClientBL($this->tenantID))->getRecords(true);
        $tiers = [];
        $tierRecs = (new RiskModelTier($this->tenantID))
            ->selectMultiple([], ['model' => $rmodelID], 'ORDER BY threshold DESC');
        $tierMdl = new RiskTier($this->tenantID);
        $prevThresh = 101;
        foreach ($tierRecs as $rec) {
            $tierRec = $tierMdl->selectByID($rec['tier']);
            $scope = $rec['scope'];
            // Not using direct lookup to deal with variable behavior of internal review
            foreach ($scopes as $s) {
                if ($s['id'] == $rec['scope']) {
                    $scope = $s['name'];
                    break;
                }
            }
            $tiers[] = [
                'name' => $tierRec['tierName'],
                'scope' => $scope,
                'thresh' => '' . $rec['threshold'] . ' &ndash; ' . ($prevThresh - 1),
                'fg' => $tierRec['tierTextColor'],
                'bg' => $tierRec['tierColor'],
            ];
            $prevThresh = $rec['threshold'];
        }

        // tpcat
        if ($model['components'] & RiskModel::TPCAT) {
            $vars['hasCat'] = true;
        }

        // cpi
        $cpiRanges = [];
        $cpiOverrides = [];
        if ($model['components'] & RiskModel::CPI) {
            $vars['hasCpi'] = true;
            $factor = $factors['cpi']['factor'];
            $geo = Geography::getVersionInstance();
            foreach ($factor->overrides as $ref => $score) {
                $iso = substr((string) $ref, 12);
                $cpiOverrides[] = [
                    'country' => $geo->getLegacyCountryName($iso),
                    'score' => $score,
                ];
            }
            $first = true;
            $prevThresh = 0;
            foreach ($factor->scores as $ref => $score) {
                $thresh = substr((string) $ref, strlen('cpirangescore-'));
                $cpiRanges[] = [
                    'range' => (($first) ? "&gt;= $thresh" : "&gt;= $thresh and &lt; $prevThresh"),
                    'score' => $score,
                ];
                $first = false;
                $prevThresh = $thresh;
            }
        }

        // ddq
        $ddqData = [];
        if ($model['components'] & RiskModel::DDQ) {
            $vars['hasDdq'] = true; // show ddq factor details
            $oqMdl = new OnlineQuestions($this->tenantID);
            // Process each scored ddq
            foreach ($factors['ddq'] as $ddqRef => $ddq) {
                $ddqData[$ddqRef] = [
                    'maxScore' => $ddq['factor']->maxScore,
                    'questions' => [],
                ];
                // Get scored questions and answers
                $questions = $oqMdl->getRiskFactorQuestions($ddqRef, $rmodelID);
                if (is_array($questions) && count($questions)) {
                    // Check all eligle questions
                    foreach ($questions as $qRow) {
                        $qid = $qRow->questionID;
                        // Grab it only if it was scored in this risk model
                        if (!array_key_exists($qid, $ddq['factor']->scores)) {
                            continue;
                        }

                        $items = [];


                        if ($qRow->controlType == 'radioYesNo') {
                            $score = '';
                            if (isset($ddq['factor']->scores[$qid][$qid . '-' . 'yes'])) {
                                $score = $ddq['factor']->scores[$qid][$qid . '-' . 'yes'];
                            } elseif (isset($ddq['factor']->scores[$qid][$qid . '-' . 'Yes'])) {
                                $score = $ddq['factor']->scores[$qid][$qid . '-' . 'Yes'];
                            }
                            $items[] = [
                                'ans' => 'Yes',
                                'score' => $score,
                            ];
                            $score = '';
                            if (isset($ddq['factor']->scores[$qid][$qid . '-' . 'no'])) {
                                $score = $ddq['factor']->scores[$qid][$qid . '-' . 'no'];
                            } elseif (isset($ddq['factor']->scores[$qid][$qid . '-' . 'No'])) {
                                $score = $ddq['factor']->scores[$qid][$qid . '-' . 'No'];
                            }
                            $items[] = [
                                'ans' => 'No',
                                'score' => $score,
                            ];
                        } else {
                            // Get list items (answers) and score for each
                            foreach ($qRow->listItems as $id => $name) {
                                $key = $qid . '-' . $id;
                                if ($missingKey = $this->missingArrayKeys($ddq['factor']->scores, $qid, $key, 'ddq')) {
                                    $vars['hasDdqError'] = true;
                                    $errors['ddq'][] = "[$missingKey] {$name}";
                                } else {
                                    $items[] = [
                                        'ans' => $name,
                                        'score' => $ddq['factor']->scores[$qid][$key],
                                    ];
                                }
                            }
                        }
                        $ddqData[$ddqRef]['questions'][] = [
                            'ques' => $qRow->labelText,
                            'items' => $items,
                        ];
                    }
                }
            }
        }

        // cufld
        $cufldData = [];
        if ($model['components'] & RiskModel::CUFLD) {
            $vars['hasCufld'] = true;
            $factor = $factors['cufld']['factor'];
            $data = $this->getCuFldData();
            if (is_array($data)) {
                $bbt = new BbTag();
                $fields = $data['fields'];
                $lists = $data['listItems'];
                foreach ($fields as $fld) {
                    if (!array_key_exists('cufld-' . $fld['id'], $factor->fields)) {
                        continue;
                    }
                    $fld['prompt'] = $bbt->removeTags($fld['prompt']);
                    $isNumeric = false;
                    $itemType = '';
                    $max = $min = '';
                    $items = [];
                    switch ($fld['type']) {
                        case 'select':
                        case 'radio':
                            $itemType = 'one';
                            break;
                        case 'check':
                            $itemType = 'multiple';
                            break;
                        case 'numeric':
                            $isNumeric = true;
                            $max = $fld['maxValue'];
                            $min = $fld['minValue'];
                            break;
                    }
                    if (!empty($itemType) && array_key_exists($fld['listName'], $lists)) {
                        foreach ($lists[$fld['listName']] as $item) {
                            $fldRef = 'cufld-' . $fld['id'];
                            $itemRef = $fldRef . '-' . $item['id'];
                            if ($missingKey = $this->missingArrayKeys($factor, $fldRef, $itemRef, 'cufld')) {
                                $vars['hasCufldError'] = true;
                                $errors['cufld'][] = "[$missingKey] {$item['name']}";
                            } else {
                                $items[] = [
                                    't' => $item['name'],
                                    's' => $factor->fields[$fldRef]['scores'][$itemRef],
                                ];
                            }
                        }
                    }
                    $dat = [
                        'pmt' => $fld['prompt'],
                        'isNumeric' => $isNumeric,
                        'max' => $max,
                        'min' => $min,
                        'itemType' => $itemType,
                        'items' => $items,
                    ];
                    $cufldData[$fld['name']] = $dat;
                }
            }
        }

        return [
            'model' => $model,
            'cats' => $cats,
            'tiers' => $tiers,
            'factors' => $factors,
            'cpiRanges' => $cpiRanges,
            'cpiOverrides' => $cpiOverrides,
            'ddqData' => $ddqData,
            'cufldData' => $cufldData,
            'vars' => $vars,
            'errors' => $errors
        ];
    }

    /**
     * Get data needed to display Risk Assessment Detail widget
     *
     * @param integer $tpID     thirdPartyProfile.id
     * @param integer $rmodelID riskModel.id
     * @param string  $status   (optional) valid status value, usually for 'current' or 'test'
     * @param string  $tstamp   (optional) 'Y-m-d H:i:s' timestamp
     *
     * @return mixed false on no match or assoc array of data
     */
    public function assessmentDetail($tpID, $rmodelID, $status = null, $tstamp = null)
    {
        if (empty($tpID) || empty($rmodelID)) {
            return false;
        }
        $assessment = null;
        $assess = (new RiskAssessment($this->tenantID))->getRecord($tpID, $rmodelID, $status, $tstamp);
        if (empty($assess)) {
            return false;
        }
        $assessment = $assess['details'];
        unset($assess['details']);
        $factors = $this->getFactorData($rmodelID);
        $model =  (new RiskModels($this->tenantID))->getRecord($rmodelID);
        $cols = ['userTpNum', 'legalName', 'tpType', 'tpTypeCategory', 'country'];
        $profile = (new ThirdParty($this->tenantID))->findById($tpID, $cols)->getAttributes();
        $pdfRef = str_replace(['-', ':', ' '], '', (string) $assess['tstamp'])
            . 'M' . $rmodelID
            . 'N' . $assess['normalized']
            . 'T' . $tpID;
        $scopeWhere = [
            'tier' => $assess['tier'],
            'model' => $rmodelID,
        ];
        $scopeID = (new RiskModelTier($this->tenantID))->selectValue('scope', $scopeWhere);
        if ($scopeID == CaseTypeClientBL::CASETYPE_INTERNAL) {
            $scopeName = CaseTypeClientBL::CASETYPE_INTERNAL_NAME;
        } else {
            $scopeName
                = (new CaseTypeClientBL($this->tenantID))->selectValue('name', ['caseTypeID' => $scopeID]);
        }
        if (empty($scopeName)) {
            $scopeName = '???';
        }
        $tierCols = [
            'tierName AS `name`',
            'tierColor AS `bg`',
            'tierTextColor AS `fg`',
        ];
        $vars = [
            'tier' => (new RiskTier($this->tenantID))->selectByID($assess['tier'], $tierCols),
            'scopeName' => $scopeName,
            'pdfRef' => $pdfRef,
            'hasCat' => false,
            'hasCpi' => false,
            'hasDdq' => false,
            'hasCufld' => false,
        ];

        // tpcat
        if ($model['components'] & RiskModel::TPCAT) {
            $factor = $factors['tpcat']['factor'];
            $score  = $assessment->tpcat->score;
            $vars['hasCat'] = true;
            $vars['tpcatName'] = (new TpTypeCategory($this->tenantID))
                ->selectValue('name', ['id' => $profile['tpTypeCategory']]);
            // Component total
            if ($score >= 0) {
                $ttl = ($factor->maxScore < $model['weights']['tpcat'])
                    ? $model['weights']['tpcat']
                    : $factor->maxScore;
            } else {
                $ttl = (($factor->minScore * -1) < $model['weights']['tpcat'])
                    ? ($model['weights']['tpcat'] * -1)
                    : $factor->minScore;
            }
            $vars['tpcatCompTotal'] = $ttl;
        }

        // cpi
        if ($model['components'] & RiskModel::CPI) {
            $vars['hasCpi'] = true;
            $factor = $factors['cpi']['factor'];
            $score  = $assessment->cpi->score;
            $iso = $assessment->cpi->iso;
            $vars['profileCountry'] = '';
            $vars['ddqCountry'] = '';
            $vars['cpiCountry'] = '';
            $geo = Geography::getVersionInstance();
            if ($assessment->cpi->fromProfile) {
                $vars['profileCountry'] = $geo->getLegacyCountryName($assessment->cpi->fromProfile);
            }
            if ($assessment->cpi->fromDDQ) {
                $vars['ddqCountry'] = $geo->getLegacyCountryName($assessment->cpi->fromDDQ);
            }
            if ($assessment->cpi->iso) {
                $vars['cpiCountry'] = $geo->getLegacyCountryName($assessment->cpi->iso);
            }
            // Component total
            if ($score >= 0) {
                $ttl = ($factor->maxScore < $model['weights']['cpi'])
                    ? $model['weights']['cpi']
                    : $factor->maxScore;
            } else {
                $ttl = (($factor->minScore * -1) < $model['weights']['cpi'])
                    ? ($model['weights']['cpi'] * -1)
                    : $factor->minScore;
            }
            $vars['cpiCompTotal'] = $ttl;
        }

        // ddq
        if (isset($assessment->ddq) && $model['components'] & RiskModel::DDQ) {
            $vars['hasDdq'] = true; // show ddq factor details
            $vars['ddqCompTotal'] = $model['weights']['ddq']; // default
            $assessment->ddq->ddqName = '';                   // ...
            if ($assessment->ddq->hasDDQ == 1 && (0 < (int)$assessment->ddq->ddqPID)) {
                if ($assessment->ddq->ddqType == 'legacy') {
                    $def = $assessment->ddq->ddqRef;
                    $factor = $factors['ddq'][$def]['factor']; // just the matched one
                    $score  = $assessment->ddq->score;
                    $ddqName = (new DdqName($this->tenantID))->selectValue('name', ['legacyID' => $def]);
                    if (empty($ddqName)) {
                        $ddqName = $def;
                    }
                    $assessment->ddq->ddqName = $ddqName;
                    $nameParts = $this->splitLegacyID($def);
                    $oqMdl = new OnlineQuestions($this->tenantID);
                    foreach ($assessment->ddq->answers as $rawqid => $ary) {
                        $qid = $rawqid;
                        // get question
                        $cols = ['labelText', 'generalInfo'];
                        $where = [
                            'questionID' => $qid,
                            'caseType' => $nameParts['ddqType'],
                            'ddqQuestionVer' => $nameParts['ddqVersion'],
                            'languageCode' => 'EN_US',
                        ];
                        $qRow = $oqMdl->selectOne($cols, $where);
                        $q = strip_tags(str_replace('&nbsp;', ' ', (string) $qRow['labelText']));
                        // get answer text
                        if (isset($ary['answer']) && $ary['answer'] == '') {
                            $a = $this->trans->codeKey('value_missing_paren');
                        } elseif (isset($ary['valid']) && $ary['valid'] == 0) {
                            $a = $this->trans->codeKey('value_invalid_answer_paren');
                        } elseif (isset($ary['answer']) && ($ary['answer'] == 'Yes' || $ary['answer'] == 'No'
                            || $ary['answer'] == 'yes' || $ary['answer'] == 'no')
                        ) {
                            $a = $ary['answer'];
                        } else {
                            // get from table
                            if (isset($qRow['generalInfo'])) {
                                [$tbl, $useClient] = explode(',', (string) $qRow['generalInfo']);
                                if (isset($tbl) && $this->DB->tableExists($tbl)) {
                                    $params = [':ans' => $ary['answer']];
                                    $sql = "SELECT name FROM $tbl WHERE id = :ans";
                                    if ($useClient) {
                                        $sql .= " AND clientID = :cli";
                                        $params[':cli'] = $this->tenantID;
                                    }
                                    $sql .= ' LIMIT 1';
                                    $a = $this->DB->fetchValue($sql, $params);
                                }
                            } else {
                                $a = '';
                            }
                        }
                        // add to $assessment
                        $assessment->ddq->answers[$rawqid]['q'] = $q;
                        $assessment->ddq->answers[$rawqid]['a'] = $a;
                    }
                    // Component total
                    if ($score >= 0) {
                        $ttl = ($factor->maxScore < $model['weights']['ddq'])
                            ? $model['weights']['ddq']
                            : $factor->maxScore;
                    } else {
                        $ttl = (($factor->minScore * -1) < $model['weights']['ddq'])
                            ? ($model['weights']['ddq'] * -1)
                            : $factor->minScore;
                    }
                    $vars['ddqCompTotal'] = $ttl;
                } else {
                    $assessment->ddq->hasDDQ = 0; // non-legacy DDQ?
                }
            } else {
                $assessment->ddq->hasDDQ = 0;
            }
        }

        // cufld
        if ($model['components'] & RiskModel::CUFLD) {
            $vars['hasCufld'] = true;
            $factor = $factors['cufld']['factor'];
            $vars['cufldNoA'] = $factor->unanswered;
            $vars['cufldFactor'] = $factor;
            $score  = $assessment->cufld->score;
            $cfMdl = new CustomField($this->tenantID);
            $cslMdl = new CustomSelectList($this->tenantID);
            $bbt = new BbTag();
            foreach ($assessment->cufld->answers as $rawid => $ary) {
                $fid = substr((string) $rawid, 6);
                $fld = $factor->fields[$rawid];
                $fldRow = $cfMdl->selectByID($fid, ['name', 'prompt', 'listName']);
                $pmt = $bbt->removeTags($fldRow['prompt']);
                if (!$pmt) {
                    $pmt = $fldRow['name'];
                }
                if ($ary['answered'] != 1) {
                    $a = $this->trans->codeKey('value_not_answered_paren');
                } else {
                    switch ($fld['type']) {
                        case 'numeric':
                            $a = $ary['answer'];
                            break;
                        case 'check':
                            $itemList = [0];
                            if ((new CsvIntList($ary['answer']))->isValid()) {
                                $itemList = $ary['anser'];
                            }
                            $sql = "SELECT id, name FROM customSelectList\n"
                            . "WHERE id IN($itemList) AND clientID = :cid AND listName = :listName";
                            $params = [':cli' => $this->tenantID, ':listName' => $fldRow['listName']];
                            $a = $this->DB->fetchKeyValueRows($sql, $params);
                            break;
                        case 'select':
                        case 'radio':
                            $a = $cslMdl->selectValue(
                                'name',
                                ['id' => $ary['answer'], 'listName' => $fldRow['listName']]
                            );
                            break;
                        default:
                            $a = $this->trans->codeKey('err_invalidFieldType_paren');
                    }
                }
                $assessment->cufld->answers[$rawid]['pmt'] = $pmt;
                $assessment->cufld->answers[$rawid]['a'] = $a;
            }
            // Component total
            if ($score >= 0) {
                $ttl = ($factor->maxScore < $model['weights']['cufld'])
                    ? $model['weights']['cufld']
                    : $factor->maxScore;
            } else {
                $ttl = (($factor->minScore * -1) < $model['weights']['cufld'])
                    ? ($model['weights']['cufld'] * -1)
                    : $factor->minScore;
            }
            $vars['cufldCompTotal'] = $ttl;
        }

        // Put it all together for the controller to feed the view
        return [
            'assess' => $assess,         // assessment record, less detail
            'assessment' => $assessment, // unserialized assessment detail
            'model' => $model,           // risk model
            'profile' => $profile,       // 3P profile
            'vars' => $vars,             // addition variables to render view
        ];
    }

    /**
     * Check a multidimensional array to ensure all the expected array keys exist. Can't use array_keys_exists() on a
     * sub-key directly as it does not work on multidimensional arrays, and isset() will return false if the array key
     * does exist but the array value is null, which could be a valid setting.
     *
     * @param array  $multiArray Multidimensional array to test
     * @param string $key        Parent array index to test
     * @param string $subKey     Child array index to test
     * @param string $arrayType  Type of array to check
     *
     * @return boolean True if array key exists, false if not found
     */
    private function missingArrayKeys($multiArray, $key, $subKey, $arrayType)
    {
        switch ($arrayType) {
            case 'ddq':
                if (array_key_exists($key, $multiArray)) {
                    $subArray = $multiArray[$key];
                    if (isset($subArray[$subKey])) {
                        return false;
                    } else {
                        return $subKey;
                    }
                } else {
                    return $key;
                }
                break;
            case 'cufld':
                if (array_key_exists($key, $multiArray->fields)) {
                    $subArray = $multiArray->fields[$key]['scores'];
                    if (array_key_exists($subKey, $subArray)) {
                        return false;
                    } else {
                        return $subKey;
                    }
                } else {
                    return $key;
                }
                break;
        }
    }
}
