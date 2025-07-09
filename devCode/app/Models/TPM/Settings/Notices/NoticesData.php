<?php
/**
 * Model: check if any site notices are available for subscriber
 *
 * @keywords SiteNoticesData, site, data, notices
 */

namespace Models\TPM\Settings\Notices;

use Xtra;
use Lib\Legacy\UserType;
use Lib\FeatureACL;

/**
 * Provides checks for all data being saved
 */
#[\AllowDynamicProperties]
class NoticesData
{
    /**
     * @var \Lib\Database\MySqlPdo class instance
     */
    protected $DB   = null;

    /**
     * @var \Sckinny\Skinny class instance
     */
    protected $app  = null;

    /**
     * @var UserType class instance
     */
    protected $utm  = null;

    /**
     * @var int TPM tenant ID
     */
    protected $tenantID   = 0;

    /**
     * @var array|null Array of table names
     */
    protected $tbl        = null;

    /**
     * @var int users.id
     */
    protected $userID     = 0;

    /**
     * @var int userType.id
     */
    protected $userTypeID = 0;

    /**
     * Constructor - initialization
     *
     * @param integer $tenantID Delta tenantID
     *
     * @return void
     */
    public function __construct($tenantID)
    {
        $this->app = Xtra::app();
        $this->DB = $this->app->DB;
        $this->tenantID = (int)$tenantID;
        if (!$this->tenantID) {
            throw new \InvalidArgumentException('Unauthorized tenant; zero value.');
        }
        $this->userID = intval($this->app->session->get('authUserID'));
        if (!$this->userID) {
            throw new \InvalidArgumentException('Unauthorized user; zero value.');
        }
        $this->userTypeID = intval($this->app->session->get('authUserType'));
        if (!$this->userTypeID) {
            throw new \InvalidArgumentException('Unauthorized user type; zero value.');
        }
        $this->utm = new UserType();
        $this->tbl = (object)null;
        $this->tbl->gateAccessLevel = $this->DB->globalDB . '.g_gateAccessLevel';
        $this->tbl->users           = $this->DB->authDB . '.users';
        $this->tbl->siteNotice      = $this->DB->globalDB . '.g_siteNotice';
    }

    /**
     * Display site-wide notices to users
     *
     * @return Array of site notices
     */
    public function displayNotices()
    {
        $userClass = $this->getUserClass();
        if ($userClass == 'admin') {
            $userTypes = $this->DB->fetchKeyValueRows("SELECT id, name "
                . "FROM {$this->tbl->gateAccessLevel} "
                . "WHERE userClass <> 'admin'");
        }

        // Clear user account flag to redirect to this page
        $this->DB->query(
            "UPDATE {$this->tbl->users} SET bSeeNotice = 0 WHERE id = :userID LIMIT 1",
            [':userID' => $this->userID]
        );

        // Clear expired
        $this->DB->query(
            "UPDATE {$this->tbl->siteNotice} SET active = 0 WHERE expiration < :today",
            [':today' => date("Y-m-d")]
        );

        $userCond = '';
        $sql = "SELECT *, UNIX_TIMESTAMP(tstamp) AS t_stamp FROM {$this->tbl->siteNotice} "
            . "WHERE active = :active ";
        $params = [':active' => 1];
        // Add audience and client check
        if ($userClass != 'admin') {
            $sql .= "AND FIND_IN_SET(:userTypeID, audience) "
                . "AND (clients = '' OR FIND_IN_SET(:tenantID, clients)) ";
            $params[':userTypeID'] = $this->userTypeID;
            $params[':tenantID'] = $this->tenantID;
        }
        $sql .= "ORDER BY tstamp DESC";

        if (!$notices = $this->DB->fetchObjectRows($sql, $params)) {
            return $noticeDataArray = ['activeNotices' => false, 'content' => $this->app->trans->codeKey('settings_notices_noActiveNotices')];
        } else {
            $noticeDataArray = ['activeNotices' => true];
        }

        $cnt = 0;
        foreach ($notices as $notice) {
            $tmp = [];
            if ($userClass == 'admin' && !empty($notice->audience)) {
                $ids = explode(',', (string) $notice->audience);
                foreach ($ids as $id) {
                    if (isset($userTypes[$id])) {
                        $tmp[] = $userTypes[$id];
                    }
                }
            }
            $noticeDataArray[] = ['audTitle' => $this->app->trans->codeKey('audience_title'), 'audience' => ((!empty($tmp)) ? implode(',', $tmp) : ''), 'title' => $notice->title, 'content' => $notice->content, 'noticeSpace' => (($cnt) ? '3em' : '1.5em'), 'noticeDate' => date('D, M j, Y - g:ia', $notice->t_stamp), 'tstamp' => $notice->t_stamp];
            $cnt++;
        }
        return $noticeDataArray;
    }

    /**
     * Get the legacy user type.
     *
     * @return string admin|client|vendor
     */
    protected function getUserClass()
    {
        $admin = $this->app->ftr->isLegacySuperAdmin();
        return $this->utm->getUserClass($this->userTypeID, $this->tenantID, 0, $admin);
    }
}
