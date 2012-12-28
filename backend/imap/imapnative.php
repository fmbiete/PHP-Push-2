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
    }
    
    public function GetWasteBasket() {
        $wastebaskt = @imap_getmailboxes($this->mbox, $this->server, "Trash");
        if (isset($wastebaskt[0])) {
            return $this->convertImapId(substr($wastebaskt[0]->name, strlen($this->server)));
        }
        return false;
    }
    
    public function GetAttachmentData($imapFolderid, $id, $part) {
        $mail = $this->FetchMessage($imapFolderid, $id);
        if ($mail === false) {
            return false;
        }

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
    
    public function ChangesSink($imapFolderid) {
        $this->imap_reopenFolder($imapFolderid);

        // courier-imap only cleares the status cache after checking
        @imap_check($this->mbox);

        $status = @imap_status($this->mbox, $this->server . $imapFolderid, SA_ALL);
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
                $folders[] = array("name" => substr($val->name, strlen($this->server)), "delimiter" => $val->delimiter);
            }
        }

        return $folders;
    }
    
    public function GetLastError() {
        return imap_last_error();
    }
    
    public function ChangeFolder($folderid, $imapFolderid, $oldid, $displayname){
        // go to parent mailbox
        $this->imap_reopenFolder($imapFolderid);

        // build name for new mailboxBackendMaildir
        $newname = $this->server . $imapFolderid . $this->serverdelimiter . $displayname;

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
            // Return folderid, to use in StatsFolder
            return $folderid . $this->serverdelimiter . $displayname;
        }
        else {
            return false;
        }
    }
    
    public function DeleteFolder($id, $parentid, $imapId, $imapParentId) {
        //TODO: implement
        return false;
    }
    
    
    public function GetMessageList($imapFolderid, $cutoffdate) {
        $messages = array();
        $this->imap_reopenFolder($imapFolderid, true);

        $sequence = "1:*";
        if ($cutoffdate > 0) {
            $search = @imap_search($this->mbox, "SINCE ". date("d-M-Y", $cutoffdate));
            if ($search !== false)
                $sequence = implode(",", $search);
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("IMAPNative->GetMessageList(): searching with sequence '%s'", $sequence));
        $overviews = @imap_fetch_overview($this->mbox, $sequence);

        if (!$overviews || !is_array($overviews)) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("IMAPNative->GetMessageList('%s','%s'): Failed to retrieve overview: %s",$imapFolderid, $cutoffdate, imap_last_error()));
            return $messages;
        }

        foreach($overviews as $overview) {
            $date = "";
            $vars = get_object_vars($overview);
            if (array_key_exists( "date", $vars)) {
                // message is out of range for cutoffdate, ignore it
                if ($this->cleanupDate($overview->date) < $cutoffdate) continue;
                $date = $overview->date;
            }

            // cut of deleted messages
            if (array_key_exists("deleted", $vars) && $overview->deleted)
                continue;

            if (array_key_exists("uid", $vars)) {
                $message = array();
                $message["mod"] = $date;
                $message["id"] = $overview->uid;
                
                // 'seen' aka 'read'
                if(array_key_exists("seen", $vars) && $overview->seen) {
                    $message["flags"] = 1;
                }
                else {
                    $message["flags"] = 0;
                }
                
                // 'flagged' aka 'FollowUp' aka 'starred'
                if (array_key_exists("flagged", $vars) && $overview->flagged) {
                    $message["star"] = 1;
                }
                else {
                    $message["star"] = 0;
                }                

                array_push($messages, $message);
            }
        }
        return $messages;
    }


    public function GetMessage($folderid, $imapFolderid, $id, $contentparameters, $stat) {
        $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
        $mimesupport = $contentparameters->GetMimeSupport();
        $bodypreference = $contentparameters->GetBodyPreference();

        $mail = $this->FetchMessage($imapFolderid, $id);
        if ($mail === false) {
            return false;
        }

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));
        unset($mobj);

        $output = new SyncMail();

        //Select body type preference
        $bpReturnType = SYNC_BODYPREFERENCE_PLAIN;
        if ($bodypreference !== false) {
            $bpReturnType = Utils::GetBodyPreferenceBestMatch($bodypreference); // changed by mku ZP-330
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("IMAPNative->GetMessage - getBodyPreferenceBestMatch: %d", $bpReturnType));

        //Get body data
        $this->getBodyRecursive($message, "plain", $plainBody);
        $this->getBodyRecursive($message, "html", $htmlBody);
        if ($plainBody == "") {
            $plainBody = Utils::ConvertHtmlToText($htmlBody);
        }
        $htmlBody = str_replace("\n","\r\n", str_replace("\r","",$htmlBody));
        $plainBody = str_replace("\n","\r\n", str_replace("\r","",$plainBody));

        if (Request::GetProtocolVersion() >= 12.0) {
            $output->asbody = new SyncBaseBody();

            switch($bpReturnType) {
                case SYNC_BODYPREFERENCE_PLAIN:
                    $output->asbody->data = $plainBody;
                    break;
                case SYNC_BODYPREFERENCE_HTML:
                    if ($htmlBody == "") {
                        $output->asbody->data = $plainBody;
                        $bpReturnType = SYNC_BODYPREFERENCE_PLAIN;
                    }
                    else {
                        $output->asbody->data = $htmlBody;
                    }
                    break;
                case SYNC_BODYPREFERENCE_MIME:
                    //We don't need to create a new MIME mail, we already have one!!
                    $output->asbody->data = $mail;
                    break;
                case SYNC_BODYPREFERENCE_RTF:
                    ZLog::Write(LOGLEVEL_DEBUG, "IMAPNative->GetMessage RTF Format NOT CHECKED");
                    $output->asbody->data = base64_encode($plainBody);
                    break;
            }
            // truncate body, if requested
            if(strlen($output->asbody->data) > $truncsize) {
                $output->asbody->data = Utils::Utf8_truncate($output->asbody->data, $truncsize);
                $output->asbody->truncated = 1;
            }

            $output->asbody->type = $bpReturnType;
            $output->nativebodytype = $bpReturnType;
            $output->asbody->estimatedDataSize = strlen($output->asbody->data);

            $bpo = $contentparameters->BodyPreference($output->asbody->type);
            if (Request::GetProtocolVersion() >= 14.0 && $bpo->GetPreview()) {
                $output->asbody->preview = Utils::Utf8_truncate(Utils::ConvertHtmlToText($plainBody), $bpo->GetPreview());
            }
            else {
                $output->asbody->truncated = 0;
            }
        }
        else { // ASV_2.5
            $output->bodytruncated = 0;
            if ($bpReturnType == SYNC_BODYPREFERENCE_MIME) {
                if (strlen($mail) > $truncsize) {
                    $output->mimedata = Utils::Utf8_truncate($mail, $truncsize);
                    $output->mimetruncated = 1;
                }
                else {
                    $output->mimetruncated = 0;
                    $output->mimedata = $mail;
                }
                $output->mimesize = strlen($output->mimedata);
            }
            else {
                // truncate body, if requested
                if (strlen($plainBody) > $truncsize) {
                    $output->body = Utils::Utf8_truncate($plainBody, $truncsize);
                    $output->bodytruncated = 1;
                }
                else {
                    $output->body = $plainBody;
                    $output->bodytruncated = 0;
                }
                $output->bodysize = strlen($output->body);
            }
        }

        $output->datereceived = isset($message->headers["date"]) ? $this->cleanupDate($message->headers["date"]) : null;
        $output->messageclass = "IPM.Note";
        $output->subject = isset($message->headers["subject"]) ? $message->headers["subject"] : "";
        $output->read = $stat["flags"];
        $output->from = isset($message->headers["from"]) ? $message->headers["from"] : null;

        if (isset($message->headers["thread-topic"])) {
            $output->threadtopic = $message->headers["thread-topic"];
                /*
                //FIXME: Conversation support, get conversationid and conversationindex good values
                if (Request::GetProtocolVersion() >= 14.0) {
                    // since the conversationid must be unique for a thread we could use the threadtopic in base64 minus the ==
                    $output->conversationid = strtoupper(str_replace("=", "", base64_encode($output->threadtopic)));
                    if (isset($message->headers["thread-index"]))
                        $output->conversationindex = strtoupper($message->headers["thread-index"]);
                }
                */
        }

        // Language Code Page ID: http://msdn.microsoft.com/en-us/library/windows/desktop/dd317756%28v=vs.85%29.aspx
        $output->internetcpid = INTERNET_CPID_UTF8;
        if (Request::GetProtocolVersion() >= 12.0) {
            $output->contentclass = "urn:content-classes:message";

            $output->flag = new SyncMailFlags();
            if (isset($stat["star"]) && $stat["star"]) {
                //flagstatus 0: clear, 1: complete, 2: active
                $output->flag->flagstatus = SYNC_FLAGSTATUS_ACTIVE;
                //flagtype: for follow up
                $output->flag->flagtype = "FollowUp";                    
            }
            else {
                $output->flag->flagstatus = SYNC_FLAGSTATUS_CLEAR;
            }
        }

        $Mail_RFC822 = new Mail_RFC822();
        $toaddr = $ccaddr = $replytoaddr = array();
        if(isset($message->headers["to"]))
            $toaddr = $Mail_RFC822->parseAddressList($message->headers["to"]);
        if(isset($message->headers["cc"]))
            $ccaddr = $Mail_RFC822->parseAddressList($message->headers["cc"]);
        if(isset($message->headers["reply_to"]))
            $replytoaddr = $Mail_RFC822->parseAddressList($message->headers["reply_to"]);

        $output->to = array();
        $output->cc = array();
        $output->reply_to = array();
        foreach(array("to" => $toaddr, "cc" => $ccaddr, "reply_to" => $replytoaddr) as $type => $addrlist) {
            foreach($addrlist as $addr) {
                $address = $addr->mailbox . "@" . $addr->host;
                $name = $addr->personal;

                if (!isset($output->displayto) && $name != "") {
                    $output->displayto = $name;
                }

                if($name == "" || $name == $address) {
                    $fulladdr = w2u($address);
                }
                else {
                    if (substr($name, 0, 1) != '"' && substr($name, -1) != '"') {
                        $fulladdr = "\"" . w2u($name) ."\" <" . w2u($address) . ">";
                    }
                    else {
                        $fulladdr = w2u($name) ." <" . w2u($address) . ">";
                    }
                }

                array_push($output->$type, $fulladdr);
            }
        }

        // convert mime-importance to AS-importance
        if (isset($message->headers["x-priority"])) {
            $mimeImportance =  preg_replace("/\D+/", "", $message->headers["x-priority"]);
            //MAIL 1 - most important, 3 - normal, 5 - lowest
            //AS 0 - low, 1 - normal, 2 - important
            if ($mimeImportance > 3) {
                $output->importance = 0;
            }
            else if ($mimeImportance == 3) {
                $output->importance = 1;
            }
            else if ($mimeImportance < 3) {
                $output->importance = 2;
            }
        } else { /* fmbiete's contribution r1528, ZP-320 */
                $output->importance = 1;
        }

        // Attachments are not needed for MIME messages
        if($bpReturnType != SYNC_BODYPREFERENCE_MIME && isset($message->parts)) {
            $mparts = $message->parts;
            for ($i=0; $i<count($mparts); $i++) {
                $part = $mparts[$i];
                //recursively add parts
                if($part->ctype_primary == "multipart" && ($part->ctype_secondary == "mixed" || $part->ctype_secondary == "alternative"  || $part->ctype_secondary == "related")) {
                    foreach($part->parts as $spart) {
                        $mparts[] = $spart;
                    }
                    continue;
                }
                //add part as attachment if it's disposition indicates so or if it is not a text part
                if ((isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) ||
                    (isset($part->ctype_primary) && $part->ctype_primary != "text")) {

                    if(isset($part->d_parameters['filename'])) {
                        $attname = $part->d_parameters['filename'];
                    }
                    else if(isset($part->ctype_parameters['name'])) {
                        $attname = $part->ctype_parameters['name'];
                    }
                    else if(isset($part->headers['content-description'])) {
                        $attname = $part->headers['content-description'];
                    }
                    else {
                        $attname = "unknown attachment";
                    }

                    /* BEGIN fmbiete's contribution r1528, ZP-320 */
                    if (Request::GetProtocolVersion() >= 12.0) {
                        if (!isset($output->asattachments) || !is_array($output->asattachments)) {
                            $output->asattachments = array();
                        }

                        $attachment = new SyncBaseAttachment();

                        $attachment->estimatedDataSize = isset($part->d_parameters['size']) ? $part->d_parameters['size'] : isset($part->body) ? strlen($part->body) : 0;

                        $attachment->displayname = $attname;
                        $attachment->filereference = $folderid . ":" . $id . ":" . $i;
                        $attachment->method = 1; //Normal attachment
                        $attachment->contentid = isset($part->headers['content-id']) ? str_replace("<", "", str_replace(">", "", $part->headers['content-id'])) : "";
                        if (isset($part->disposition) && $part->disposition == "inline") {
                            $attachment->isinline = 1;
                        }
                        else {
                            $attachment->isinline = 0;
                        }

                        array_push($output->asattachments, $attachment);
                    }
                    else { //ASV_2.5
                        if (!isset($output->attachments) || !is_array($output->attachments)) {
                            $output->attachments = array();
                        }

                        $attachment = new SyncAttachment();

                        $attachment->attsize = isset($part->d_parameters['size']) ? $part->d_parameters['size'] : isset($part->body) ? strlen($part->body) : 0;

                        $attachment->displayname = $attname;
                        $attachment->attname = $folderid . ":" . $id . ":" . $i;
                        $attachment->attmethod = 1;
                        $attachment->attoid = isset($part->headers['content-id']) ? str_replace("<", "", str_replace(">", "", $part->headers['content-id'])) : "";

                        array_push($output->attachments, $attachment);
                    }
                }
            }
        }
        
        unset($mail);
        
        return $output;
    }
    
    public function GetServerDelimiter() {
        return $this->serverdelimiter;
    }
    
    public function StatMessage($imapFolderid, $id) {
        $overview = $this->FetchMessage($imapFolderid, $id, false);
        if (!$overview) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->StatMessage('%s','%s'): Failed to retrieve overview: %s", $imapFolderid,  $id, imap_last_error()));
            return false;
        }

        // check if variables for this overview object are available
        $vars = get_object_vars($overview[0]);

        // without uid it's not a valid message
        if (! array_key_exists( "uid", $vars)) return false;

        $entry = array();
        $entry["mod"] = (array_key_exists( "date", $vars)) ? $overview[0]->date : "";
        $entry["id"] = $overview[0]->uid;
        
        // 'seen' aka 'read'
        if (array_key_exists("seen", $vars) && $overview[0]->seen) {
            $entry["flags"] = 1;
        }
        else {
            $entry["flags"] = 0;
        }

        // 'flagged' aka 'FollowUp' aka 'starred'
        if (array_key_exists("flagged", $vars) && $overview[0]->flagged) {
            $entry["star"] = 1;
        }
        else {
            $entry["star"] = 0;
        }

        return $entry;
    }
    
    public function ChangeMessage($imapFolderid, $id, $message, $contentParameters) {
        // TODO this could throw several StatusExceptions like e.g. SYNC_STATUS_OBJECTNOTFOUND, SYNC_STATUS_SYNCCANNOTBECOMPLETED

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before changing the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_SYNCCANNOTBECOMPLETED should be thrown

        if (isset($message->flag)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangeMessage('Setting flag')"));

            $this->imap_reopenFolder($imapFolderid);

            if (isset($message->flag->flagstatus) && $message->flag->flagstatus == 2) {
                ZLog::Write(LOGLEVEL_DEBUG, "Set On FollowUp -> IMAP Flagged");
                $status = @imap_setflag_full($this->mbox, $id, "\\Flagged",ST_UID);
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "Clearing Flagged");
                $status = @imap_clearflag_full ( $this->mbox, $id, "\\Flagged", ST_UID);
            }

            if ($status) {
                ZLog::Write(LOGLEVEL_DEBUG, "Flagged changed");
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "Flagged failed");
            }
        }
    }

    public function SetReadFlag($imapFolderid, $id, $flags, $contentParameters) {
        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before setting the read flag, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown

        $this->imap_reopenFolder($imapFolderid);

        if ($flags == 0) {
            // set as "Unseen" (unread)
            $status = @imap_clearflag_full ( $this->mbox, $id, "\\Seen", ST_UID);
        } else {
            // set as "Seen" (read)
            $status = @imap_setflag_full($this->mbox, $id, "\\Seen",ST_UID);
        }

        return $status;
    }
    
    public function SetStarFlag($imapFolderid, $id, $flags, $contentParameters) {
        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before setting the read flag, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown

        $this->imap_reopenFolder($imapFolderid);

        if ($flags == 0) {
            // set as "UnFlagged" (unstarred)
            $status = @imap_clearflag_full ( $this->mbox, $id, "\\Flagged", ST_UID);
        } else {
            // set as "Flagged" (starred)
            $status = @imap_setflag_full($this->mbox, $id, "\\Flagged",ST_UID);
        }

        return $status;
    }
    
    public function DeleteMessage($imapFolderid, $id, $contentParameters) {
        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before deleting the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown

        $this->imap_reopenFolder($imapFolderid);
        $s1 = @imap_delete ($this->mbox, $id, FT_UID);
        $s11 = @imap_setflag_full($this->mbox, $id, "\\Deleted", FT_UID);
        $s2 = @imap_expunge($this->mbox);

        return ($s1 && $s2 && $s11);
    }
    
    public function MoveMessage($imapFolderid, $id, $imapNewfolderid, $contentParameters) {
        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before moving the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID should be thrown

        // TODO this should throw a StatusExceptions on errors like SYNC_MOVEITEMSSTATUS_SAMESOURCEANDDEST,SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID,SYNC_MOVEITEMSSTATUS_CANNOTMOVE

        // read message flags
        $overview = $this->FetchMessage($imapFolderid, $id, false);
        if (!$overview) {
            throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, unable to retrieve overview of source message: %s", $imapFolderid, $id, $imapNewfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
        }
        else {
            // get next UID for destination folder
            // when moving a message we have to announce through ActiveSync the new messageID in the
            // destination folder. This is a "guessing" mechanism as IMAP does not inform that value.
            // when lots of simultaneous operations happen in the destination folder this could fail.
            // in the worst case the moved message is displayed twice on the mobile.
            $destStatus = @imap_status($this->mbox, $this->server . $imapNewfolderid, SA_ALL);
            if (!$destStatus) {
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, unable to open destination folder: %s", $imapFolderid, $id, $imapNewfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);
            }

            $newid = $destStatus->uidnext;

            // move message
            $s1 = @imap_mail_move($this->mbox, $id, $imapNewfolderid, CP_UID);
            if (!$s1) {
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, copy to destination folder failed: %s", $imapFolderid, $id, $imapNewfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);
            }

            // delete message in from-folder
            $s2 = @imap_expunge($this->mbox);

            // open new folder
            $s1 = $this->imap_reopenFolder($imapNewfolderid);
            if (!$s1) {
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, opening the destination folder: %s", $imapFolderid, $id, $imapNewfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);
            }

            // remove all flags
            $s3 = @imap_clearflag_full ($this->mbox, $newid, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
            $newflags = "";
            if ($overview[0]->seen) $newflags .= "\\Seen";
            if ($overview[0]->flagged) $newflags .= " \\Flagged";
            if ($overview[0]->answered) $newflags .= " \\Answered";
            $s4 = @imap_setflag_full ($this->mbox, $newid, $newflags, FT_UID);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): result s-move: '%s' s-expunge: '%s' unset-Flags: '%s' set-Flags: '%s'", $imapFolderid, $id, $imapNewfolderid, Utils::PrintAsString($s1), Utils::PrintAsString($s2), Utils::PrintAsString($s3), Utils::PrintAsString($s4)));

            // return the new id "as string""
            return $newid . "";
        }
    }
    
    public function Search($imapFolderid, $filter) {
        if ($this->imap_reopenFolder($imapFolderid)) {
            return @imap_search($this->mbox, $filter, SE_UID, "UTF-8");
        }
    }
    
    public function FetchMessage($folder, $id, $full = true) {
        if ($this->imap_reopenFolder($folder)) {
            if ($full) {
                // receive entire mail (header + body) to decode body correctly
                return @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);
            }
            else {
                return @imap_fetch_overview($this->mbox, $id, FT_UID);
            }
        }
        return false;
    }


    /**
     * Recursive way to get mod and parent - repeat until only one part is left
     * or the folder is identified as an IMAP folder
     *
     * @param string        $fhir           folder hierarchy string
     * @param string        &$displayname   reference of the displayname
     * @param long          &$parent        reference of the parent folder
     *
     * @access protected
     * @return
     */
    public function GetModAndParentNames($fhir, &$displayname, &$parent) {
        // if mod is already set add the previous part to it as it might be a folder which has
        // delimiter in its name
        $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir).$this->serverdelimiter.$displayname : array_pop($fhir);
        $parent = implode($this->serverdelimiter, $fhir);

        if (count($fhir) == 1 || $this->checkIfIMAPFolder($parent)) {
            return;
        }
        //recursion magic
        $this->GetModAndParentNames($fhir, $displayname, $parent);
    }
    
    public function AddSentMessage($folderid, $fullmessage) {
        return @imap_append($this->mbox, $this->server . $folderid, $fullmessage, "\\Seen");
    }

    /**
     * Checks if a specified name is a folder in the IMAP store
     *
     * @param string        $foldername     a foldername
     *
     * @access protected
     * @return boolean
     */
    private function checkIfIMAPFolder($folderName) {
        $parent = @imap_list($this->mbox, $this->server, $folderName);
        if ($parent === false) return false;
        return true;
    }
    
    /**
     * Helper to re-initialize the folder to speed things up
     * Remember what folder is currently open and only change if necessary
     *
     * @param string        $folderid       id of the folder
     * @param boolean       $force          re-open the folder even if currently opened
     *
     * @access protected
     * @return
     */
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
        return true;
    }
}

?>