<?php
/**
 * Provide data access for responses to requests from user interaction on 3P UBO sub-tab
 */

namespace Models\TPM\ThirdPartyMgt\ProfileDetail;

use Models\LogData;
use Models\TPM\TpProfile\TpProfile;
use Models\Globals\Geography;
use Lib\Database\MySqlPdo;
use Lib\Support\Xtra;
use Exception;
use Skinny\Skinny;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\TransferStats;
use Lib\SettingACL;
use Models\TPM\UboDnBApi;

#[\AllowDynamicProperties]
class UboSubTabData
{
    /**
     * @var MySqlPdo PDO class instance for normal application data access
     */
    protected MySqlPdo $DB;

    /**
     * @var MySqlPdo PDO class instance for UBO database
     */
    protected MySqlPdo $uboDB;

    /**
     * @var Skinny Class instance
     */
    protected Skinny $app;

    /**
     * @var UboDnBApi Class instance
     */
    protected UboDnBApi $uboApi;

    /**
     * Instantiate class and set instance properties
     *
     * @param int $clientID TPM tenant ID
     */
    public function __construct(protected int $clientID)
    {
        $this->app = Xtra::app();
        $this->DB = $this->app->DB;
        $this->uboApi = new UboDnBApi();
        $this->uboDB = $this->uboApi->getUboPdo();
    }

