<?php
/**
 * Provides read/write access to tpRelate table and validation of inputs for insert and update
 *
 * Primary relationship: tpID is parent, child is another 3P Profile or 3P Engagement
 * Secondary relationship: tpID is child, parent is another 3P profile or 3P Engagement
 *
 * Update can modify primary or secondary record, but tpID cannot change from parent to child or from child to parent.
 * Insert can add primary relationships only.
 */

namespace Models\TPM\TpProfile;

use Models\BaseLite\BasicCRUD;
use Lib\Support\Xtra;
use Lib\Traits\DetailedUpdate;
use Models\LogData;
use Exception;

/**
 * Read/write access to tpRelate. This table does not have a clientID column.
 *
 * @keywords 3p type
 */
#[\AllowDynamicProperties]
class TpRelate extends BasicCRUD
{
    use DetailedUpdate;

    /**
     * Table name (required by base class)
     *
     * @var string
     */
    protected $tbl = 'tpRelate';

    /**
     * @var int TPM tenant ID. This table does not have a clientID column, but tpRelate records must be constrained to
     *          a specific client.
     */
    protected $clientID = 0;

    /**
     * @var array Acceptable values for tpRelate.relation
     */
    protected $validRelationships = [
        'affiliate',
        'partner',
        'subsidiary',
    ];

    /**
     * @var string List of accepted relationship values - initialized in constructor
     */
    protected $acceptRelationships = '';

    /**
     * @var int User to which audit logs are attribtued
     */
    protected $loggingUserID = 0;

    /**
     * @var array Map table columns to input names
     */
    protected $fieldMap = [
        'id' => 'id',
        'parent' => 'parent',
        'child' => 'child',
        'relation' => 'relation',
    ];

    /**
     * Instantiate class and set properties
     *
     * @param int   $clientID      TPM tenant ID
     * @param int   $loggingUserID cms.users.id of user for audit log
     * @param array $fieldMap      Override default field map
     * @param array $connection    Alternate connection values for parent class
     *
     * @throws Exception
     */
    public function __construct($clientID = 0, $loggingUserID = 0, $fieldMap = [], $connection = [])
    {
        parent::__construct($connection);
        // BasicCRUD doesn't know about clientID or client database
        $this->clientID = (int)$clientID;
        // Fix parent properties, invalid clientID throws exception
        $this->dbName = $this->DB->getClientDB($this->clientID);
        $this->tbl = "$this->dbName.$this->tbl";
        $tmpArray = [];
        foreach ($this->validRelationships as $value) {
            $tmpArray[] = ucfirst((string) $value);
        }
        $this->acceptRelationships = implode(', ', $tmpArray);
        if (!empty($loggingUserID)) {
            $this->loggingUserID = (int)$loggingUserID;
        }
        if (!empty($fieldMap)) {
            $this->fieldMap = $fieldMap;
        }
    }

