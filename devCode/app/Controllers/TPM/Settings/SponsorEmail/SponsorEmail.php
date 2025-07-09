<?php
/**
 * Controller for client-facing Sponsor Email mapping tool
 * @see TPM-7461 and TPM-8957
 *
 * WARNING: Column length limitations for g_ddqSponsorEmailConfig.mapName, g_ddqSponsorEmailConfig.fromColumn,
 *          and g_ddqSponsorEmailChain.chainName are currently 100, 150, and 100, respectively. These column length
 *          are sufficient for the implemented naming convention, but they are dependent on column lengths of
 *          underlying components used in these values.
 *   Dependent column lengths
 *     customSelectList.listName     - currently 20 characters
 *     clientProfile.regionTitle     - currently 15 characters
 *     clientProfile.departmentTitle - currently 30 characters
 *   Increasing the lengths of these dependent fields could exceed space allowed for mapName, fromColumn, and chainName.
 *     If the maximum for any of these values is exceeded attempting to save a new map will return an error until the
 *     schema limitations are addressed.
 *
 * WARNING: Changing the name of a customSelectList.listName will break any mapping based on the same listName.
 */

namespace Controllers\TPM\Settings\SponsorEmail;

use Exception;
use Models\TPM\Settings\SponsorEmail\SponsorEmail as Data;
use Lib\Traits\AjaxDispatcher;
use Models\TPM\Settings\Architecture\ArchitectureData;
use Models\MapListToList;
use Lib\Support\Xtra;
use Skinny\Skinny;

#[\AllowDynamicProperties]
class SponsorEmail
{
    use AjaxDispatcher;

    /**
     * @const g_ddqSponsorEmailMapConfig.mapName length
     */
    protected const MAX_MAP_NAME = 100;

    /**
     * @const g_ddqSponsorEmailMapConfig.fromColumn length
     */
    protected const MAX_FROM_COLUMN = 150;

    /**
     * @const g_ddqSponsorEmailMapChain.chainName length
     */
    protected const MAX_CHAIN_NAME = 100;

    /**
     * @var Skinny Class instance, required by AjaxDispatcher
     */
    protected Skinny $app;

    /**
     * @var Data Data access class for SponsorEmail sub-tab
     */
    protected Data $dataClass;

    /**
     * @var string What the client calls region
     */
    protected string $regionLabel;

    /**
     * @var string What the client calls department
     */
    protected string $departmentLabel;

    /**
     * Instantiate class and initialize properties
     *
     * @param int $tenantID TPM tenant ID
     *
     * @throws Exception
     */
    public function __construct(protected int $tenantID)
    {
        $this->app = Xtra::app();
        $this->dataClass = new Data($this->tenantID);
        [$regionLabel, $departmentLabel] = (new ArchitectureData())->regionDeptLabels($this->tenantID);
        $this->regionLabel = $regionLabel;
        $this->departmentLabel = $departmentLabel;
    }

    /**
     * Initialize the sub-tab content
     *
     * @return string
     */
    public function initialize(): string
    {
        $sitePath = substr((string) $_ENV['sitePath'], 0, -5);
        $html = file_get_contents(__DIR__ . '/../../../../Views/TPM/Settings/SponsorEmail/Base.html');
        $search = ['{{sitePath}}', '{{regionLabel}}', '{{departmentLabel}}'];
        $replace = [$sitePath, $this->regionLabel, $this->departmentLabel];
        return str_replace($search, $replace, $html);
    }

    /**
     * Get regions and departments one time for tool. Includes 'active' column so lists can be filtered, as needed
     *
     * @return void
     */
    private function ajaxGetArchitecture(): void
    {
        $error = '';
        $architecture = $this->dataClass->getArchitecture($error);
        if ($error) {
            $this->jsObj->ErrTitle = 'Unexpected Error';
            $this->jsObj->ErrMsg = 'FAILED to get records from ' . $error;
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = $architecture; // [regions, departments]
        }
    }

