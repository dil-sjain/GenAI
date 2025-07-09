<?php

namespace Models\TPM\ThirdPartyMgt;

use Models\BaseLite\RequireClientID;
use Models\LogData;

/**
 * Provide simplified read/write access to tpNote records
 */
#[\AllowDynamicProperties]
class TpNoteLT extends RequireClientID
{
    // Required setup
    protected $tbl = 'tpNote';
    protected $tableInClientDB = true;

    /**
     * Create a new third party profile note and make an audit log entry
     *
     * @param integer $tpID      tpNote.tpID
     * @param integer $noteCatID tpNote.noteCatID
     * @param integer $ownerID   tpNote.ownerID
     * @param integer $created   tpNote.created
     * @param integer $subject   tpNote.subject
     * @param integer $note      tpNote.note
     *
     * @return mixed False boolean if error else integer
     */
    public function createNote($tpID, $noteCatID, $ownerID, $created, $subject, $note)
    {
        $rtn = false;
        if (!empty($tpID) && !empty($noteCatID) && !empty($created) && !empty($subject) && !empty($note)) {
            $data = [
                'tpID' => $tpID,
                'noteCatID' => $noteCatID,
                'ownerID' => $ownerID,
                'created' => $created,
                'subject' => $subject,
                'note' => $note
            ];
            if ($id = $this->insert($data)) {
                $logMsg = 'Subject: ' . $subject;
                $addNoteEvent = 29;
                (new LogData($this->clientID, $ownerID))->save3pLogEntry($addNoteEvent, $logMsg, $tpID);
                $rtn = $id;
            }
        }
        return $rtn;
    }

    /**
     * Create notes for a given tpID
     *
     * @param integer $tpID   Third Party Profile ID
     * @param array   $notes  Notes to create
     *
     * @return mixed False boolean if error else an array
     */
    public function createNotesByTpID(int $tpID, array $notes)
    {
        $rtn = false;
        $tpID = (int)$tpID;
        if (!empty($tpID) && !empty($notes)) {
            $noteIDs = [];
            foreach ($notes as $note) {
                if (!empty($note['noteCatID']) && !empty($note['created'])
                    && !empty($note['subject']) && !empty($note['note'])
                ) {
                    $noteID = $this->createNote(
                        $tpID,
                        $note['noteCatID'],
                        $note['ownerID'],
                        $note['created'],
                        $note['subject'],
                        $note['note']
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
     * For a given tpID, return a list of notes.
     *
     * @param integer $tpID    tpNote.tpID
     * @param array   $columns tpNote columns
     */
    public function getNotes($tpID, $columns)
    {
        $tpID = (int)$tpID;
        $rtn = [];
        if (!empty($tpID) && is_array($columns)) {
            if ($notes = $this->selectMultiple($columns, ['tpID' => $tpID], 'ORDER BY id DESC')) {
                $rtn = \Xtra::decodeAssocRowSet($notes);
            }
        }
        return $rtn;
    }
}