    /**
     * Validate insert inputs for one connection. Does not insert records
     *
     * If fieldMap contains 'child' only (no parent) tpID is parent.
     * ### Rules ###
     *   1. tpID exists and is 3P Engagement or 3P Profile
     *   2. If 'parent' is provided it must match tpID
     *   3. Must have 'child' and 'relation'
     *   4. Child record must exist and not be same as tpID
     *   5. Relationship must be one of 'Affiliate', 'Partner', or 'Subsidiary' (default)
     *   6. Cannot collide with an existing relationship
     *
     * @param int   $tpID         thirdPartyProfile.id of record to which relationship belongs
     * @param array $inputs       Must contain 'parent', 'child' and 'relation' keys.
     *                            'parent' or 'child' must match tpID
     * @param bool  $isEngagement True if tpID is 3P Engagement, otherwise tpID is 3P Profile
     *
     * @return array Errors or full validated inputs ('parent', 'child', 'relation') ready for insert.
     */
    public function validateInsertInputs(int $tpID, array $inputs, bool $isEngagement): array
    {
        $result = [
            'validatedInputs' => [],
            'errors' => [
                'missing' => [],
                'invalid' => [],
            ],
        ];
        $profileGetter = new TpProfile($this->clientID);

        // tpID exists and is 3P Profile or 3P Engagement
        $confirmType = $isEngagement ? 1 : 0;
        $ownerProfile  = $profileGetter->getProfileByReference($tpID, ['id', 'userTpNum', 'isEngagement']);
        if (empty($ownerProfile) || $ownerProfile['isEngagement'] !== $confirmType) {
            $tpScope = $isEngagement ? 'Engagement' : 'Profile';
            $this->setError($result, 'invalid', 'reference', "Does not match a 3P $tpScope record");
        }

        // if parent is present must be same as tpID
        $parentRecord = $childRecord = false;
        $relationship = '';
        if (isset($this->fieldMap['parent'])) {
            $parentRecord = $profileGetter->getProfileByReference(
                $inputs[$this->fieldMap['parent']],
                ['id', 'userTpNum']
            );
            if (empty($parentRecord)) {
                $this->setError($result, 'invalid', 'parent', '3P record does not exist');
            } elseif ($parentRecord['id'] !== $tpID) {
                $this->setError($result, 'invalid', 'parent', 'Parent must be same as reference');
            }
        } else {
            $parentRecord = $ownerProfile;
        }

        // must have 'child' and 'relation'
        foreach (['child', 'relation'] as $field) {
            if (!isset($inputs[$this->fieldMap[$field]])) {
                $this->setError($result, 'missing', $field);
                continue;
            }
            switch ($field) {
                case 'child':
                    $childRecord = $profileGetter->getProfileByReference(
                        $inputs[$this->fieldMap[$field]],
                        ['id', 'userTpNum']
                    );
                    if (empty($childRecord)) {
                        $this->setError($result, 'invalid', $field, 'Does not match a 3P record');
                    } elseif ($childRecord['id'] === $tpID) {
                        $this->setError($result, 'invalid', $field, 'Cannot be same as reference');
                    }
                    break;
                default:
                    // 'relation'
                    $relationship = $inputs[$this->fieldMap[$field]];
                    if (!in_array(strtolower((string) $relationship), $this->validRelationships)) {
                        $this->setError(
                            $result,
                            'invalid',
                            'relation',
                            "Must be one of $this->acceptRelationships"
                        );
                    }
                    break;
            }
        }

        // don't allow collision with existing record
        if ($parentRecord && $childRecord) {
            $where = [
                'parent' => $parentRecord['id'],
                'child' => $childRecord['id'],
            ];
            if ($otherRecords = $this->selectMultiple([], $where)) {
                $this->setError($result, 'invalid', 'parent/child', 'Duplicates an existing relationship');
            }
        }

        if (!empty($result['errors']['missing']) || !empty($result['errors']['invalid'])) {
            return $result;
        }

        $result['validatedInputs'] = [
            'parent' => $parentRecord['id'],
            'child' => $childRecord['id'],
            'relation' => $relationship,
        ];
        $result['errors'] = [];

        return $result;
    }

