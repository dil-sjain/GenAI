<?php
/**
 * Add Case Dialog Principals
 *
 * @category AddCase_Dialog
 * @package  Controllers\TPM\AddCase
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddCase\Subs;

use Lib\Legacy\IntakeFormTypes as IFT;

/**
 * Class Principals
 */
#[\AllowDynamicProperties]
class Principals extends AddCaseBase
{
    /**
     * Entry constructor.
     *
     * @param int $tenantID tenant ID
     * @param int $caseID   cases.id
     *
     * @throws \Exception
     */
    public function __construct($tenantID, $caseID)
    {
        $this->caseID   = (int)$caseID;
        $this->callback = 'appNS.addCase.handler';
        $this->template = 'Principals.tpl';

        parent::__construct($tenantID, $caseID);
    }


    /**
     * Returns the translated dialog title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->app->trans->codeKey('edit_add_principals');
    }


    /**
     *  Returns view values for Entry.tpl
     *
     * @return array
     */
    public function getViewValues()
    {
        return array_merge(
            $this->app->trans->group('add_case_dialog'),
            $this->returnPrincipalsViewValues(),
            [
                'sitePath'              =>  $this->app->sitePath,
                'pageName'              =>  'Principals',
                'label_principalsScope' =>  $this->returnScopeNotice(),
            ]
        );
    }


    /**
     * Returns an array of user input fields
     *
     * @return array of user input fields
     */
    public function getUserInput()
    {
        $inputs = [];

        for ($i = 1; $i < 11; $i++) {
            $inputs[] = "Principal{$i}";
            $inputs[] = "Relationship{$i}";
            $inputs[] = "OwnerPercent{$i}";
            $inputs[] = "IsOwner{$i}";
            $inputs[] = "IsBoardMem{$i}";
            $inputs[] = "IsKeyMgr{$i}";
            $inputs[] = "IsKeyCons{$i}";
            $inputs[] = "IsUnknown{$i}";
        }

        return $inputs;
    }


    /**
     * Validates the Principals form.
     *
     * Get all inputs & validate them.
     *
     * @return array of errors, empty on successful validation
     */
    #[\Override]
    public function validate()
    {
        $validated  = [];
        $error      = [];
        $ownerTotal = 0;

        $this->setUserInput($this->getUserInput());

        for ($i = 1; $i < 11; $i++) {
            $principal    = $this->inputs["Principal{$i}"];
            $relationship = $this->inputs["Relationship{$i}"];
            $ownerPercent = '';
            if ($this->inputs["OwnerPercent{$i}"]) {
                if (is_numeric($this->inputs["OwnerPercent{$i}"])) {
                    $ownerPercent = number_format((float)$this->inputs["OwnerPercent{$i}"], 3);
                    $ownerTotal += $ownerPercent;
                } else {
                    $error[] = str_replace(
                        'Percentage',
                        "Principal $i owner percentage",
                        (string) $this->app->trans->codeKey('percent_misformatted')
                    );
                }
            }
            $isOwner         = filter_var($this->inputs["IsOwner{$i}"], FILTER_VALIDATE_BOOLEAN);
            $isBoardMember   = filter_var($this->inputs["IsBoardMem{$i}"], FILTER_VALIDATE_BOOLEAN);
            $isKeyManager    = filter_var($this->inputs["IsKeyMgr{$i}"], FILTER_VALIDATE_BOOLEAN);
            $isKeyConsultant = filter_var($this->inputs["IsKeyCons{$i}"], FILTER_VALIDATE_BOOLEAN);
            $isUnknown       = filter_var($this->inputs["IsUnknown{$i}"], FILTER_VALIDATE_BOOLEAN);

            if (!empty($principal)
                &&  !($isOwner)
                &&  !($isBoardMember)
                &&  !($isKeyManager)
                &&  !($isKeyConsultant)
                &&  !($isUnknown)) {
                $error[] = str_replace('{number}', $i, (string) $this->app->trans->codeKey('error_principal_associated_role'));
            }
            if ($isOwner && (empty($ownerPercent) || $ownerPercent <= 0 || $ownerPercent > 100)) {
                $error[] = str_replace('{number}', $i, (string) $this->app->trans->codeKey('error_principal_owner_percent'));
            }
            if ($ownerPercent && !$isOwner) {
                $error[] = str_replace('{number}', $i, (string) $this->app->trans->codeKey('error_owner_percent_no_role'));
            }

            $validated = array_merge($validated, [
                // If no principal then reset everything.
                "principal{$i}"     =>  $principal,
                "pRelationship{$i}" =>  !empty($principal) ? $relationship : '',
                "bp{$i}Owner"       =>  (!empty($principal) && $isOwner) ? 1 : 0,
                "p{$i}OwnPercent"   =>  !empty($principal) ? $ownerPercent : '',
                "bp{$i}BoardMem"    =>  (!empty($principal) && $isBoardMember) ? 1 : 0,
                "bp{$i}KeyMgr"      =>  (!empty($principal) && $isKeyManager) ? 1 : 0,
                "bp{$i}KeyConsult"  =>  (!empty($principal) && $isKeyConsultant) ? 1 : 0,
                "bp{$i}Unknown"     =>  (!empty($principal) && $isUnknown) ? 1 : 0,
            ]);
        }

        if ($ownerTotal > 100) {
            $error[] = $this->app->trans->codeKey('percent_sum_exceeds_100');
        }

        //  If there were no errors, map data to db.table.column for store()
        $this->inputs = empty($error) ? $validated : [];

        return $error;
    }