    /**
     * Get data for map chains. Also get data for customSelectList items
     *
     * @return void
     */
    private function ajaxMapChains(): void
    {
        // Get chain headings and rows
        $data = $this->dataClass->getMapChains();
        $data['what'] = 'showMaps';
        // Use formatted rows to get custom select list data
        $listNames = [];
        $chainLists = [];
        array_map(function ($row) use (&$chainLists, &$listNames) {
            $chainID = $row[0];
            $listName = $row[2];
            $chainLists['chain' . $chainID] = $listName;
            if (!in_array($listName, $listNames)) {
                $listNames[] = $listName;
            }
        }, $data['rows']);
        $listItems =  $this->dataClass->getCustomListData($listNames);

        // Return chain data and custom list data as a single object
        $data['listData'] = [];
        foreach ($chainLists as $chainRef => $listName) {
            $data['listData'][$chainRef] = $listName;
        }
        foreach ($listItems as $listName => $items) {
            $data['listData'][$listName] = $items;
        }
        $this->jsObj->Result = 1;   // won't reach React front-end without this
        $this->jsObj->Args = $data; // should only send the query result to callback, but whole jsObj ends in React
    }

    /**
     * Get map chain details from specified mapping
     *
     * @return void
     */
    private function ajaxGetChainDetails(): void
    {
        $chainID = (int) $this->getPostVar('chainID', 0);
        $data = $this->dataClass->getChainDetails($chainID);
        $this->jsObj->Result = 1;   // won't reach React front-end without this
        $this->jsObj->Args = $data; // should only send the query result to callback, but whole jsObj ends in React
    }

    /**
     * Get list of mapped intake forms
     *
     * @return array
     */
    private function ajaxMappedForms(): void
    {
        $data = $this->dataClass->getMappedForms();
        $data['what'] = 'showMappedForms';
        $this->jsObj->Result = 1;   // won't reach React front-end without this
        $this->jsObj->Args = $data; // should only send the query result to callback, but whole jsObj ends in React
    }

    /**
     * Get list of unmapped intake forms
     *
     * @return array
     */
    private function ajaxUnmappedForms(): void
    {
        $data = $this->dataClass->getUnMappedForms();
        $data['what'] = 'showUnmappedForms';
        $this->jsObj->Result = 1;   // won't reach React front-end without this
        $this->jsObj->Args = $data; // should only send the query result to callback, but whole jsObj ends in React
    }

    /**
     * Get a list of active customSelectList names
     *
     * @return void
     */
    private function ajaxGetActiveListNames(): void
    {
        $error = '';
        $listNames = $this->dataClass->getActiveCustomSelectListNames($error);
        if ($error) {
            $this->jsObj->ErrTitle = 'An Unexpected Error Occurred';
            $this->jsObj->ErrMsg = $error;
        } else {
            $this->jsObj->Result = 1;
        }
        $this->jsObj->Args = [$listNames];
    }

