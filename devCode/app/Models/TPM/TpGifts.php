<?php
/**
 * TenantIdStrict model for TpGifts
 *
 * @note code generated with \Models\Cli\ModelRules\ModelRulesGenerator.php
 * @keywords tpm, gifts
 */

namespace Models\TPM;

use Models\Base\ReadWrite\TenantIdStrict;

/**
 * TenantIdStrict model for TpGifts CRUD
 */
#[\AllowDynamicProperties]
class TpGifts extends TenantIdStrict
{
    /**
     * @var string name of table
     */
    protected $table = 'tpGifts';

    /**
     * @var string field name of the tenant ID
     */
    protected $tenantIdField = 'clientID';


    /**
     * return array gettable/settable attributes w/validation rules
     *
     * comment out any attribute you wish to hide from both read & write access
     * keeping this list as small as possible will save some memory when retrieving rows
     *
     * @param string $context not functional, but would allow for future conditional attributes/validation
     *
     * @return array
     */
    public static function rulesArray($context = '')
    {
        return [
            'id'              => 'db_int',
            'clientID'        => 'db_int|required', // clientProfile.id
            'tpID'            => 'db_int|required', // thirdPartyProfile.id
            'ownerID'         => 'db_int', // users.id of record creator
            'giftRuleID'      => 'db_int', // tpGiftRules.id
            'userGethNum'     => 'max_len,16',
            'created'         => 'db_timestamp,blank-null',
            'giftDate'        => 'db_datetime,blank-null',
            'action'          => 'max_len,8',
            'amount'          => 'db_decimal, 2',
            'benefitType'     => 'max_len,100',
            'description'     => 'max_len,65535',
            'businessPurpose' => 'max_len,255',
            'category'        => "max_len,13|contains,'Gift' 'Entertainment' 'Travel' 'Hospitality'",
            'status'          => "max_len,8|contains,'New' 'Pending' 'Approved' 'Denied' 'Deleted'",
            'moderator'       => 'db_int', // user.id of approve/deny setter
            'batchID'         => 'db_int',
        ];
    }

    /**
     * configure other rules for attributes
     *
     * @param string $context allows for conditional rules (in future implementations)
     *
     * @return void
     */
    protected function loadRulesAdditional($context = '')
    {

        // set defaults, key--> column name, value--> value or functionName()
        $this->rulesDefault = [
            'ownerID'         => '0',
            'giftRuleID'      => '0',
            'userGethNum'     => '',
            'giftDate'        => '0000-00-00 00:00:00',
            'action'          => '',
            'amount'          => '0.00',
            'benefitType'     => '',
            'description'     => '',
            'businessPurpose' => '',
            'category'        => '',
            'status'          => 'New',
            'moderator'       => '0',
        ];

        $this->rulesReadonly = [
            'created',
        ];
        $this->rulesNoupdate = [
            'id',
            'clientID',
        ];
    }

