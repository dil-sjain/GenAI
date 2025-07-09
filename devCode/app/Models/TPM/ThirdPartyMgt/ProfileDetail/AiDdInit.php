<?php
/**
 * Initiate AI DD-Report and save the report details
 */
namespace Models\TPM\ThirdPartyMgt\ProfileDetail;

use Lib\Support\Xtra;
use Skinny\Skinny;
use Lib\Database\MySqlPdo;
use Models\Globals\Geography;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Lib\ApplicationRegion;

/**
 * Class AiDdInit
 *
 * Using for initiate AI DD-Report and save the report details
 * and check the report status
 * */
#[\AllowDynamicProperties]
class AiDdInit
{
    /**
     * @var Skinny Class instance
     */
    protected Skinny $app;

    /**
     * @var int TPM tenant ID
     */
    protected int $clientID;

    /**
    * globalDDReportTbl
    * @var string
    */
    protected string $globalDDReportTbl='';

    /**
    * clientDDReportTbl
    * @var string
    */
    protected string $clientDDReportTbl='';

    /**
     * ThirdpartyProfile table
     * @var string
     */
    protected string $tpTbl = '';

    /**
    * DB
    * @var MySqlPdo
    */
    protected MySqlPdo $DB;

    /**
     * @const integer denoting report in progress
     */
    protected const REPORT_IN_PROGRESS = 1;

    /**
     * @const integer denoting in progress report checking hours
     */
    protected const PROGRESS_REPORT_CHECKING_HOURS = 1;

    /**
     * @var string clientDB
     */
    protected string $clientDB = '';
    
    /**
     * AiDdInit constructor.
     *
     * @param int $clientID TPM tenant ID
     */
    public function __construct(int $clientID)
    {
        $this->clientID = $clientID;
        $this->app = Xtra::app();
        $this->DB  = $this->app->DB;
        $this->clientDB = $this->DB->getClientDB($clientID);
        $this->globalDDReportTbl = $this->DB->globalDB . "." ."g_ddReports";
        $this->clientDDReportTbl = $this->clientDB . "." ."ddReports";
        $this->tpTbl = $this->clientDB . "." ."thirdPartyProfile";
    }

    /**
     * Get request parameters
     *
     * @return array
     */
    public function getRequestParameter(): array
    {
        return [
            "DBAname" => "Alternate Trade Name",
            "legalFormName" => "Legal Form of Company",
            "regCountryName" => "Country of Registration",
            "regNumber" => "Registration Number",
            "website" => "Company Website",
            "addr" => "Address",
            "city" => "City",
            "countryName" => "Country",
            "stateName" => "State",
            "postcode" => "Postcode",
            "regionName" => "Region",
            "countryRiskName" => "Highest Risk Country Asscoiated",
            "risk" => "Risk Rating",
        ];
    }

    /**
     * Build request payload
     *
     * @param int $tpID Third Party ID
     *
     * @return array
     */
    public function requestPayloadBuilder(int $tpID): array
    {
        $fieldsDetails = $this->getRequestParameter();

        $tpRow = $this->getTPProfileSumaryDetails($tpID);
        $requestPayload = [];
        $entity_name = strip_tags(htmlspecialchars_decode($tpRow->legalName, ENT_QUOTES | ENT_HTML401));
        if (empty($entity_name)) {
            return [];
        }
        $requestPayload['entity_name'] = $entity_name;
        $requestPayload['entity_type'] = "Company";
        $requestPayload['notification_url'] = getenv("DD_AI_CALL_BACK_URL");
        $requestPayload['metadata'] = [];
        
        foreach ($fieldsDetails as $column => $label) {
            if (isset($tpRow->$column) || $column == "addr") {
                if ($column == "bPublicTrade") {
                    $tpRow->$column = $tpRow->$column == 1 ? "Yes" : "No";
                } else if ($column == "addr") {
                    $tpRow->$column = "";
                    if (!empty($tpRow->addr1)) {
                        $tpRow->$column .= $tpRow->addr1;
                    }
                    if (!empty($tpRow->addr1) && !empty($tpRow->addr2)) {
                        $tpRow->$column .= ",";
                    }
                    if (!empty($tpRow->addr2)) {
                        $tpRow->$column .= $tpRow->addr2;
                    }
                }
                if (!empty($tpRow->$column)) {
                    $value = strip_tags(html_entity_decode($tpRow->$column, ENT_QUOTES | ENT_HTML401));
                    if (!empty($value) && $value !== ',') {
                        $requestPayload['metadata'][] = [
                            'name' => $label,
                            'value' => $value ?: 'N/A'
                        ];
                    }
                }
            }
        }

        $requestPayload['metadata'][] = [
            'name' => "Profile Id",
            'value' => $tpRow->userTpNum
        ];
        return $requestPayload;
    }

