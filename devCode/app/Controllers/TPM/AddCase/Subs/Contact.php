<?php
/**
 * Add Case Dialog Contact
 *
 * @category AddCase_Dialog
 * @package  Controllers\TPM\AddCase
 * @keywords SEC-873 & SEC-2844
 */

namespace Controllers\TPM\AddCase\Subs;

use Models\TPM\CaseMgt\CaseFolder\SubInfoAttach;

/**
 * Class Contact
 */
#[\AllowDynamicProperties]
class Contact extends AddCaseBase
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
        $this->callback = 'appNS.addCase.handler';
        $this->template = 'Contact.tpl';

        parent::__construct($tenantID, $caseID);
    }


    /**
     * Returns the translated dialog title
     *
     * @return string
     */
    public function getTitle()
    {
        return str_replace(
            '{caseName}',
            $this->CasesRecord['caseName'],
            (string) $this->app->trans->codeKey('due_diligence_key_info_form')
        );
    }


    /**
     *  Returns view values for Entry.tpl
     *
     * @return array
     */
    public function getViewValues()
    {
        $viewValues = [
            'pageName'                      => 'Contact',
            'sitePath'                      => $this->app->sitePath,
            '_attachmentsList'              => (new SubInfoAttach($this->tenantID))->getAttachments($this->caseID),
            'value_rb_bAwareInvestigation'  => $this->SubInfoRecord['bAwareInvestigation'],
            'value_tf_pointOfContact'       => $this->SubInfoRecord['pointOfContact'],
            'value_tf_POCposition'          => $this->SubInfoRecord['POCposition'],
            'value_tf_phone'                => $this->SubInfoRecord['phone'],
            'value_rb_bInfoQuestnrAttach'   => $this->SubInfoRecord['bInfoQuestnrAttach'],
            'value_ta_addInfo'              => $this->SubInfoRecord['addInfo'],
        ];

        return array_merge($viewValues, $this->app->trans->group('add_case_dialog'));
    }


    /**
     * Validates the Contact form.
     *
     * @return array of errors, empty on successful validation
     */
    #[\Override]
    public function validate()
    {
        $this->setUserInput([
            'rb_bAwareInvestigation',
            'tf_pointOfContact',
            'tf_POCposition',
            'tf_phone',
            'rb_bInfoQuestnrAttach',
            'ta_addInfo',
        ]);

        return (in_array($this->inputs['rb_bAwareInvestigation'], ['yes', 'no']))
            ?   []
            :   [$this->app->trans->codeKey('invalid_rb_bAwareInvestigation')];
    }


    /**
     * Stores contact information
     *
     * @dev: Legacy bAwareInvestigation stored NULL, '0' or '1'. Glenn wanted it changed to a boolean value.
     *
     * @return bool
     * @throws Exception
     */
    public function store()
    {
        try {
            $attributes = [
                'bAwareInvestigation'  => ($this->inputs['rb_bAwareInvestigation'] == 'yes') ? 1 : 0,
                'pointOfContact'       => $this->inputs['tf_pointOfContact'],
                'POCposition'          => $this->inputs['tf_POCposition'],
                'phone'                => $this->inputs['tf_phone'],
                'bInfoQuestnrAttach'   => $this->inputs['rb_bInfoQuestnrAttach'],
                'addInfo'              => $this->inputs['ta_addInfo'],
            ];

            if (!$this->SubInfoObject->setAttributes($attributes)) {
                throw new \Exception('ERROR: '.$this->SubInfoObject->getErrors());
            } elseif (!$this->SubInfoObject->save()) {
                throw new \Exception('ERROR: '.$this->SubInfoObject->getErrors());
            }
            return true;
        } catch (\Exception $e) {
            $this->app->log->debug($e->getMessage());
        }
    }
}
