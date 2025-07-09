<?php
/**
 * Model: Intake Forms - Invite Menu
 *
 * @keywords intake forms, intake invite, invite menu
 */
namespace Models\TPM\IntakeForms;

use Models\ThirdPartyManagement\Cases;

/**
 * Class IntakeMenuData
 *
 * Configure invitation menu
 */
#[\AllowDynamicProperties]
class InviteMenuData
{
    /**
     * the app instance loaded in via Xtra::app
     * @var object Skinny instance
     */
    protected $app = null;

    /**
     * Construct and manage invitation control menu
     * Expects $inviteControl to be instance of InvitationControl
     *
     * @param integer $clientID clientProfile.id
     *
     * @return void
     */
    public function __construct(protected $clientID)
    {
        $this->app = \Xtra::app();
    }

    /**
     * Get Invite Menu table rows
     *
     * @param array $inviteControl lists and maps of invitation options
     *
     * @return array table data
     */
    public function getRows($inviteControl)
    {
        $icMenuOpts = [];
        if ($inviteControl['perspective'] == 'anonymouse') {
            foreach ($inviteControl['actionFormList'] as $icFormType => $icForm) {
                if ($icForm['renewalOf'] == 0) {
                    $icMenuOpts[] = $icFormType;
                }
            }
        } else {
            foreach ($inviteControl['formsByClassAction'] as $icFormClass => $icActions) {
                foreach ($icActions as $icFormAction => $icForms) {
                    foreach ($icForms as $icFormType) {
                        $icMenuOpts[] = $icFormType;
                    }
                }
            }
        }
        $rows = [];
        $rowcnt = 0;
        foreach ($icMenuOpts as $icFormType) {
            $row = [];
            $icForm = $inviteControl['actionFormList'][$icFormType];
            $row['icFormAction'] = $icForm['action'];
            $row['icFormName'] = $icForm['name'];
            if ($row['icFormAction'] == 'Renew') {
                $row['icFormName'] = $inviteControl['formList'][$icForm['renewedBy']]['name'];
            }
            $row['icFormClass'] = $icForm['formClass'];
            $row['icDiagLink'] = $icForm['dialogLink'];
            $row['userCaseNum'] = '';
            $case = (new Cases($this->clientID))->findById($icForm['caseID']);
            if (is_object($case) && $icForm['caseID'] > 0 && $icForm['action'] != 'Invite') {
                $row['userCaseNum'] = $case->get('userCaseNum');
            }
            $row['rowcls'] = ($rowcnt++ & 1 ? 'odd' : 'even');

            $rows[] = $row;
        }

        return $rows;
    }
}
