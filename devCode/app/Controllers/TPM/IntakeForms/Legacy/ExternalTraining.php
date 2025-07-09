<?php
/**
 * Launch external training and poll for completion.
 * At the time of initial implementation these routes were invoked from legacy DDQ.
 */

namespace Controllers\TPM\IntakeForms\Legacy;

use Models\TPM\ScormCourse\ExtrnTrainingCompletion;
use Lib\Traits\JsonOutput;

/**
 * Launch external training and poll for completion.
 *
 * @keywords extrnTrn, external training, poll completion, ddq completion, intake form
 */
#[\AllowDynamicProperties]
class ExternalTraining
{
    use JsonOutput;

    /**
     * @var integer TPM client ID
     */
    protected $clientID = 0;

    /**
     * @var \Skinny\Skinny framework instance
     */
    protected $app = null;

    /**
     * @var object instance of ExtrnTrainingCompletion model
     */
    protected $mdl = null;

    /**
     * Instantiate in initialize properties
     *
     * @param integer $clientID TPM tenant ID
     *
     * @return void
     */
    public function __construct($clientID)
    {
        $this->app = \Xtra::app();
        \Xtra::requireInt($clientID);
        $this->clientID = $clientID;
        $this->mdl = new ExtrnTrainingCompletion($this->clientID);
    }

    /**
     * Dispatch route to method
     *
     * @return void
     */
    public static function invoke()
    {
        $app = \Xtra::app();
        $path = rtrim((string) $app->request->getPathInfo(), '/');
        $method = substr($path, strlen('/intake/legacy/extrnTrn/'));
        if (in_array($method, ['open', 'poll'])) {
            $inputSrc = ($method == 'open') ? $app->clean_GET: $app->clean_POST;
            $clientID = $app->session->get('clientID', 0);
            $intakeFormID = $app->session->get('inFormRspnsID', 0);
            $providerID = \Xtra::arrayGet($inputSrc, 'p', 0);
            $courseID = \Xtra::arrayGet($inputSrc, 'c', 0);
            (new self($clientID))->$method($intakeFormID, $providerID, $courseID);
        }
    }

    /**
     * Launch external training course
     *
     * @param integer $intakeFormID ddq.id
     * @param integer $providerID   g_extrnTrainingProvider.id
     * @param integer $courseID     b_extrnTrainingCourse.id
     *
     * @return void
     */
    private function open($intakeFormID, $providerID, $courseID)
    {
        $courseRec = $this->mdl->getCourse($providerID, $courseID);
        $statusRec = $this->mdl->getCompletionRecord($intakeFormID, $providerID, $courseID);
        if ($courseRec && $statusRec) {
            if ($this->app->mode !== 'Production') {
                $ovrUrl = $courseRec['courseTestUrl'];
            } else {
                $ovrUrl = $courseRec['courseUrl'];
            }
            $baseUrl = (!empty($ovrUrl)) ? $ovrUrl : $courseRec['defaultCourseUrl'];
            $smid = $statusRec['uniqueLinkIdent'];
            $tid = $this->clientID;
            $cs = $courseRec['providerCourseIdent'];
            $ts = time();
            $ck = sha1($smid . $tid . $cs . $ts . $courseRec['sharedKey']);
            $courseUrl = $baseUrl
                . '?smid=' . $smid
                . '&tid=' . $tid
                . '&cs=' . urlencode((string) $cs)
                . '&ts=' . $ts
                . '&ck=' . $ck;
            if (empty($GLOBALS['PHPUNIT'])) {
                $this->app->redirect($courseUrl);
            } else {
                echo $courseUrl;
            }
        } else {
            $this->app->log->debug(['courseRec' => $courseRec, 'statusRec' => $statusRec]);
        }
    }

    /**
     * Get course completion status
     *
     * @param integer $intakeFormID ddq.id
     * @param integer $providerID   g_extrnTrainingProvider.id
     * @param integer $courseID     b_extrnTrainingCourse.id
     *
     * @return void
     */
    private function poll($intakeFormID, $providerID, $courseID)
    {
        $qID = \Xtra::arrayGet($this->app->clean_POST, 'q', '');
        $completed = $this->mdl->isCourseCompleted($intakeFormID, $providerID, $courseID);
        $obj = [
            'qID' => $qID,
            'completed' => $completed,
        ];
        echo JsonOutput::jsonEncodeResponse($obj);
    }
}
