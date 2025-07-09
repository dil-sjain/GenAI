<?php
/**
 * Provide SQL shortcuts for onlineQuestions
 */

namespace Models\TPM\IntakeForms\Legacy;

use Models\BaseLite\RequireClientID;

#[\AllowDynamicProperties]
class OnlineQuestionsBL extends RequireClientID
{
    /**
     * @var string Table name
     */
    protected $tbl = 'onlineQuestions';

    /**
     * @var bool Table is in client database
     */
    protected $tableInClientDB = true;
}
