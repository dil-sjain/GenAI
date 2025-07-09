<?php
/**
 * BaseLite access to client cases table
 */

namespace Models\TPM\CaseMgt;

use Lib\Legacy\CaseStage;

#[\AllowDynamicProperties]
class SubjectInfoDdLT extends \Models\BaseLite\RequireClientID
{
    /**
     * @var string Table name
     */
    protected $tbl = 'subjectInfoDD';

    /**
     * @var bool In client DB
     */
    protected $tableInClientDB = true;
}