    /**
     * Simulate and answer from a ddq and report the result of mapping it to a sponsor email
     *
     * @return void
     */
    private function ajaxSimulateMappingResult()
    {
        $itemID = (int)$this->getPostVar('item', 0);
        $chainID = (int)$this->getPostVar('chain', 0);
        $rawResult = (new MapListToList())->simulateMappedSponsorEmail($itemID, $chainID);
        $cleanResult = [];
        $keep = ['Item ID', 'Dept ID', 'Region ID', 'Sponsor Email'];
        foreach ($rawResult as $field => $value) {
            if (!in_array($field, $keep)) {
                continue;
            }
            $cleanResult[] = ['field' => $field, 'value' => $value];
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Args = $cleanResult;
    }

    /**
     * Get a list of eligible mappings to apply to an unmapped form
     *
     * @return void
     */
    private function ajaxGetMappingsForUnmappedForm(): void
    {
        $ddqNameID = (int)$this->getPostVar('nameID', 0);
        $listName = $this->getPostVar('list', '');
        $options = $this->dataClass->getMappingsForList($listName);
        array_unshift($options, ['id' => 0, 'chainName' => 'Choose a mapping to assign']);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [
            'ddqNameID' => $ddqNameID,
            'recordID'  => $ddqNameID,
            'options' => $options,
            'selected' => 0,
            'list' => $listName,
            'for' => 'unmapped',
        ];
    }

    /**
     * Get a list of eligible mappings to apply to a mapped form
     *
     * @return void
     */
    private function ajaxGetMappingsForMappedForm(): void
    {
        $mapFormID = (int)$this->getPostVar('formID', 0);
        $listName = $this->getPostVar('list', '');
        $options = $this->dataClass->getMappingsForList($listName);
        array_unshift($options, ['id' => 0, 'chainName' => 'Remove mapping from this form']);
        $this->jsObj->Result = 1;
        $this->jsObj->Args = [
            'mapFormID' => $mapFormID,
            'recordID' => $mapFormID,
            'options' => $options,
            'selected' => $this->dataClass->getMappedFormChainID($mapFormID),
            'list' => $listName,
            'for' => 'mapped',
        ];
    }

    /**
     * Assign new mapping chosen by user to a form (mapped or unmapped)
     *
     * @return void
     */
    private function ajaxAssignNewMapping(): void
    {
        $meta = $this->getPostVar('meta', []);
        $mappingChoice = (int)$this->getPostVar('choice', 0);
        $error = '';
        if ($meta['for'] === 'unmapped') {
            $this->assignFormMapping($mappingChoice, $meta, $error);
        } elseif ($meta['for'] === 'mapped') {
            $this->reassignFormMapping($mappingChoice, $meta, $error);
        } else {
            // unrecognized operation
            $error = "Failed: unrecognized operation.";
        }
        $outcome = 'success';
        $target = $meta['for'];
        if ($error) {
            $this->jsObj->AppNotice = [$error, ['template' => 'error']];
            $outcome = 'failure';
        } else {
            $this->jsObj->AppNotice = ['Form was updated'];
        }
        $returnValues = [
            'outcome' => $error ? 'failure' : 'success',
            'target' => $target,
        ];
        $this->jsObj->Result = 1;
        $this->jsObj->Args = compact('target', 'outcome', 'error');
    }

    /**
     * Assign selected mapping to an unmapped form
     *
     * @param int    $chainID Selected g_ddqSponsorEmailMapChain.id
     * @param array  $meta    Meta data to identify the form in ddqName
     * @param string $error   (reference) communicate any error back to caller
     *
     * @return void
     */
    private function assignFormMapping(int $chainID, array $meta, string &$error = '')
    {
        $this->dataClass->assignMappingToUnmappedForm($meta['ddqNameID'], $chainID, $meta['list'], $error);
    }

    /**
     * Assign new mapping to or remove mapping from a mapped form
     *
     * @param int    $chainID Selected g_ddqSponsorEmailMapChain.id
     * @param array  $meta    Meta data to identify the form in ddqName
     * @param string $error   (reference) communicate any error back to caller
     *
     * @return void
     */
    private function reassignFormMapping(int $chainID, array $meta, string &$error = '')
    {
        $this->dataClass->reassignMappingToMappedForm($meta['mapFormID'], $chainID, $error);
    }

    /**
     * Validate and update sponsor email addresses for specified mapping
     *
     * @return void
     */
    private function ajaxSaveEmailChanges(): void
    {
        $mapChainID = (int)$this->getPostVar('chainID', 0);
        $emailsToUpdate = $this->getPostVar('emails', []);
        $error = '';
        $errorTitle = '';
        $badEmails = [];
        $whichEmails = '';
        $mappingType = '';
        $isDirect = false;
        if ($mapping = $this->dataClass->getMapChain($mapChainID)) {
            if (!empty($mapping['chainToConfigID'])) {
                // Indirect mapping
                if ($mapping['mapToColumn'] === 'department.id') {
                    $whichEmails = $this->regionLabel;
                    $mappingType = 'region';
                } else {
                    $whichEmails = $this->departmentLabel;
                    $mappingType = 'department';
                }
            } else {
                // Direct mapping
                $isDirect = true;
                if ($mapping['mapToColumn'] === 'department.id') {
                    $whichEmails = $this->departmentLabel;
                    $mappingType = 'department';
                } else {
                    $whichEmails = $this->regionLabel;
                    $mappingType = 'region';
                }
            }
        }

        // Must validate emails and email lists
        if (empty($mapping)) {
            $error = 'Invalid mapping ID.';
            $errorTitle = 'Unable to Update Email(s)';
        } elseif (empty($emailsToUpdate) || !is_array($emailsToUpdate)) {
            $error = 'No valid request to update email(s).';
            $errorTitle = 'Unable to Update Email(s)';
        } else {
            // Validate emails
            foreach ($emailsToUpdate as $request) {
                $requestEmail = trim((string) $request['email'], "\n ,");
                $fullElement = ['recID' => $request['recID'], 'email' => $requestEmail];
                if (str_contains($requestEmail, ',')) {
                    $fromList = explode(',', $requestEmail);
                    $allGood = true;
                    foreach ($fromList as $candidate) {
                        $candidate = trim($candidate);
                        $element = ['recID' => $request['recID'], 'email' => $candidate];
                        if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                            $badEmails[] = $element;
                            $allGood = false;
                        }
                    }
                } else {
                    if (!filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
                        $badEmails[] = $fullElement;
                    }
                }
            }
            if (!empty($badEmails)) {
                // prepare for error dialog
                $errorTitle = 'Invalid Email Address(es)';
                // This unordered list is not for a React component, but for a jquery message box using innerHTML
                $error = "<ul>";
                foreach ($badEmails as $element) {
                    if ($isDirect) {
                        $error .= "<li>For $whichEmails : {$element['email']}</li>\n";
                    } else {
                        $error .= "<li>For $whichEmails #{$element['recID']}: {$element['email']}</li>\n";
                    }
                }
                $error .= "</ul>";
            }
        }

        // Are we updating region emails or dept emails
        if (empty($error)) {
            $updates = $this->dataClass->updateEmailAddresses($mapping, $emailsToUpdate, $mappingType, $whichEmails);
            if (!empty($updates['error'])) {
                $errorTitle = 'An Error Occurred';
            }
        }

        $returnValues = [
            'chainID' => $mapChainID,
            'outcome' => $error ? 'failure' : 'success',
            'errorTitle' => $errorTitle,
            'errorMsg' => $error,
        ];
        if (empty($error)) {
            if (!empty($updates['updated'])) {
                $successMessage = 'Updates were successful.';
            } else {
                $successMessage = 'Found nothing to update.';
            }
            $this->jsObj->AppNotice = [$successMessage];
        }
        $this->jsObj->Result = 1;
        $this->jsObj->Args = $returnValues;
    }

