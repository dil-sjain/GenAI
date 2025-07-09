<?php
/**
 * Model: super admin utility Batch Upload Cases
 *
 * @keywords cases, batch upload cases validation
 */

namespace Models\TPM\Admin\Cases;

use Lib\Support\ValidationCustom;
use Models\ThirdPartyManagement\Cases;
use Models\ThirdPartyManagement\SubjectInfoDD;
use Models\ThirdPartyManagement\ThirdParty;

/**
 * Provides validation methods for admin tool Batch Upload Cases
 */
#[\AllowDynamicProperties]
class BatchUploadCasesValidation
{
    private $clientID = 0;
    private $fallbackErrMsg = null;

    /**
     * Constructor - initialization
     *
     * @param integer $clientID Client ID
     *
     * @return void
     */
    public function __construct($clientID = 0)
    {
        \Xtra::requireInt($clientID, 'clientID must be an integer value.');
        if ($clientID <= 0) {
            throw new \Exception('Invalid Client ID value.');
        }
        $this->clientID = $clientID;
        $this->fallbackErrMsg = 'Improper format/Invalid data.';
    }



    /**
     * Validation for classes extending the base model (Cases, ThirdParty, SubjectInfoDD)
     *
     * @param string $class    Class to be instantiated
     * @param string $tenantID Used to instantiate the class
     * @param string $method   Method to be called
     * @param array  $args     Contains additional arguments to be fed to the method
     *
     * @return array contains result boolean and errMsg array
     */
    private function baseModelValidation($class, $tenantID, $method, $args = [])
    {
        $tenantID = (int)$tenantID;
        if (empty($class) || $tenantID <= 0 || empty($method) || !method_exists($class, $method)
            || !array_key_exists('value', $args) || empty($args['value'])
        ) {
            return ['result' => false, 'errMsg' => [$this->fallbackErrMsg]];
        }
        $instance = (new \ReflectionClass($class))->newInstanceArgs([$tenantID]);
        $validated = call_user_func_array([$instance, $method], $args);
        $errMsg = [];
        if (!$validated) {
            $errorOutput = $instance->getErrors();
            if (!empty($errorOutput)) {
                foreach ($errorOutput as $error) {
                    $errMsg[] = $error['error_msg'];
                }
            } else {
                $errMsg[] = $this->fallbackErrMsg;
            }
        }
        return ['result' => $validated, 'errMsg' => $errMsg];
    }



    /**
     * Validation for ValidationCustom class
     *
     * @param string $method Method to be called
     * @param mxed   $value  Value (string or int) to be passed as argument in method
     *
     * @return array contains result boolean and errMsg array
     */
    private function customValidation($method, $value)
    {
        if (empty($value)) {
            return ['result' => false, 'errMsg' => [$this->fallbackErrMsg]];
        }
        $validated = (new ValidationCustom)->$method($value);
        $errMsg = [];
        if (!$validated['result']) {
            if (!empty($validated['errMsg'])) {
                $errMsg[] = $validated['errMsg'];
            } else {
                $errMsg[] = $this->fallbackErrMsg;
            }
        }
        return ['result' => $validated['result'], 'errMsg' => $errMsg];
    }



    /**
     * Validate alphaNumeric input
     *
     * @param string $value alphaNumeric string to evaluate.
     *
     * @return array contains result boolean and errMsg string
     */
    public function alphaNumeric($value)
    {
        return $this->customValidation('validateAlphaNumeric', $value);
    }



    /**
     * Validate Case Due Date input
     *
     * @param string $value Date string to evaluate.
     *
     * @return array contains result boolean and errMsg string
     */
    public function caseDueDate($value)
    {
        $args = ['value' => $value, 'setError' => true];
        return $this->baseModelValidation(
            \Models\ThirdPartyManagement\Cases::class,
            $this->clientID,
            'validateCaseDueDate',
            $args
        );
    }


    /**
     * Validate Case Type input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array contains result boolean and errMsg array
     */
    public function caseType(mixed $value)
    {
        $args = ['value' => $value, 'setError' => true];
        return $this->baseModelValidation(
            \Models\ThirdPartyManagement\Cases::class,
            $this->clientID,
            'validateCaseType',
            $args
        );
    }


    /**
     * Validate Case Type ID input
     *
     * @param integer $value Case Type ID
     *
     * @return array contains result boolean and errMsg array
     */
    public function caseTypeID($value)
    {
        $args = ['value' => $value, 'setError' => true];
        return $this->baseModelValidation(
            \Models\ThirdPartyManagement\Cases::class,
            $this->clientID,
            'validateCaseTypeID',
            $value
        );
    }


    /**
     * Validate Case Country Code input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg string
     */
    public function countryCode(mixed $value)
    {
        $args = ['value' => $value, 'setError' => true];
        return $this->baseModelValidation(
            \Models\ThirdPartyManagement\Cases::class,
            $this->clientID,
            'validateCountryCode',
            $args
        );
    }



    /**
     * Validate Current Prospective input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg array
     */
    public function currentProspective(mixed $value)
    {
        $args = ['value' => $value, 'setError' => true];
        return $this->baseModelValidation(
            'Models\ThirdPartyManagement\SubInfoDD',
            $this->clientID,
            'validateCountryCode',
            $args
        );
    }



    /**
     * Validate email address input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg string
     */
    public function email(mixed $value)
    {
        return $this->customValidation('validateEmail', $value);
    }



    /**
     * Validate phone number input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg string
     */
    public function phone(mixed $value)
    {
        return $this->customValidation('validatePhone', $value);
    }



    /**
     * Validate Percentage input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg string
     */
    public function percentage(mixed $value)
    {
        return $this->customValidation('validatePercentage', $value);
    }



    /**
     * Validate a third party for open cases
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg array
     */
    public function tpOpenCases(mixed $value)
    {
        $args = ['value' => $value, 'setError' => true];
        return $this->baseModelValidation(
            \Models\ThirdPartyManagement\ThirdParty::class,
            $this->clientID,
            'validateTpOpenCases',
            $args
        );
    }



    /**
     * Validate Third Party ID input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg array
     */
    public function tpID(mixed $value)
    {
        $args = ['value' => $value, 'setError' => true];
        $validated = $this->baseModelValidation(
            \Models\ThirdPartyManagement\ThirdParty::class,
            $this->clientID,
            'validateTpID',
            $args
        );
        if ($validated['result']) {
            return $this->tpOpenCases($value);
        }
        return $validated;
    }



    /**
     * Validate user-facing Third Party ID input
     *
     * @param mixed $value Either string or integer to be validated
     *
     * @return array $rtn contains result boolean and errMsg array
     */
    public function userTpNum(mixed $value)
    {
        $args = ['value' => $value, 'setError' => true, 'verifyExists' => true];
        $validated = $this->baseModelValidation(
            \Models\ThirdPartyManagement\ThirdParty::class,
            $this->clientID,
            'validateUserTpNum',
            $args
        );
        if ($validated['result']) {
            return $this->tpOpenCases($value);
        }
        return $validated;
    }
}