    /**
     * Stores user input into subInfoAttach
     *
     * @return int
     * @throws \Exception
     */
    public function store()
    {
        try {
            if (!$this->SubInfoObject->setAttributes($this->inputs)) {
                throw new \Exception('ERROR: '.$this->SubInfoObject->getErrors());
            } elseif (!$this->SubInfoObject->save()) {
                throw new \Exception('ERROR: '.$this->SubInfoObject->getErrors());
            }
        } catch (\Exception $e) {
            $this->app->log->debug($e->getFile().' : '.$e->getLine().' : '.$e->getMessage());
        }

        return $this->caseID;
    }


    /**
     * Returns the Principals view values
     *
     * @return array view value keys matched to subjectInfoDD record data
     */
    private function returnPrincipalsViewValues()
    {
        $principalValues = ['maxPrincipal' => 4];

        for ($i = 1; $i <= 10; $i++) {
            $principalValues["value_Principal{$i}"]    = $this->SubInfoRecord["principal{$i}"];     // Principal
            $principalValues["value_Relationship{$i}"] = $this->SubInfoRecord["pRelationship{$i}"]; // Relationship
            $principalValues["value_IsOwner{$i}"]      = $this->SubInfoRecord["bp{$i}Owner"];       // Owner [checkbox]
            $principalValues["value_OwnerPercent{$i}"] = $this->SubInfoRecord["p{$i}OwnPercent"];   // Owner [%]
            $principalValues["value_IsBoardMem{$i}"]   = $this->SubInfoRecord["bp{$i}BoardMem"];    // Board Member
            $principalValues["value_IsKeyMgr{$i}"]     = $this->SubInfoRecord["bp{$i}KeyMgr"];      // Key Manager
            $principalValues["value_IsKeyCons{$i}"]    = $this->SubInfoRecord["bp{$i}KeyConsult"];  // Key Consultant
            $principalValues["value_IsUnknown{$i}"]    = $this->SubInfoRecord["bp{$i}Unknown"];     // Unknown

            if ($i > 4 && !empty($principalValues["value_Principal{$i}"])) {
                $principalValues['maxPrincipal'] = ($i < 8) ? 7 : 10;
            }
        }

        return $principalValues;
    }


    /**
     * Returns text to notify of possible costs for the principals depending on the case type.
     *
     * Add the text translations code keys
     *
     * @return string
     */
    private function returnScopeNotice()
    {
        $caseTypeScopeText = match ((int) $this->CasesRecord['caseType']) {
            IFT::DUE_DILIGENCE_SBI, IFT::DUE_DILIGENCE_EDDPDF => $this->app->trans->codeKey('scope_sbi_eddpdf_notice'),
            IFT::DUE_DILIGENCE_IBI => $this->app->trans->codeKey('scope_ibi_notice'),
            default => '',
        };

        return $caseTypeScopeText;
    }
}
