<?php
/***********************************************
* File      :   imaplibray.php
* Project   :   Z-Push
* Descr     :   IMAP library abstract class
*
* Created   :   10.12.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

abstract class IMAPLibrary {
    //IMAP object/connection
    protected $imap;
    
    
    public abstract function AddSentMessage($folderid, $fullmessage);
    public abstract function ChangeFolder($folderid, $imapFolderid, $oldid, $displayname);
    public abstract function ChangeMessage($imapFolderid, $id, $message, $contentParameters);
    public abstract function ChangesSink($imapFolderid);
    public abstract function DeleteFolder($id, $parentid, $imapId, $imapParentId);
    public abstract function DeleteMessage($imapFolderid, $id, $contentParameters);
    public abstract function FetchMessage($folder, $id, $full = true);
    public abstract function GetAttachmentData($imapFolderid, $id, $part);
    public abstract function GetFolderList();
    public abstract function GetLastError();
    public abstract function GetMessage($folderid, $imapFolderid, $id, $contentparameters, $stat);
    public abstract function GetMessageList($imapFolderid, $cutoffdate);
    public abstract function GetModAndParentNames($fhir, &$displayname, &$parent);
    public abstract function GetServerDelimiter();
    public abstract function GetWasteBasket();
    public abstract function IsDriverFound();
    public abstract function Logoff();
    public abstract function Logon($server, $username, $domain, $password);
    public abstract function MoveMessage($imapFolderid, $id, $imapNewfolderid, $contentParameters);
    public abstract function Search($imapFolderid, $filter);
    public abstract function SetReadFlag($imapFolderid, $id, $flags, $contentParameters);
    public abstract function SetStarFlag($imapFolderid, $id, $flags, $contentParameters);
    public abstract function StatMessage($imapFolderid, $id);
    
    /**
     * Removes parenthesis (comments) from the date string because
     * strtotime returns false if received date has them
     *
     * @param string        $receiveddate   a date as a string
     *
     * @access protected
     * @return string
     */
    protected function cleanupDate($receiveddate) {
        $receiveddate = strtotime(preg_replace("/\(.*\)/", "", $receiveddate));
        if ($receiveddate == false || $receiveddate == -1) {
            debugLog("Received date is false. Message might be broken.");
            return null;
        }

        return $receiveddate;
    }
    
    /**
     * Parses the message and return only the plaintext body
     *
     * @param string        $message        html message
     *
     * @access protected
     * @return string       plaintext message
     */
    public function getBody($message) {
        $body = "";
        $htmlbody = "";

        $this->getBodyRecursive($message, "plain", $body);

        if($body === "") {
            $this->getBodyRecursive($message, "html", $body);
        }

        return $body;
    }

    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     *
     * @access protected
     * @return
     */
    protected function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }
}

?>