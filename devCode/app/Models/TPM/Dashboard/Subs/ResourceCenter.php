<?php
/**
 * Contains class that provides data for the Resource Center Widget in the dashboard
 *
 * @keywords dashboard, widget, resource center
 */
namespace Models\TPM\Dashboard\Subs;

use Lib\Legacy\ClientIds;
use Lib\SettingACL;

/**
 * Provides data for the Resource center dash widget
 *
 * @package Models\TPM\Dashboard\Subs
 */
#[\AllowDynamicProperties]
class ResourceCenter
{
    /**
     * @var object Application instance
     */
    protected $app = null;

    /**
     * @var object DB instance
     */
    private $DB = null;

    /**
     * @var int $tenantID
     */
    private $tenantID = null;

    /**
     * @var array
     */
    private $trans = [
        'title' => 'Resource Center'
    ];

    /**
     * Init class constructor
     *
     * @param integer $tenantID Current tenantID
     *
     * @return null
     */
    public function __construct($tenantID)
    {
        \Xtra::requireInt($tenantID);
        $this->app      = \Xtra::app();
        $this->DB       = $this->app->DB;
        $this->tenantID = $tenantID;
        $this->sitePath = \Xtra::conf('cms.sitePath');
    }

    /**
     * get widget specific data
     *
     * @return array
     */
    public function getData()
    {
        return [[
            'title'         => $this->trans['title'],
            'description'   => null,
            'CLIENT_ID'     => $this->tenantID,
            'viewValues'    => ($this->isWideView()) ? $this->wideResourceCenter() : $this->normalResourceCenter(),
        ]];
    }

    /**
     * Returns boolean value, controls widget data
     *
     * @return boolean
     */
    private function isWideView()
    {
        $setting = (new SettingACL($this->tenantID))->get(SettingACL::RESOURCE_CENTER_WIDE_VIEW);
        return (($setting) ? $setting['value'] : false);
    }

    /**
     * Wide resource widget data
     *
     * @return array
     */
    private function wideResourceCenter()
    {
        return [
            'WIDE_RESOURCE_CENTER' => true,
            'DB_RESOURCES'         => $this->getDashboardResources(),
        ];
    }

    /**
     * Will look for files inside of: public_html/cms/dashboard/dashAttachments/
     *
     * @return bool
     */
    private function hasLocalFiles()
    {
        // Maps 100% to dashboard.sec:689-701
        return in_array(
            $this->tenantID,
            [
                ClientIds::ECOLAB_CLIENTID,
                ClientIds::GOODYEAR_CLIENTID,
                ClientIds::LIFETECH_CLIENTID,
                ClientIds::IRONMTN_CLIENTID,
                ClientIds::YUM_CLIENTID,
                ClientIds::KRAFT_CLIENTID,
                ClientIds::PERRIGO_CLIENTID,
                ClientIds::HARMAN_CLIENTID,
                ClientIds::HOMEDEPOT_CLIENTID,
                ClientIds::PALL_CLIENTID,
                ClientIds::ALLEGION_CLIENTID,
                ClientIds::GILBARCO_CLIENTID,
            ]
        );
    }

    /**
     * Appends the default of Foreign Corrupt Practices Act
     *
     * @return bool
     */
    private function hasAppendDefault()
    {
        return (!in_array(
            $this->tenantID,
            [
                ClientIds::LINCOLN_CLIENTID,
                ClientIds::AFFINIA_CLIENTID,
                ClientIds::NBCU_CLIENTID,
                ClientIds::TORO_CLIENTID,
                ClientIds::BAXTER_CLIENTID,
                ClientIds::MEDTRONIC_CLIENTID,
                ClientIds::GOODYEAR_CLIENTID,
            ]
        ));
    }