    /**
     * Who receives this email?
     * TODO: This was copied over from legacy (class_geth.php) for sending emails. This should be refactored when Gifts
     * are done as part of the regular refactoring.
     *
     * @param string  $etype which type of message to send
     * @param integer $recid gifts.id record ID
     *
     * @return array list of recipients
     */
    public function whoTo($etype, $recid)
    {
        $retidu = [];
        $retid  = [];
        $retval = [];
        $recid  = intval($recid);

        /*
        $retval  = array(
            array(
                'id'    => '',
                'name'  => '',
                'email' => '',
                'subj'  => '',
            )
        );
        */

        // Find Users involved with the gift
        $sql_user_id_list = "SELECT u.id as userID, g.ownerID \n"
            . "FROM {$this->DB->prependDbName($this->DB->getDB(), 'tpGiftUser')} as gu  \n"
            . "INNER JOIN {$this->DB->prependDbName('auth', 'users')} as u ON (gu.userID = u.id) \n"
            . "INNER JOIN tpGifts as g ON (g.id = gu.giftID) \n"
            . "WHERE u.clientID='$this->tenantIdValue' \n"
            . "AND gu.clientID='$this->tenantIdValue' \n"
            . "AND gu.giftID = '$recid' \n";

        $recu = $this->DB->fetchObjectRows($sql_user_id_list);
        if (!$recu) {
            return false;
        } else {
            foreach ($recu as $index => $obj) {
                $retidu[] = $obj->ownerID;
                $retidu[] = $obj->userID;
            }
            sort($retidu);
            $retidu = array_unique($retidu); // remove redundant ids
            sort($retidu); // re-index
        }

        // Handle finding Moderator Email in charge of this gift
        $recm = $this->DB->fetchObjectRow("SELECT gr.email FROM tpGiftRules AS gr "
            . "LEFT JOIN tpGifts AS g ON g.giftRuleID=gr.id "
            . "WHERE g.id='$recid'");


        $retlist = $retidu ;//+ $retidm; // Merge arrays for owner, creator, and moderators

        $i = 0;
        foreach ($retlist as $rlk => $rl) {
            $e_rl = $this->DB->esc($rl);
            $userInf = $this->DB->fetchObjectRow("SELECT id, userName, userEmail "
                . "FROM {$this->DB->prependDbName('auth', 'users')} "
                . "WHERE id='$e_rl' ");
            //. "AND clientID='$this->_clientID'"

            if (!is_object($userInf)) {
                exitApp(
                    __FILE__,
                    __LINE__,
                    '',
                    'ERROR in whoTo method of geth class $userInf NOT an object - '
                    .'(id: ' . $rl . ')'
                );
            }
            $uarr = new \stdClass();
            // Build the return array
            switch ($etype) {
                case 'msg_NewPending':
                    $retval[$i]['id']    = $userInf->id;
                    $retval[$i]['name']  = $userInf->userName;
                    $retval[$i]['email'] = $userInf->userEmail;
                    $retval[$i]['subj']  = 'A new Gift Record has been submitted';
                    break;

                case 'msg_ApproveDeny':
                    $retval[$i]['id']      = $userInf->id;
                    $retval[$i]['name']    = $userInf->userName;
                    $retval[$i]['email']   = $userInf->userEmail;
                    $retval[$i]['subj']    = '';
                    break;

                case 'msg_Updated':
                    $retval[$i]['id']    = $userInf->id;
                    $retval[$i]['name']  = $userInf->userName;
                    $retval[$i]['email'] = $userInf->userEmail;
                    $retval[$i]['subj']  = '';
                    break;

                case 'msg_SpecialChange':
                    $retval[$i]['id']         = $userInf->id;
                    $retval[$i]['name']       = $userInf->userName;
                    $retval[$i]['email']      = $userInf->userEmail;
                    $retval[$i]['subj']       = '';
                    break;

                default:
                    //return false;
            }
            $i++;
        }
        if (is_object($recm) && $recm->email) {
            $uarr = [];
            $uarr['id']    = '';
            $uarr['email'] = $recm->email;
            $uarr['name']  = 'Gifts Moderator';
            $uarr['subj']  = $retval[0]['subj'];

            $retval[] = $uarr;
        }

        return $retval;
    }

    /**
     * Set future Approval, Denial, or Pending status property
     *
     * @param string $appdeny GETH status value
     *
     * @return boolean success or failure
     */
    public function setAppDeny($appdeny)
    {
        if ($appdeny == 'Approved'
            || $appdeny == 'Denied'
            || $appdeny == 'Pending'
            || $appdeny == 'New'
        ) {
            $this->appdeny = strtolower($appdeny);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Set previous Approval, Denial, or Pending status property
     *
     * @param string $preappdeny GETH status value
     *
     * @return boolean success or failure
     */
    public function setPreAppDeny($preappdeny)
    {
        if ($preappdeny == 'Approved'
            || $preappdeny == 'Denied'
            || $preappdeny == 'Pending'
            || $preappdeny == 'New'
        ) {
            $this->preappdeny = strtolower($preappdeny);
            return true;
        } else {
            return false;
        }
    }
}