    /**
     * Initiate CURL request
     *
     * @param int $tpID Third Party ID
     *
     * @return array
     */
    public function initiateCurl(int $tpID)
    {
        $data = $this->requestPayloadBuilder($tpID);
        if (count($data) > 0) {
            return $this->initDDRequest(json_encode($data));
        } else {
            return ["responseCode" => 0, "curlOutput" => "Invalid request payload"];
        }
    }

    /**
     * Initialize Due Diligence request
     *
     * @param string $jsonData JSON data
     *
     * @return array
     */
    public function initDDRequest($jsonData)
    {
        $responseCode = null;
        $responseBody = null;

        // Build necessary variables
        $fullUrl = getenv("DD_AI_RID_URL");
        $referrer = $this->app->sitePath;
        $userAgent = 'TPM.Diligent.MediaMonitor (' . ApplicationRegion::getAppRegion() . ')';

        try {
            // Initialize Guzzle client with base options
            $client = new HttpClient([
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => $userAgent,
                    'Referer' => $referrer,
                    'Authorization' => getenv('mmTransparintToken')
                ],
            ]);

            // Make the POST request
            $response = $client->request('POST', $fullUrl, [
                'body' => $jsonData,
            ]);

            // Extract status code and response body
            $responseCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $responseCode = $e->getResponse()->getStatusCode();
                $responseBody = $response->getBody()->getContents();
            } else if ($e->getCode() == 0) {
                $responseCode = 1;
            } else {
                $responseCode = 2;
            }
        } catch (\Exception $e) {
            $responseCode = 2;
        }
        return ["responseCode"=>$responseCode,"curlOutput"=>$responseBody];
    }

    /**
     * Check if report is in progress stage
     *
     * @param int $tpID Third Party ID
     *
     * @return bool
     */
    public function isReportInProgressStage(int $tpID): bool
    {
        return $this->getDDCount("in-progress", $tpID, 1)  > 0;
    }

    /**
     * Get count of DD report
     *
     * @param string $usingFor using for
     * @param int    $tpID     Third Party ID
     * @param int    $status   status
     *
     * @return int
     */
    public function getDDCount(string $usingFor, int $tpID, int $status): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->clientDDReportTbl} WHERE tpID = :tpID AND clientID = :clientID AND status = :status ";
        if ($usingFor == "completed") {
            $sql .= "AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        }

        $params= [
                    ':tpID' => $tpID,
                    ':clientID' => $this->clientID,
                    ':status' => $status
        ];

        return $this->DB->fetchValue($sql, $params);
    }

    /**
     * Check if report is completed stage
     *
     * @param int $tpID Third Party ID
     *
     * @return bool
     */
    public function isCompletedStage(int $tpID): bool
    {
        return $this->getDDCount("completed", $tpID, 2) > 0;
    }

    /**
     * Save report details in global table
     *
     * @param int $reportId Report ID
     *
     * @return void
     */
    public function saveReportDetailsInGlobalTbl($reportId): void
    {
        $sql = "INSERT INTO {$this->globalDDReportTbl} (ridReportID,clientID) VALUES (:reportId, :clientID)";
        $insertParams = [':reportId' => $reportId, ':clientID' => $this->clientID];
        $this->DB->query($sql, $insertParams);
    }

    /**
     * Get report details
     *
     * @param int $reportID Report ID
     *
     * @return array
     */
    public function getReportDetails($reportID)
    {
        $sql = "SELECT * FROM {$this->clientDDReportTbl} WHERE ridReportID = :reportID and clientID = :clientID";
        $params = [':reportID' => $reportID, ':clientID' => $this->clientID];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Save report details in client table
     *
     * @param int $reportId Report ID
     * @param int $tpID     Third Party ID
     * @param int $userID   User ID
     *
     * @return void
     */
    public function saveReportDetailsInClientTbl($reportId, int $tpID, int $userID): void
    {
        $sql = "INSERT INTO {$this->clientDDReportTbl} (ridReportID, clientID, tpID, userID) VALUES (:reportId, :clientID, :tpID, :userID)";
        $insertParams = [':reportId' => $reportId, ':clientID' => $this->clientID, ':tpID' => $tpID, ':userID' => $userID];
        $this->DB->query($sql, $insertParams);
    }

    /**
     * Update progress to failure reports
     *
     * @param int $tpID Third Party ID
     *
     * @return void
     */
    public function updateProgressToFailureReports(int $tpID): void
    {
        $sql = "UPDATE {$this->clientDDReportTbl} SET status = 3, download_error = :error_content ";
        $sql .= "WHERE created_at < (NOW() - INTERVAL 1 HOUR) AND status = :status AND clientID = :clientID AND tpID = :tpID";

        $params = [
                    ':status' => self::REPORT_IN_PROGRESS,
                    ':clientID' => $this->clientID,
                    ':tpID' => $tpID,
                    ":error_content" => self::PROGRESS_REPORT_CHECKING_HOURS . " hour passed and report is still in progress"
                ];
        $this->DB->query($sql, $params);
    }

    /**
     * Fetch Third Party details
     *
     * @param int $tpID Third Party ID
     *
     * @return array | null
     */
    public function getTpProfile(int $tpID)
    {
        $sql = "SELECT userTpNum as tpNumber, tp.id as tpID, legalName as tpName, reg.name as tpRegion from {$this->tpTbl} as tp LEFT JOIN region as reg ON reg.id = tp.region  WHERE tp.id = :tpID";
        $params = [':tpID' => $tpID];
        return $this->DB->fetchAssocRow($sql, $params);
    }

    /**
     * Get Third Party Profile Summary Details
     *
     * @param int $tpID Third Party ID
     *
     * @see legacy fGetThirdPartyProfileRow function in public_html/cms/includes/php/funcs_thirdparty.php
     *
     * @return object
     */
    public function getTPProfileSumaryDetails(int $tpID)
    {
        
        $sql = "SELECT tp.*, reg.name AS regionName, ptype.name AS typeName, "
            . "pcat.name AS categoryName, "
            . "lf.name AS legalFormName, own.userName AS owner, "
            . "orig.userName AS originator, DATE_FORMAT(tp.tpCreated,'%Y-%m-%d') as createDate, "
            . "dept.name AS deptName ";
        if ($risk = $this->app->ftr->has(\Feature::TENANT_TPM_RISK)) {
            $sql .= ", ra.normalized AS riskrate, "
                  . "ra.tstamp AS risktime, rmt.scope AS invScope, "
                  . "rt.tierName AS risk, tp.riskModel ";
        } else {
            $sql .= ", '0' AS invScope ,'' AS risk ";
        }
        $sql .= "FROM {$this->tpTbl} AS tp "
            . "LEFT JOIN {$this->clientDB}.region AS reg ON reg.id = tp.region "
            . "LEFT JOIN {$this->clientDB}.department AS dept ON dept.id = tp.department "
            . "LEFT JOIN {$this->clientDB}.tpType AS ptype ON ptype.id = tp.tpType "
            . "LEFT JOIN {$this->clientDB}.tpTypeCategory AS pcat ON pcat.id = tp.tpTypeCategory "
            . "LEFT JOIN {$this->clientDB}.companyLegalForm AS lf ON lf.id = tp.legalForm "
            . "LEFT JOIN {$this->app->DB->authDB}.users AS own ON own.id = tp.ownerID "
            . "LEFT JOIN {$this->app->DB->authDB}.users AS orig ON orig.id = tp.createdBy ";
        if ($risk) {
            $sql .= "LEFT JOIN {$this->clientDB}.riskAssessment AS ra ON (ra.tpID = tp.id "
                . "AND ra.model = tp.riskModel AND ra.status = 'current') "
                . "LEFT JOIN {$this->clientDB}.riskTier AS rt ON rt.id = ra.tier "
                . "LEFT JOIN {$this->clientDB}.riskModelTier AS rmt "
                . "  ON rmt.tier = ra.tier AND rmt.model = tp.riskModel ";
        }

        $sql .= "WHERE tp.id=:id AND tp.clientID=:clientID LIMIT 1";
        $params[':id'] = $tpID;
        $params[':clientID'] = $this->clientID;

        $geo = Geography::getVersionInstance();
        $langCode = $this->app->session->get('languageCode');
        if (($row = $this->DB->fetchObjectRow($sql, $params))) {
            $row->countryName = $geo->getLegacyCountryName($row->country);
            $row->regCountryName = $geo->getLegacyCountryName($row->regCountry);
            $row->stateName = $geo->getLegacyStateName($row->state, $row->country);
        }

        if (!is_null($row) && is_object($row) && property_exists($row, 'countryOverride')) {
            if ($countryOverride = $geo->getCountryNameTranslated($row->countryOverride, $langCode)) {
                $row->countryNameOverride = $countryOverride;
            }
        }
    
        if (!is_null($row) && is_object($row) && property_exists($row, 'countryRisk')) {
            if ($countryRisk = $geo->getCountryNameTranslated($row->countryRisk, $langCode)) {
                $row->countryRiskName = $countryRisk;
            }
        }

        return $row;
    }

    /**
     * Update report view and download status
     *
     * @param int   $reportID   ddReport ID
     * @param int   $isViewed   ddReport isViewed
     * @param int   $isDownload ddReport isDownload
     *
     * @return void
     */
    public function updateViewAndDownloadStatus(int $reportID, int $isViewed = 0, int $isDownload = 0)
    {
        if ($isViewed == 0 && $isDownload == 0) {
            return false;
        }
        $sql = "UPDATE {$this->clientDDReportTbl} SET ";
        if ($isViewed > 0) {
            $sql .= "isViewed = :isViewed ";
            $params = [
                ':isViewed' => '1',
                ':reportID' => $reportID
            ];
        } else if ($isDownload > 0) {
            $sql .= "isDownload = :isDownload ";
            $params = [
                ':isDownload' => '1',
                ':reportID' => $reportID
            ];
        }
        $sql .= "WHERE ridReportID = :reportID limit 1";
        return $this->DB->query($sql, $params);
    }
}
