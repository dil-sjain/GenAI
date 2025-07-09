<?php
/**
 * Provide responses to requests from user interaction on AI DD-Report
 */

namespace Controllers\TPM\ThirdPartyMgt\ProfileDetail;

use Models\TPM\ThirdPartyMgt\ProfileDetail\AiDdReportData;
use Skinny\Skinny;
use Lib\Traits\AjaxDispatcher;
use Lib\Support\Xtra;
use Exception;

#[\AllowDynamicProperties]
class AiDdReport
{
    use AjaxDispatcher;

    /**
     * @var Skinny Instance of PHP framework
     */
    protected Skinny $app;

    /**
     * @var int TPM tenant ID
     */
    protected int $clientID;

    /**
     * @var AiDdReportData Class instance for data access
     */
    protected AiDdReportData $data;

    /**
     * Instantiate class and set instance properties
     *
     * @param int $clientID TPM tenant ID
     */
    public function __construct(int $clientID)
    {
        $this->clientID = $clientID;
        $this->app = Xtra::app();
        $this->data = new AiDdReportData($clientID);
    }

    /**
     * Get user pending notifications
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxGetNotification(): void
    {
        $notificationID = $this->getPostVar('nID', '');
        if ($notificationID > 0) {
            $this->data->updateNotification($notificationID, '2');
        }
        $userID = $this->app->session->get('authUserID');
        $data = $this->data->getNotification($userID);
        if ($data) {
            if ($data['nStatus'] == '0') {
                $this->data->updateNotification($data['nID'], '1');
            }
            $this->jsObj->Result = 1;
            $this->jsObj->Args = $data;
        } else {
            $this->jsObj->ErrTitle = 'Nothing to Show';
            $this->jsObj->ErrMsg = 'Nothing to Show.';
        }
    }

    public function ajaxCheckNotification()
    {
        set_time_limit(0);

        $userID = $this->app->session->get('authUserID') ?: '';

        if ($userID == "") {
            exit();
        }
        
        session_write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $startTime = time();
        while (time() - $startTime <= 3600) { // Run for up to 1 hour
            if (connection_aborted()) {
                break;
            }

            $data = $this->data->getNotification($userID);
            if ($data) {
                $result = 1;
                echo "data: $result" . PHP_EOL . PHP_EOL;
                @ob_flush();
                flush();
                break;
            }
            sleep(60);
        }
        exit();
    }


    /**
     * Check user has pending notifications or in-progress reports
     *
     * @return void
     *
     * @throws Exception
     */
    private function ajaxCheckPendingReport(): void
    {
        $userID = $this->app->session->get('authUserID');
        $data = $this->data->checkInprogressReport($userID);
        $this->jsObj->Result = $data ? 1 : 0;
    }
}