    /**
     * Get customSelectList items
     *
     * @return void
     */
    private function ajaxGetCustomSelectListItems(): void
    {
        $listName = $this->getPostVar('listName', '');
        $includeInactive = (bool)$this->getPostVar('includeInactive', false);
        $error = '';
        $listRows = $this->dataClass->getCustomSelectListItems($listName, $includeInactive, $error);
        if ($error) {
            $this->jsObj->ErrTitle = "An Error Occurred";
            $this->jsObj->ErrMsg = $error;
        } else {
            $this->jsObj->Result = 1;
            $this->jsObj->Args = [$listRows];
        }
    }

    /**
     * Save new Direct Mapping - List -> Region or Department -> Email
     *
     * @return void
     */
    private function ajaxSaveNewDirectMapping(): void
    {
        $error = '';
        $errorTitle = 'Invalid Configuration';
        $adjustErrorWidth = true;

        // list name
        $listName = $this->getPostVar('listName', '');
        // list type
        $listType = $this->getPostVar('listType', '');
        // list assignments
        $listAssignments = $this->getPostVar('listAssignments', []);

        // Sanity test on inputs - these conditions shouldn't happen
        if (empty($listName)) {
            $error = 'Missing Custom List name.';
        } elseif (!in_array($listType, ['listIsDept', 'listIsRegion'])) {
            $error = 'Missing or invalid Custom List type.';
        } elseif (empty($listAssignments)) {
            $error = "Missing assignments to Custom List `$listName`.";
        }

        // Construct and test names
        $mapName = $chainName = '';
        $fromColumn = $toColumn = '';
        if (empty($error)) {
            $errorTitle = 'Unexpected Error';
            $dateTail = ' (' . date('Y-m-d') . ')';
            if ($listType === 'listIsDept') {
                $architectureName = $this->departmentLabel;
                $toColumn = 'department.id';
            } else {
                $architectureName = $this->regionLabel;
                $toColumn = 'region.id';
            }
            $fromColumn = 'customSelectList:' . $listName;
            if (mb_strlen($fromColumn, 'UTF-8') > self::MAX_FROM_COLUMN) {
                $error = "Source field, $fromColumn, exceeds expected length. This condition is most likely caused by "
                    . "an increase in the allowable length for a Custom Select List entry in Fields &amp; Lists. "
                    . "Please notify Support about this error.";
            }
            // No need to test $toColumn

            // Prepare error template for too-long name
            $tooLongName = '{{name}} exceeds expected length. This condition is most likely caused by an increase '
                . 'in the allowable length for a Custom Select List entry in Fields &amp; Lists or for maximum '
                . 'length of customizable names for Department and Region. Please notify Support about this error.';

            // Config.mapName
            if (empty($error)) {
                $listConfigStub = 'Custom List ' . $listName . ' to ' . $architectureName . $dateTail;
                $mapName = $this->dataClass->getUniqueName(
                    $listConfigStub,
                    'mapName',
                    'g_ddqSponsorEmailMapConfig',
                    $error
                );
                if (empty($error) && mb_strlen($mapName, 'UTF-8') > self::MAX_MAP_NAME) {
                    $error = str_replace('{{name}}', "Map name, $mapName,", $tooLongName);
                }
            }

            // Chain.chainName
            if (empty($error)) {
                $chainStub = $architectureName . ' Sponsor from List ' . $listName . $dateTail;
                $chainName = $this->dataClass->getUniqueName(
                    $chainStub,
                    'chainName',
                    'g_ddqSponsorEmailMapChain',
                    $error
                );
                if (empty($error) && mb_strlen($chainName, 'UTF-8') > self::MAX_CHAIN_NAME) {
                    $error = str_replace('{{name}}', "Chain name, $chainName,", $tooLongName);
                }
            }
        }

        // Validate emails
        if (empty($error)) {
            if ($error = $this->validateEmails($listAssignments, 'itemName', 'email')) {
                $adjustErrorWidth = false;
                $errorTitle = 'Invalid Email Address(es)';
            }
        }

        // Insert records
        if (empty($error)) {
            $errorTitle = 'Unexpected Error';
            $this->dataClass->saveDirectMapping(
                $listAssignments,
                $mapName,
                $fromColumn,
                $toColumn,
                $chainName,
                $error
            );
        }

        if (empty($error)) {
            $this->jsObj->Result = 1;
            $this->jsObj->AppNotice = ['Added New Direct Mapping'];
        } else {
            $this->jsObj->ErrTitle = $errorTitle;
            $this->jsObj->ErrMsg = $error;
            if ($adjustErrorWidth) {
                $this->jsObj->ErrMsgWidth = strlen($error) > 150 ? 600 : null;
            }
        }
    }