    /**
     * Insert a tpRelate record with validated inputs, log the operation and return the new primary ID.
     * This method does not re-validate inputs.
     *
     * @param int   $tpID   3P Profile or 3P Engagement to which new record relates
     * @param array $inputs Validated inputs (from validateInsertInputs())
     *
     * @return int
     */
    public function insertValidatedRecord(int $tpID, array $inputs): int
    {
        if ($newID = $this->insert($inputs)) {
            // Log insert into tpRelate
            // 49 Add Third Party Relation
            try {
                $profileGetter = new TpProfile($this->clientID);
                $parentNumber = $profileGetter->selectValueByID($inputs['parent'], 'userTpNum');
                $childNumber = $profileGetter->selectValueByID($inputs['child'], 'userTpNum');
                $parts = [
                    "parent: `$parentNumber`",
                    "child: `$childNumber`",
                    "relation: {$inputs['relation']}",
                ];
                $logMessage = "New connection - " . implode('; ', $parts);
                $logger = new LogData($this->clientID, $this->loggingUserID);
                $logger->save3pLogEntry(49, $logMessage, $tpID);
            } catch (Exception $e) {
                // don't keep logging error from returning result
                Xtra::track([
                    'event' => 'Logging insert into tpRelate failed',
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $newID = 0;
        }
        return $newID;
    }

    /**
     * Validate update inputs and performs update if ok. Otherwise, returns error array.
     *
     * Can update primary or secondary relationship, but tpID cannot switch between parent and child.
     * ### Rules ###
     *   1. tpID exists and is 3P Engagement or 3P Profile
     *   2. tpRecordID mus match and existing record
     *   3. parent or child must be same to tpID
     *   4. parent and child must exist
     *   5. Relationship must be one of 'Affiliate', 'Partner', or 'Subsidiary' (default)
     *   6. Cannot collide with an existing relationship
     *
     * @param int   $tpID         3P profile owning the relationship
     * @param int   $tpRelateID   tpRelate.id to be changed
     * @param array $inputs       'parent' and 'child' can be reference (id or userTpNum).
     *                            Either 'parent' or 'child' must match tpID
     * @param bool  $isEngagement If true tpID must be an Engagement
     *
     * @return array result or error(s)
     */
    public function validateUpdate(int $tpID, int $tpRelateID, array $inputs, bool $isEngagement = false): array
    {
        // Return structure
        $result = $this->getDetailedUpdateStructure();
        $profileGetter = new TpProfile($this->clientID);

        // tpID exists and is 3P Profile or 3P Engagement
        $confirmType = $isEngagement ? 1 : 0;
        $ownerProfile  = $profileGetter->getProfileByReference($tpID, ['id', 'userTpNum', 'isEngagement']);
        if (empty($ownerProfile) || $ownerProfile['isEngagement'] !== $confirmType) {
            $tpScope = $isEngagement ? 'Engagement' : 'Profile';
            $this->setError($result, 'invalid', 'tpID', "Does not match a 3P $tpScope record");
        }

        // tpRelateID matches a record and must have parent or child matching tpID
        $parentIsTpID = $childIsTpID = false;
        if ($relateRecord = $this->selectByID($tpRelateID)) {
            if ($relateRecord['parent'] !== $tpID && $relateRecord['child'] !== $tpID) {
                $this->setError($result, 'invalid', 'id', 'Relationship does not belong to this 3P');
            } else {
                $parentIsTpID = $relateRecord['parent'] === $tpID;
                $childIsTpID = $relateRecord['child'] === $tpID;
            }
        } else {
            $this->setError($result, 'invalid', 'id', 'Does not match a relationship record');
        }

        // missing any inputs?
        $parentRecord = $childRecord = $relationship = false;
        foreach (['id', 'parent', 'child', 'relation'] as $field) {
            if (!isset($inputs[$this->fieldMap[$field]])) {
                $message = $field === 'id'
                    ? 'Required for confirmation and must match `id` value'
                    : 'Required input is missing';
                $this->setError($result, 'missing', $field, $message);
            } else {
                switch ($field) {
                    case 'id':
                        if ((int)$inputs[$this->fieldMap[$field]] !== $tpRelateID) {
                            $message = 'Must match `id` value';
                            $this->setError($result, 'invalid', $field, $message);
                        }
                        break;
                    case 'parent':
                        if ($parentIsTpID) {
                            $parentRecord = $ownerProfile;
                        } else {
                            $parentRecord = $profileGetter->getProfileByReference(
                                $inputs[$this->fieldMap[$field]],
                                ['id', 'userTpNum']
                            );
                        }
                        if (empty($parentRecord)) {
                            $this->setError($result, 'invalid', $field, 'Does not match a 3P record');
                        }
                        break;
                    case 'child':
                        if ($childIsTpID) {
                            $childRecord = $ownerProfile;
                        } else {
                            $childRecord = $profileGetter->getProfileByReference(
                                $inputs[$this->fieldMap[$field]],
                                ['id', 'userTpNum']
                            );
                        }
                        if (empty($childRecord)) {
                            $this->setError($result, 'invalid', $field, 'Does not match a 3P record');
                        }
                        break;
                    case 'relation':
                        $relationship = $inputs[$this->fieldMap[$field]];
                        if (!in_array(strtolower((string) $relationship), $this->validRelationships)) {
                            $this->setError(
                                $result,
                                'invalid',
                                'relation',
                                "Must be one of $this->acceptRelationships"
                            );
                        }
                        break;
                }
            }
        }

        // is parent or child input equal to tpID
        if ($parentRecord && $childRecord && $relationship) {
            if ($parentRecord['id'] !== $tpID && $childRecord['id'] !== $tpID) {
                $this->setError($result, 'invalid', 'parent/child', 'One must match tpID');
            } elseif ($parentRecord['id'] === $tpID && $childRecord['id'] === $tpID) {
                $this->setError($result, 'invalid', 'parent/child', '3P record cannot relate to itself');
            } else {
                // don't allow collision with existing record
                $where = [
                    'parent' => $parentRecord['id'],
                    'child'  => $childRecord['id'],
                ];
                $otherRecords = $this->selectMultiple([], $where);
                foreach ($otherRecords as $other) {
                    if ($other['id'] !== $tpRelateID) {
                        $errorMessage = 'Conflicts with another relationship record';
                        $this->setError($result, 'invalid', 'parent/child', $errorMessage);
                        break;
                    }
                }
            }
        }

        // Exit with errors if validation failed
        if (!empty($result['errors']['missing']) || !empty($result['errors']['invalid'])) {
            return $result;
        }

        // Attempt the update
        $setValues = [
            'parent' => $parentRecord['id'],
            'child' => $childRecord['id'],
            'relation' => $relationship,
        ];
        if ($rowCount = $this->updateByID($tpRelateID, $setValues)) {
            $result['updated'] = $rowCount;
            // 213 Update Third Party Relation
            try {
                // Log changes
                $changes = [];
                if ($setValues['parent'] !== $relateRecord['parent']) {
                    $originalValue = $profileGetter->selectValueByID($relateRecord['parent'], 'userTpNum');
                    $newValue = $parentRecord['userTpNum'];
                    $result['affected data'][$this->fieldMap['parent']] = [
                        'original' => $originalValue,
                        'new' => $newValue,
                    ];
                    $changes[] = "parent: `$originalValue` =>`$newValue`";
                }
                if ($setValues['child'] !== $relateRecord['child']) {
                    $originalValue = $profileGetter->selectValueByID($relateRecord['child'], 'userTpNum');
                    $newValue = $childRecord['userTpNum'];
                    $result['affected data'][$this->fieldMap['child']] = [
                        'original' => $originalValue,
                        'new' => $newValue,
                    ];
                    $changes[] = "child: `$originalValue` =>`$newValue`";
                }
                if ($relationship !== $relateRecord['relation']) {
                    $originalValue = $relateRecord['relation'];
                    $newValue = $relationship;
                    $result['affected data'][$this->fieldMap['relation']] = [
                        'original' => $originalValue,
                        'new' => $newValue,
                    ];
                    $changes[] = "relation: `$originalValue` => `$newValue`";
                }
                $logMsg = 'Relationship for ' . $ownerProfile['userTpNum'] . ' - '
                    . implode('; ', $changes);
                (new LogData($this->clientID, $this->loggingUserID))->save3pLogEntry(213, $logMsg, $tpID);
            } catch (Exception $e) {
                // don't keep logging error from returning result
                Xtra::track([
                    'event' => 'Logging  tpRelate failed',
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $result;
    }
}
