<?php
/***********************************************
* File      :   imapnative.php
* Project   :   Z-Push
* Descr     :   IMAP Native driver
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

class IMAPNative extends IMAPLibrary {
    private $mbox;
    private $mboxFolder;
    private $server;
    private $username;
    private $domain;
    private $serverdelimiter;
    
    /**
     * Check if the driver/library used is installed.
     *
     */
    public function IsDriverFound() {
        return function_exists("imap_open");
    }
    
    /**
     *
     *
     */
    public function Logon($server, $username, $domain, $password) {
        $this->server = $server;
        $this->mbox = @imap_open($server, $username, $password, OP_HALFOPEN);
        $this->mboxFolder = "";

        if ($this->mbox) {
            $this->username = $username;
            $this->domain = $domain;
            $list = @imap_getmailboxes($this->mbox, $server, "*");
            if (is_array($list)) {
                $val = $list[0];
                $this->serverdelimiter = $val->delimiter;
            }
            else {
                $this->serverdelimiter = ".";
            }
            unset($list);
            return true;
        }
        else {
            return false;
        }
    }
    
    
    public function Logoff() {
        if ($this->mbox) {
        // list all errors
        $errors = imap_errors();
        if (is_array($errors)) {
            foreach ($errors as $e) {
                if (stripos($e, "fail") !== false) {
                    $level = LOGLEVEL_WARN;
                }
                else {
                    $level = LOGLEVEL_DEBUG;
                }
                ZLog::Write($level, "IMAPNative->Logoff(): IMAP Server said: " . $e);
            }
        }
        @imap_close($this->mbox);
        unset($this->mbox);
    }
    
    public function GetWasteBasket() {
        $wastebaskt = @imap_getmailboxes($this->mbox, $this->server, "Trash");
        if (isset($wastebaskt[0])) {
            return $this->convertImapId(substr($wastebaskt[0]->name, strlen($this->server)));
        }
        return false;
    }
    
    public function GetAttachmentData($folderImapid, $id, $part) {
        $this->imap_reopenFolder($folderImapid);
        $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);
        
        //trying parts
        $mparts = $message->parts;
        for ($i = 0; $i < count($mparts); $i++) {
            $auxpart = $mparts[$i];
            //recursively add parts
            if($auxpart->ctype_primary == "multipart" && ($auxpart->ctype_secondary == "mixed" || $auxpart->ctype_secondary == "alternative"  || $auxpart->ctype_secondary == "related")) {
                foreach($auxpart->parts as $spart)
                    $mparts[] = $spart;
            }
        }
        
        $attpart = $mparts[$part];
        
        unset($message);
        unset($mparts);
        
        if (!isset($attpart->body))
            return false;

        include_once('include/stringstreamwrapper.php');
        $attachment = new SyncItemOperationsAttachment();
        $attachment->data = StringStreamWrapper::Open($attpart->body);
        if (isset($attpart->ctype_primary) && isset($attpart->ctype_secondary))
            $attachment->contenttype = $attpart->ctype_primary .'/'.$attpart->ctype_secondary;

        return $attachment;
    }
    
    public function ChangesSink($imapid) {
        $this->imap_reopenFolder($imapid);

        // courier-imap only cleares the status cache after checking
        @imap_check($this->mbox);

        $status = @imap_status($this->mbox, $this->server . $imapid, SA_ALL);
        if ($status) {
            return "M:" . $status->messages . "-R:" . $status->recent . "-U:" . $status->unseen;
        }
        return false;
    }
    
    
    public function GetFolderList() {
        $folders = false;

        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            // reverse list to obtain folders in right order
            $list = array_reverse($list);
            
            $folders = array();
            foreach ($list as $val) {
                $folders[] = array("name" => $val->name, "delimiter" => $val->delimiter);
            }
        }

        return $folders;
    }
    
    public function GetLastError() {
        return imap_last_error();
    }
    
    public function ChangeFolder($folderid, $imapFolderId, $oldid, $displayname){
        // go to parent mailbox
        $this->imap_reopenFolder($folderid);

        // build name for new mailboxBackendMaildir
        $newname = $this->server . $imapFolderId . $this->serverdelimiter . $displayname;

        $csts = false;
        // if $id is set => rename mailbox, otherwise create
        if ($oldid) {
            // rename doesn't work properly with IMAP
            // the activesync client doesn't support a 'changing ID'
            // TODO this would be solved by implementing hex ids (Mantis #459)
            //$csts = imap_renamemailbox($this->mbox, $this->server . imap_utf7_encode(str_replace(".", $this->serverdelimiter, $oldid)), $newname);
        }
        else {
            $csts = @imap_createmailbox($this->mbox, $newname);
        }
        
        if ($csts) {
            return $folderid . $this->serverdelimiter . $displayname;
        }
        else {
            return false;
        }
    }
    
    private function imap_reopenFolder($folderid, $force = false) {
        // to see changes, the folder has to be reopened!
        if ($this->mboxFolder != $folderid || $force) {
            $s = @imap_reopen($this->mbox, $this->server . $folderid);
                // TODO throw status exception
                if (!$s) {
                    ZLog::Write(LOGLEVEL_WARN, "BackendIMAP->imap_reopenFolder('%s'): failed to change folder: ",$folderid, implode(", ", imap_errors()));
                    return false;
                }
            $this->mboxFolder = $folderid;
        }
    }
}

?>