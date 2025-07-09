<?php

namespace Models\TPM\CaseMgt;

use Models\BaseLite\RequireClientID;
use Models\LogData;

/**
 * Provide simplified read/write access to caseNote records
 */
class CaseNoteLT extends RequireClientID
{
    // Required setup
    protected $tbl = 'caseNote';
    protected $tableInClientDB = true;

    /**
     * Create a new case note and make an audit log entry
     *
     * @param integer $tpID                tpNote.tpID
     * @param integer $noteCatID           tpNote.noteCatID
     * @param integer $ownerID             tpNote.ownerID
     * @param string  $created             tpNote.created
     * @param mixed    $subject             tpNote.subject
     * @param mixed   $note                tpNote.note
     * @param boolean $bInvestigator       tpNote.bInvestigator
     * @param boolean $bInvestigatorCanSee tpNote.bInvestigatorCanSee
     * @param integer $qID                 tpNote.qID
     * @param string  $nSect               tpNote.nSect
     *
     * @return mixed False boolean if error else integer
     */
    public function createNote(
        $caseID,
        $noteCatID,
        $ownerID,
        $created,
        mixed $subject,
        mixed $note,
        $bInvestigator,
        $bInvestigatorCanSee,
        $qID,
        $nSect
    ) {
        $rtn = false;
        if (!empty($caseID) && !empty($noteCatID) && !empty($created) && !empty($subject)
            && !empty($note) && isset($bInvestigator) && isset($bInvestigatorCanSee) && isset($qID) && isset($nSect)
        ) {
            $data = [
                'caseID' => $caseID,
                'noteCatID' => $noteCatID,
                'ownerID' => $ownerID,
                'created' => $created,
                'subject' => $subject,
                'note' => $note,
                'bInvestigator' => $bInvestigator,
                'bInvestigatorCanSee' => $bInvestigatorCanSee,
                'qID' => $qID,
                'nSect' => $nSect
            ];
            if ($id = $this->insert($data)) {
                $logMsg = 'Subject: ' . $subject;
                $addNoteEvent = 29;
                (new LogData($this->clientID, $ownerID))->saveLogEntry($addNoteEvent, $logMsg, $caseID);
                $rtn = $id;
            }
        }
        return $rtn;
    }

    /**
     * Create notes for a given caseID
     *
     * @param integer $caseID cases.id
     * @param array   $notes  Notes to create
     *
     * @return mixed False boolean if error else an array
     */
    public function createNotesByCaseID($caseID, $notes)
    {
        $rtn = false;
        $caseID = (int)$caseID;
        if (!empty($caseID) && !empty($notes)) {
            $noteIDs = [];
            foreach ($notes as $note) {
                if (!empty($note['noteCatID']) && !empty($note['created'])
                    && !empty($note['subject']) && !empty($note['note']) && isset($note['bInvestigator'])
                    && isset($note['bInvestigatorCanSee']) && isset($note['qID']) && isset($note['nSect'])
                ) {
                    $noteID = $this->createNote(
                        $caseID,
                        $note['noteCatID'],
                        $note['ownerID'],
                        $note['created'],
                        $note['subject'],
                        $note['note'],
                        $note['bInvestigator'],
                        $note['bInvestigatorCanSee'],
                        $note['qID'],
                        $note['nSect']
                    );
                    if ($noteID) {
                        $noteIDs[] = $noteID;
                    }
                }
            }
            if (!empty($noteIDs)) {
                $rtn = $noteIDs;
            }
        }
        return $rtn;
    }

    /**
     * For a given caseID, return a list of notes.
     *
     * @param integer $caseID  caseNote.caseID
     * @param array   $columns caseNote columns
     */
    public function getNotes($caseID, $columns)
    {
        $caseID = (int)$caseID;
        $rtn = [];
        if (!empty($caseID) && is_array($columns)) {
            if ($notes = $this->selectMultiple($columns, ['caseID' => $caseID], 'ORDER BY id DESC')) {
                $rtn = \Xtra::decodeAssocRowSet($notes);
            }
        }
        return $rtn;
    }
}
