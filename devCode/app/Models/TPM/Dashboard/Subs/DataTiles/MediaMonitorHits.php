<?php
/**
 * Find TPPs with GDC hits that have not yet been reviewed
 *
 * @keywords dashboard, data ribbon
 */

namespace Models\TPM\Dashboard\Subs\DataTiles;

use Models\ADMIN\Config\MediaMonitor\MediaMonitorData;

/**
 * Class MediaMonitorHits
 *
 * @package Models\TPM\Dashboard\Subs\DataTiles
 */
#[\AllowDynamicProperties]
class MediaMonitorHits extends TpTileBase
{
    /**
     * @var MediaMonitorData Class instance
     */
    private $mmModel = null;

    /**
     * CaseGdcFlagsToAdjudicate constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->title = 'Third Parties: Undetermined Media Monitor Hits';
        $this->selectTable = 'mediaMonResults AS results';
        $this->mmModel = new MediaMonitorData($this->tenantID);
    }

    /**
     * Override default WHERE statement in query
     *
     * @return void
     */
    public function setWhere()
    {
        $this->where[] = "AND: tPP.status != 'deleted'";
        $this->where[] = "AND: tPP.mmUndeterminedHits > 0";
        $this->where[] = "AND: (tPP.gdcScreeningID IS NOT NULL AND tPP.gdcScreeningID > 0)";
        $this->where[] = "AND: results.deleted = 0";
    }

    /**
     * (TP tiles currently use the Search3pData class)
     * Override to set custom JOIN tables for SQL.
     *
     * @return void
     */
    public function setDefaultJoins()
    {
        if (\Xtra::usingGeography2()) {
            $countryOn = '(ctry.legacyCountryCode = tPP.country '
                . 'OR ctry.codeVariant = tPP.country OR ctry.codeVariant2 = tPP.country) '
                . 'AND (ctry.countryCodeID > 0 OR ctry.deferCodeTo IS NULL)';
        } else {
            $countryOn = 'ctry.legacyCountryCode = tPP.country';
        }
        $joins = [
            'LEFT JOIN thirdPartyProfile AS tPP ON tPP.id = results.tpProfileID',
            'LEFT JOIN ' . $this->app->DB->isoDB . ".legacyCountries AS ctry ON $countryOn",
            'LEFT JOIN ' . $this->app->DB->authDB . '.users AS u ON u.id = tPP.ownerID',
            'LEFT JOIN region AS rgn ON rgn.id = tPP.region',
            'LEFT JOIN department AS dept ON dept.id = tPP.department',
        ];

        // Only JOIN risk tables if feature is enabled for tenant
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $joins[] = 'LEFT JOIN riskAssessment AS ra ON
                (ra.tpID = tPP.id AND ra.model = tPP.riskModel AND ra.status = \'current\')';
            $joins[] = 'LEFT JOIN riskTier AS rt ON rt.id = ra.tier';
            $joins[] = 'LEFT JOIN riskModelTier AS rMT ON (rMT.model = ra.model AND rMT.tier = rt.id)';
        }

        $this->joins = $joins;
    }

    /**
     * Retrieve SQL query results
     *
     * @return array
     */
    protected function getResults()
    {
        $timer = false;
        if ($timer) {
            $mtime = microtime();
            $mtime = explode(' ', $mtime);
            $mtime = $mtime[1] + $mtime[0];
            $tstart = $mtime;
        }

        $query = $this->buildQuery();
        $items = $this->db->fetchAssocRows($query, $this->whereParams);

        $total = 0;
        foreach ($items as $item) {
            $total += $item['hits'];
        }

        if ($timer) {
            $mtime = microtime();
            $mtime = explode(' ', $mtime);
            $mtime = $mtime[1] + $mtime[0];
            $tend = $mtime;
            $totalTime = ($tend - $tstart);
            $totalTime = sprintf("%2.4f s", $totalTime);
        }

        return compact('items', 'total');
    }

    /**
     * Override default query fields to only return the count of found records
     *
     * @return array
     */
    protected function getQueryFields()
    {
        $fields = parent::getQueryFields();

        $fields[] = 'tPP.mmUndeterminedHits AS hits';

        return $fields;
    }

    /**
     * List of fields to be displayed from returned case data
     *
     * @return array
     */
    public function getDisplayFields()
    {
        $display =  [
            [
                'text'      => 'TP Number',
                'dataField' => 'tpNum',
                'width'     => 100
            ],
            [
                'text'      => 'Company Name',
                'dataField' => 'companyName',
                'width'     => 225
            ],
            [
                'text'      => 'Hits',
                'dataField' => 'hits',
                'width'     => 50
            ],
            [
                'text'      => 'Region',
                'dataField' => 'region',
                'width'     => 100
            ],
            [
                'text'      => 'Department',
                'dataField' => 'department',
                'width'     => 100
            ],
        ];

        // Only display risk if feature is enabled for tenant
        if ($this->app->ftr->tenantHas(\Feature::TENANT_TPM_RISK)) {
            $display[] = [
                'text'      => 'Risk Rating',
                'dataField' => 'risk',
                'width'     => 150
            ];
        }

        return $display;
    }
    /**
     * List of fields that will be returned and the type of data contained. Should maintain parity with getQueryFields
     *
     * @return array
     */
    protected function getFieldTypes()
    {
        $fieldTypes = parent::getFieldTypes();

        $fieldTypes[] = [
                'name' => 'hits',
                'type' => 'number'
            ];

        return $fieldTypes;
    }

    /**
     * Get URL for link redirects
     *
     * @return string
     */
    protected function getUrl()
    {
        return '/cms/thirdparty/thirdparty_home.sec?id={{ id }}&tname=thirdPartyFolder&pdt=dd&rvw=1&delta=2';
    }
}
