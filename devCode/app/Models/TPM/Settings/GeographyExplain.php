<?php
/**
 * Provide data for GeographyExplain controller
 */

namespace Models\TPM\Settings;

use Lib\Support\Xtra;
use Lib\Database\MySqlPdo;
use Exception;
use PDOException;

class GeographyExplain
{
    /**
     * @var MySqlPdo PDO class instance
     */
    private MySqlPdo $DB;

    /**
     * Instantiate class and initialize properties
     */
    public function __construct()
    {
        $this->DB = Xtra::app()->DB;
    }

    /**
     * Get details from legacyCountries
     *
     * @return array
     */
    public function getLegacyCountries(): array
    {
        $sql = "SELECT c.legacyCountryCode `code`,
            (CASE
                WHEN c.codeVariant2 IS NOT NULL THEN CONCAT(c.codeVariant, ', ', c.codeVariant2)
                WHEN c.codeVariant IS NOT NULL THEN c.codeVariant
                ELSE ''
            END) `otherCode`, c.legacyName `name`, IFNULL(c.displayAs, '') `displayAs`,
            IF(c.countryCodeID <= 0, 1, 0) `deprecated`, IFNULL(c.subdivisionLanguage, '') `language`,
            IFNULL(n.englishName, '') `languageName`
            FROM {$this->DB->isoDB}.legacyCountries c
            LEFT JOIN {$this->DB->isoDB}.languageNames n ON n.iso639_2 = c.subdivisionLanguage
            WHERE c.countryCodeID > 0 OR c.deferCodeTo IS NULL
            ORDER BY IFNULL(c.displayAs, c.legacyName)";
        try {
            $countries = $this->DB->fetchAssocRows($sql);
        } catch (PDOException | Exception $e) {
            Xtra::track([
                'SQL' => $sql,
                'error' => $e->getMessage(),
            ]);
            $countries = [];
        }
        return $countries;
    }

    /**
     * Provide details from legacyStates table for a country
     *
     * @param string $countryCode legacyStates.legacyCountryCode
     *
     * @return array
     */
    public function getSubdivisionDetails(string $countryCode): array
    {
        $sql = "SELECT s.legacyStateCode `code`, IFNULL(s.displayAs, '') `displayAs`, s.legacyName `name`,
            IF(s.isParent, 'Yes', '') `isParent`, IFNULL(s.codeVariant, '') `otherCode`,
            s.`language`, IFNULL(n.englishName, '') `languageName`, IFNULL(c.category_name, '') `category`
            FROM  {$this->DB->isoDB}.legacyStates s
            LEFT JOIN {$this->DB->isoDB}.subdivisionCategories c ON c.category_id = s.categoryID
              AND c.language_alpha_3_code = 'eng'
            LEFT JOIN {$this->DB->isoDB}.languageNames n ON n.iso639_2 = s.`language`
            WHERE s.legacyCountryCode = :code AND s.subdivisionID > 0
            ORDER BY s.isParent DESC, `name`";
        try {
            $details = $this->DB->fetchAssocRows($sql, [':code' => $countryCode]);
        } catch (PDOException | Exception $e) {
            Xtra::track([
                'SQL' => $this->DB->mockFinishedSql($sql, [':code' => $countryCode]),
                'error' => $e->getMessage(),
            ]);
            $details = [];
        }
        return $details;
    }
}