    /**
     * Save new Indirect Mapping - List -> Region or Department -> Department or Region -> Email
     *
     * @return void
     */
    private function ajaxSaveNewIndirectMapping(): void
    {
        $error = '';
        $errorTitle = 'Invalid Configuration';
        $adjustErrorWidth = true;

        // list name
        $listName = $this->getPostVar('listName', '');
        // list type
        $listType = $this->getPostVar('listType', '');
        // list assignments
        $listAssignments = $this->getPostVar('listAssignments', []);
        // email assignments
        $emailAssignments = $this->getPostVar('emailAssignments', []);

        // Sanity test on inputs - these conditions shouldn't happen
        if (empty($listName)) {
            $error = 'Missing Custom List name.';
        } elseif (!in_array($listType, ['listIsDept', 'listIsRegion'])) {
            $error = 'Missing or invalid Custom List type.';
        } elseif (empty($listAssignments)) {
            $error = "Missing assignments to Custom List `$listName`.";
        } elseif (empty($emailAssignments)) {
            $error = "Missing email assignments.";
        }

        // Construct and test names
        $mapName = $chainToMapName = $chainName = '';
        $fromColumn = $fromColumn2 = $toColumn = $toColumn2 = '';
        if (empty($error)) {
            $errorTitle = 'Unexpected Error';
            $dateTail = ' (' . date('Y-m-d') . ')';
            if ($listType === 'listIsDept') {
                $architectureName = $this->departmentLabel;
                $architectureOther = $this->regionLabel;
                $toColumn = 'department.id';
                $toColumn2 = 'region.id';
            } else {
                $architectureName = $this->regionLabel;
                $architectureOther = $this->departmentLabel;
                $toColumn = 'region.id';
                $toColumn2 = 'department.id';
            }
            $fromColumn = 'customSelectList:' . $listName;
            $fromColumn2 = $toColumn;
            if (mb_strlen($fromColumn, 'UTF-8') > self::MAX_FROM_COLUMN) {
                $error = "Source field, $fromColumn, exceeds expected length. This condition is most likely caused by "
                    . "an increase in the allowable length for a Custom Select List entry in Fields &amp; Lists. "
                    . "Please notify Support about this error.";
            }
            // No need to test $fromColumn2, $toColumn, $toColumn2, $fromColumn2

            // Prepare error template for too-long name
            $tooLongName = '{{name}} exceeds expected length. This condition is most likely caused by an increase '
                . 'in the allowable length for a Custom Select List entry in Fields &amp; Lists or for maximum '
                . 'length of customizable names for Department and Region. Please notify Support about this error.';

            if (empty($error)) {
                $listConfigStub = 'Custom List ' . $listName . ' to ' . $architectureName . $dateTail;
                $mapName = $this->dataClass->getUniqueName(
                    $listConfigStub,
                    'mapName',
                    'g_ddqSponsorEmailMapConfig',
                    $error
                );
                if (empty($error) && mb_strlen($mapName, 'UTF-8') > self::MAX_MAP_NAME) {
                    $error = str_replace('{{name}}', "Map name, $mapName,", $tooLongName);
                }
            }

            if (empty($error)) {
                $chainToConfigStub = $architectureName . ' to ' . $architectureOther . $dateTail;
                $chainToMapName = $this->dataClass->getUniqueName(
                    $chainToConfigStub,
                    'mapName',
                    'g_ddqSponsorEmailMapConfig',
                    $error
                );
                if (empty($error) && mb_strlen($chainToMapName, 'UTF-8') > self::MAX_MAP_NAME) {
                    $error = str_replace('{{name}}', "Map name, $chainToMapName,", $tooLongName);
                }
            }

            // Chain.chainName
            if (empty($error)) {
                $chainStub = $architectureOther . ' Sponsor from List ' . $listName . $dateTail;
                $chainName = $this->dataClass->getUniqueName(
                    $chainStub,
                    'chainName',
                    'g_ddqSponsorEmailMapChain',
                    $error
                );
                if (empty($error) && mb_strlen($chainName, 'UTF-8') > self::MAX_CHAIN_NAME) {
                    $error = str_replace('{{name}}', "Chain name, $chainName,", $tooLongName);
                }
            }
        }

        // Validate emails
        if (empty($error)) {
            if ($error = $this->validateEmails($emailAssignments, 'name', 'email')) {
                $adjustErrorWidth = false;
                $errorTitle = 'Invalid Email Address(es)';
            }
        }

        // Insert records
        if (empty($error)) {
            $errorTitle = 'Unexpected Error';
            $this->dataClass->saveIndirectMapping(
                $listAssignments,
                $emailAssignments,
                $mapName,
                $fromColumn,
                $toColumn,
                $chainToMapName,
                $fromColumn2,
                $toColumn2,
                $chainName,
                $error
            );
        }

        if (empty($error)) {
            $this->jsObj->Result = 1;
            $this->jsObj->AppNotice = ['Added New Indirect Mapping'];
        } else {
            $this->jsObj->ErrTitle = $errorTitle;
            $this->jsObj->ErrMsg = $error;
            if ($adjustErrorWidth) {
                $this->jsObj->ErrMsgWidth = strlen($error) > 150 ? 600 : null;
            }
        }
    }

