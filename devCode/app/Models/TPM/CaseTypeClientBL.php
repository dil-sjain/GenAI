<?php
/**
 * BaseLite model on CaseTypeClient with clientID 0 fallback
 */

namespace Models\TPM;

/**
 * Read/write access to CaseTypeClient in TPM tenant dataases
 *
 * @keywords case type, case type client, fallback
 */
#[\AllowDynamicProperties]
class CaseTypeClientBL extends \Models\BaseLite\FallbackClient0
{
    /**
     * @const int Internal Review caseType
     */
    public const CASETYPE_INTERNAL = 50;
    public const CASETYPE_INTERNAL_NAME = 'lbl_InternalReview';
    public const CASETYPE_INTERNAL_ABBREV = 'lbl_Internal';

    protected $tbl = 'caseTypeClient';

    /**
     * Convenience method to get caseType list in id order with or without Internal Review appended
     *
     * @param boolean $addInternal Append Internal Review caseType to end of list
     *
     * @return array of caseType records
     */
    public function getRecords($addInternal = false)
    {
        $records = $this->selectMultiple(["caseTypeID AS 'id'", 'name', 'abbrev'], [], 'ORDER BY id', true);
        if ($addInternal) {
            $hasIR = false;
            foreach ($records as $rec) {
                if ($rec['id'] == self::CASETYPE_INTERNAL) {
                    $hasIR = true;
                    break;
                }
            }
            if (!$hasIR) {
                $trans = \Xtra::app()->trans;
                $name = $trans->codeKey(self::CASETYPE_INTERNAL_NAME);
                $abbrev = $trans->codeKey(self::CASETYPE_INTERNAL_ABBREV);
                $records[] = ['id' => self::CASETYPE_INTERNAL, 'name' => $name, 'abbrev' => $abbrev];
            }
        }
        return $records;
    }
}