    /**
     * Get DUNS number as string from thirdPartyProfile record
     *
     * @param int $tpID thirdPartyProfile.id
     *
     * @return string
     *
     * @throws Exception
     */
    public function getAssignedDuns(int $tpID): string
    {
        try {
            $result = '';
            $tpRecord = (new TpProfile($this->clientID))->selectByID($tpID, ['DUNS']);
            if (!empty($tpRecord['DUNS'])) {
                $result = $tpRecord['DUNS'];
            }
            return $result;
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Determine if client has assigned their limit on DUNS numbers
     *
     * @return bool
     *
     * @throws Exception
     */
    public function usedMaxDuns(): bool
    {
        try {
            $result = false;
            $byolValue = (new SettingACL($this->clientID))->get(
                SettingACL::UBO_BRING_YOUR_OWN_LICENSE
            );
            $byolStatus = isset($byolValue['value']) && $byolValue['value'] == '1';
            if ($byolStatus) {
                $result = false;
            } else {
                $dunsUsed = $this->getUBOClientRelationshipByDUNS();
                $setting = (new SettingACL($this->clientID))->get(
                    SettingACL::MAX_UBO_DUNS_NUMBERS
                );
                $maxDuns = ($setting) ? $setting['value'] : 0;
                if ($dunsUsed) {
                    $result = $dunsUsed >= $maxDuns;
                }
            }
            return $result;
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Save DUNS number to 3P record
     *
     * @param string $duns DnB entity identifier
     *
     * @return bool
     */
    public function saveDunsToThirdParty(string $duns): bool
    {
        // Update thirdPartyProfile table DUNS column
        $tpID = (int)$this->app->session->get('currentID.3p', 0);
        $updated = $saved = false;
        try {
            if ($this->updateThirdParty($duns, $tpID)) {
                $updated = true;
                // Check and insert g_uboClientRelationship table
                $dunsUsed = $this->getUBOClientRelationshipByDUNS($duns);
                if ($dunsUsed == 0) {
                    if ($this->saveUBOClientRelationship($duns)) {
                        $saved = true;
                    }
                }
            }
        } catch (Exception $e) {
            Xtra::track([
                'File' => $e->getFile() . ':' . $e->getLine(),
                'Error' => $e->getMessage()
            ]);
        }
        return $updated && $saved;
    }

    /**
     * Get data from g_uboClientRelationship table by DUNS number
     *
     * @param string $duns DUNS number
     *
     * @return int Count of records
     *
     * @throws Exception
     */
    private function getUBOClientRelationshipByDUNS(string $duns = ''): int
    {
        $result = 0;
        try {
            if (!empty($duns)) {
                $sql = "SELECT COUNT(id) FROM {$this->DB->globalDB}.`g_uboClientRelationship`
                 WHERE DUNS = :DUNS AND clientID = :clientID;";
                return $this->DB->fetchValue($sql, [':DUNS' => $duns, ':clientID' => $this->clientID]);
            } else {
                $sql = "SELECT COUNT(id) FROM {$this->DB->globalDB}.`g_uboClientRelationship`
                 WHERE clientID = :clientID;";
                return $this->DB->fetchValue($sql, [':clientID' => $this->clientID]);
            }
        } catch (Exception) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
        return $result;
    }

    /**
     * Save data in g_uboClientRelationship table for global database
     *
     * @param string $duns DUNS number
     *
     * @return int Last insert ID
     *
     * @throws Exception If error occurs during database query
     */
    private function saveUBOClientRelationship(string $duns)
    {
        try {
            $sql = "INSERT INTO {$this->DB->globalDB}.`g_uboClientRelationship` (`DUNS`, `clientID`) 
                    VALUES (:DUNS, :clientID);";
            $this->DB->query($sql, [':DUNS' => $duns, ':clientID' => $this->clientID]);
            return $this->DB->lastInsertId();
        } catch (Exception) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Get available address part to combine into an address string
     *
     * @param array $primaryAddress Address information from DnB API
     *
     * @return array
     */
    private function getAddressParts(array $primaryAddress): array
    {
        $addressParts = [];
        if (!empty($primaryAddress['streetAddress']['line1'])) {
            $addressParts[] = $primaryAddress['streetAddress']['line1'];
        }
        if (!empty($primaryAddress['addressLocality']['name'])) {
            $addressParts[] = $primaryAddress['addressLocality']['name'];
        }
        if (!empty($primaryAddress['addressCountry']['name'])) {
            $addressParts[] = $primaryAddress['addressCountry']['name'];
        }
        return $addressParts;
    }

    /**
     * Get search dialog html
     *
     * @param int $tpID thirdPartyProfile.id
     *
     * @return string
     *
     * @throws Exception
     */
    public function getDialogInformation(int $tpID): string
    {
        try {
            $fields = ['legalName `name`', 'city', 'addr1 `address`', 'country'];
            if (!($tpRecord = (new TpProfile($this->clientID))->selectByID($tpID, $fields))) {
                $tpRecord = ['name' => '', 'city' => '', 'address' => '', 'country' => ''];
            }
            // filter countries to exclude KV and translate others to true ISO codes
            $countryList = (Geography::getVersionInstance(null, $this->clientID))->countryList();
            $substitute = [
                'TP' => 'TL',
                'UK' => 'GB',
                'TU' => 'TC',
            ];
            $countries = [];
            foreach ($countryList as $code => $name) {
                if ($code === 'KV') {
                    continue;
                } elseif (array_key_exists($code, $substitute)) {
                    $code = $substitute[$code];
                }
                $countries[$code] = $name;
            }
            $tpRecord['countries'] = $countries;
            return $this->app->view->fetch(
                'TPM/ThirdPartyMgt/ProfileDetail/UboFindDunsDialog.tpl',
                $tpRecord
            );
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Return a list of matching entities for name search API
     *
     * @param string $companyName Company name to search (required)
     * @param string $address     Address to search
     * @param string $city        City to search
     * @param string $country     Country to search (required)
     *
     * @return array
     *
     * @throws Exception
     */
    public function getEntityList(string $companyName, string $address, string $city, string $country): array
    {
        $entities = [];
        $maxReached = $this->usedMaxDuns();
        if (!$maxReached) {
            $searchData = [
                'searchTerm' => $companyName,
                'countryISOAlpha2Code' => $country,
                'pageSize' => '50'
            ];
            if (!empty($address)) {
                $searchData['streetAddressLine1'] = $address;
            }
            if (!empty($city)) {
                $searchData['addressLocality'] = $city;
            }
            $searchDunsData = $this->dnbApiSearchDuns($searchData);
            if ($searchDunsData) {
                if (200 === $searchDunsData['statusCode']) {
                    $searchDunsData = json_decode((string) $searchDunsData['responseData'], true);
                    if (isset($searchDunsData['searchCandidates'])
                        && !empty($searchDunsData['searchCandidates'])
                        && count($searchDunsData['searchCandidates']) > 0
                    ) {
                        foreach ($searchDunsData['searchCandidates'] as $searchCandidate) {
                            $searchCandidateData = $searchCandidate['organization'];

                            $addressParts = [];
                            if (isset($searchCandidateData['primaryAddress'])) {
                                $addressParts
                                    = $this->getAddressParts($searchCandidateData['primaryAddress'], $addressParts);
                            }
                            $candidateAddress = $addressParts ? implode(', ', $addressParts) : '(unknown)';

                            $entities[] = [
                                'name' => $searchCandidateData['primaryName'] ?? '(unknown)',
                                'address' => $candidateAddress,
                                'duns' => $searchCandidateData['duns'] ?? '',
                            ];
                        }
                        return ['entities' => $entities, 'totalResult' => $searchDunsData['candidatesMatchedQuantity']];
                    } else {
                        throw new Exception('No Data Found');
                    }
                }
                throw new Exception('Error in loading results. Please contact system administrator.');
            }
        } else {
            throw new Exception('D-U-N-S number limit has been exhausted. '
                . 'You need to upgrade the package in order to continue further');
        }
    }

    /**
     * Get entity name and address by DUNS number
     *
     * @param string $duns DUNS number
     *
     * @return array
     *
     * @throws Exception
     */
    public function getEntityByDuns(string $duns): array
    {
        if ($entity = $this->uboApi->getStoredEntity($duns)) {
            return $entity; // faster/better to get it from the DB than from API
        }
        $maxReached = $this->usedMaxDuns();
        if (!$maxReached) {
            $dunsData = $this->dnbApiSearchDuns(['duns' => $duns]);
            if ($dunsData) {
                if (200 === $dunsData['statusCode']) {
                    $dunsData = json_decode((string) $dunsData['responseData'], true);
                    if (!empty($dunsData['searchCandidates'][0]['organization'])) {
                        $dunsDetail = $dunsData['searchCandidates'][0]['organization'];
                        if (isset($dunsDetail['primaryName']) && isset($dunsDetail['primaryAddress'])) {
                            $addressParts = $this->getAddressParts($dunsDetail['primaryAddress']);
                            $entityAddress = $addressParts ? implode(', ', $addressParts) : '(unknown)';
                            $this->uboApi->updateStoredEntity($duns, $dunsDetail['primaryName'], $entityAddress);
                            return [
                                'name' => $dunsDetail['primaryName'],
                                'address' => $entityAddress,
                            ];
                        }
                    }
                    throw new Exception('No Data Found');
                }
            }
            throw new Exception('Error in loading results. Please contact system administrator.');
        } else {
            throw new Exception('D-U-N-S number limit has been exhausted. '
                . 'You need to upgrade the package in order to continue further');
        }
    }

    /**
     * Search DnB for matching entities
     *
     * @param array $body Data which will be used to search DUNS
     *
     * @return mixed|\Psr\Http\Message\ResponseInterface
     *
     * @throws Exception
     */
    private function dnbApiSearchDuns(array $body)
    {
        if ($accessToken = $this->uboApi->accessToken($this->clientID)) {
            $client = new HttpClient();
            $requestOptions = [
                'headers' => [
                    "Content-Type" => "application/json",
                    "Authorization" => sprintf("Bearer %s", $accessToken)
                ],
                'body' => json_encode($body),
                'on_stats' => function (TransferStats $stats) {
                    if ($stats->hasResponse()) {
                        $statsResponse = $stats->getResponse();
                        $statsCode = $statsResponse->getStatusCode();
                        if ($statsCode === 404) {
                            throw new Exception('No matching records found.');
                        } elseif (!in_array($statsCode, [200])) {
                            throw new Exception('Error in loading results. Please contact system administrator.');
                        }
                    }
                }
            ];
            $response = $client->request(
                'POST',
                getenv("uboSearchApi"),
                $requestOptions
            );
            $responseBody = $response->getBody();
            return [
                'statusCode' => $response->getStatusCode(),
                'responseData' => $responseBody->getContents()
            ];
        }
        throw new Exception('Error in loading results. Please contact system administrator.');
    }

    /**
     * Get latest DUNS version from ubo table
     *
     * @param string $duns D-U-N-S number
     *
     * @return array
     *
     * @throws Exception
     */
    public function getLatestDunsVersion(string $duns): array
    {
        try {
            return $this->uboApi->latestUboVersion($duns);
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }

    /**
     * Get user viewed version from g_uboUserRelationship table
     *
     * @param string $duns   D-U-N-S number
     * @param int    $userID users.id of logged-in user
     *
     * @return int
     */
    public function getUserViewedVersion(string $duns, int $userID): int
    {
        $lastVersion = 0;
        try {
            $sql = "SELECT viewedVersion FROM {$this->DB->globalDB}.g_uboUserRelationship\n"
                . "WHERE DUNS = :DUNS AND userID = :userID ORDER BY id DESC LIMIT 1";
            $params = [':DUNS' => $duns, ':userID' => $userID];
            if ($version = $this->DB->fetchValue($sql, $params)) {
                $lastVersion = $version;
            }
        } catch (Exception $e) {
            Xtra::track([
                'Where' => $e->getFile() . ':' . $e->getLine(),
                'SQL' => $this->DB->mockFinishedSql($sql, $params),
                'Error' => $e->getMessage(),
            ]);
        }
        return $lastVersion;
    }

    /**
     * Insert user's viewed version in g_uboUserRelationship table
     *
     * @param int    $userID  users.id
     * @param string $duns    D-U-N-S number
     * @param int    $version latest UBO version for this D-U-N-S
     *
     * @return bool
     *
     * @throws Exception
     */
    public function insertUserViewedVersion(int $userID, string $duns, int $version): bool
    {
        $result = false;
        try {
            $sql = "INSERT INTO {$this->DB->globalDB}.g_uboUserRelationship (userID, DUNS, viewedVersion) 
                    VALUES (:userID, :DUNS, :viewedVersion)";
            $params = [
                ':userID' => $userID,
                ':DUNS' => $duns,
                ':viewedVersion' => $version
            ];
            $result = ($this->DB->query($sql, $params) && $this->DB->lastInsertId() > 0);
        } catch (Exception $e) {
            Xtra::track([
                'Where' => $e->getFile() . ':' . $e->getLine(),
                'SQL' => $this->DB->mockFinishedSql($sql, $params),
                'Error' => $e->getMessage(),
            ]);
        }
        return $result;
    }

    /**
     * Update thirdPartyProfile table DUNS column
     *
     * @param string $duns DUNS number
     * @param int    $tpID ThirdParty ID
     *
     * @return bool
     *
     * @throws Exception
     */
    private function updateThirdParty(string $duns, int $tpID): bool
    {
        try {
            $clientDB = $this->DB->getClientDB($this->clientID);
            $table = "$clientDB.thirdPartyProfile";
            $sql = "SELECT DUNS FROM $table WHERE id = :profileID LIMIT 1";
            $dunsWas = $this->DB->fetchValue($sql, [':profileID' => $tpID]);
            $sql = "UPDATE thirdPartyProfile SET DUNS = :DUNS WHERE id = :profileID LIMIT 1";
            if (($pdoResult = $this->DB->query($sql, [':DUNS' => $duns, ':profileID' => $tpID]))
                && $pdoResult->rowCount()
            ) {
                // Log the change
                $userID = $this->app->session->get('authUserID');
                // get rid of null for inclusion in log message
                $dunsWas = empty($dunsWas) ? '' : $dunsWas;
                $logMessage = "Assigned DnB D-U-N-S number: `$dunsWas` => `$duns`";
                // 52 | Update Third Party
                (new LogData($this->clientID, $userID))->save3pLogEntry(52, $logMessage, $tpID);

                // Register user relationship
                if ($this->getUserViewedVersion($duns, $userID) === 0) {
                    $this->insertUserViewedVersion($userID, $duns, 1);
                }
            }
            return true; // no errors; don't care if there was an actual update
        } catch (Exception $e) {
            throw new Exception('Error in loading results. Please contact system administrator.');
        }
    }
}