    /**
     * Determines how static html in Resource List is displayed.
     *
     * LOCAL_FILE_DATA array contains list elements from internal client files.
     *
     * @return array
     */
    private function normalResourceCenter()
    {
        $hasLocalFiles = $this->hasLocalFiles();




        return  [
            'WIDE_RESOURCE_CENTER'  => false,
            'LOCAL_FILES'           => $hasLocalFiles,
            'LINCOLN_CLIENTID'      => ($this->tenantID == ClientIds::LINCOLN_CLIENTID),
            'AFFINIA_CLIENTID'      => ($this->tenantID == ClientIds::AFFINIA_CLIENTID),
            'GENMILLS_CLIENTID'     => ($this->tenantID == ClientIds::GENMILLS_CLIENTID),
            'MEDTRONIC_CLIENTID'    => ($this->tenantID == ClientIds::MEDTRONIC_CLIENTID),
            'TORO_CLIENTID'         => ($this->tenantID == ClientIds::TORO_CLIENTID),
            'APPEND_DEFAULT'        => $this->hasAppendDefault(),
            'LOCAL_FILE_DATA'       => ($hasLocalFiles) ? $this->localFileResources() : [],
        ];
    }

    /**
     * Refactor of dashboard.sec, files inside of: public_html/cms/dashboard/dashAttachments/
     *
     * @return array
     */
    private function localFileResources()
    {
        $file = \Xtra::conf('cms.docRoot').'/cms/dashboard/dashAttachments/'
            . $this->tenantID . '/' . $this->tenantID . 'docs.php';
        $links = [];

        if (file_exists($file)) {
            // ** DO NOT ** delete the next two lines of code, they are required for the following
            // 'include $file' statement to work. The include file references $cid, so it must exist.
            // Also note, the included file sets '$ciddocs' which is used by the foreach() loop. Lastly note,
            // these include files are not under version control, so changing the code in all of the include files
            // for each client is not a good idea at this time as the files are manually maintained.
            $cid = $this->tenantID;
            define('ALLOW_CID' . $cid, 1);
            include $file;

            $path = "{$this->sitePath}cms/dashboard/dashAttachments/{$this->tenantID}";

            foreach ($ciddocs as $name => $doc) {
                $href = (str_starts_with((string) $doc, 'http')) ? $doc : "{$path}/{$doc}";
                $links[$href] = $name;
            }
        }

        return $links;
    }

    /**
     * Queries clientDB for dashboard widget links, returning a formatted array
     *
     * @return array
     */
    private function getDashboardResources()
    {
        $clientDB = $this->DB->getClientDB($this->tenantID);

        $records = $this->app->DB->fetchAssocRows(
            "SELECT title, resource FROM {$clientDB}.dashboardResources "
            . "WHERE clientID = {$this->tenantID} ORDER BY title ASC"
        );

        $parsedRecords = [];

        if (count($records)) {
            $pdfIcon = "<img src='{$this->sitePath}cms/images/filetype_icons/pdf.gif' width='16' height='16' />";
            $docIcon = "<img src='{$this->sitePath}cms/images/filetype_icons/ms-word.gif' width='16' height='16' />";
            $xlsIcon = "<img src='{$this->sitePath}cms/images/filetype_icons/excel-file.gif' width='16' height='16' />";

            $counter = 1;
            foreach ($records as $record) {
                $ext    = pathinfo((string) $record['resource'], PATHINFO_EXTENSION);
                $isUrl  = preg_match('#^https?://#i', (string) $record['resource']);

                if (!$isUrl) {
                    $type = match ($ext) {
                        'doc', 'docx' => $docIcon,
                        'pdf' => $pdfIcon,
                        'xls', 'xlsx' => $xlsIcon,
                        default => '<strong>' . strtoupper($ext) . '</strong>',
                    };
                    $link    = "dashAttachments/{$this->tenantID}/" . rawurlencode((string) $record['resource']);
                    $linkText = 'Click Here to Download';
                } else {
                    $type   = '<em><strong>URL</strong></em>';
                    $link    = $record['resource'];
                    $linkText = 'Click Here to Visit Page';
                }

                $parsedRecords[] = [
                    'count'     => $counter,
                    'title'     => $record['title'],
                    'linkText'  => $linkText,
                    'type'      => $type,
                    'link'      => ($isUrl) ? $link : $this->sitePath.'cms/dashboard/' . $link
                ];
                $counter++;
            }
        }

        return $parsedRecords;
    }
}