    /**
     * Detect any invalid emails and return formatted error for app error dialog
     *
     * @param array  $source           Source records to check
     * @param string $itemNameProperty Property holding Item Name value
     * @param string $emailProperty    Property holding email value
     *
     * @return string
     */
    private function validateEmails(array $source, string $itemNameProperty, string $emailProperty): string
    {
        $badEmails = [];
        foreach ($source as $request) {
            $requestEmail = trim($request[$emailProperty], "\n ,");
            if (strpos($requestEmail, ',') !== false) {
                $addresses = explode(',', $requestEmail);
                foreach ($addresses as $candidate) {
                    $candidate = trim($candidate);
                    if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                        $badEmails[] = [$request[$itemNameProperty], $candidate];
                    }
                }
            } else {
                if (!filter_var($requestEmail, FILTER_VALIDATE_EMAIL)) {
                    $badEmails[] = [$request[$itemNameProperty], $requestEmail];
                }
            }
        }
        if (count($badEmails)) {
            // This unordered list is not for a React component, but for a jquery message box using innerHTML
            $error = "<ul>";
            foreach ($badEmails as $bad) {
                list($item, $email) = $bad;
                $error .= "<li>For $item: $email</li>\n";
            }
            $error .= "</ul>";
        } else {
            $error = '';
        }
        return $error;
    }
}
