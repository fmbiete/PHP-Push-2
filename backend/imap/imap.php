<?php
/***********************************************
* File      :   imap.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               IMAP interface
*
* Created   :   10.10.2007
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

// config file
require_once("backend/imap/config.php");

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/mimeDecode.php');
require_once('include/z_RFC822.php');


class BackendIMAP extends BackendDiff implements ISearchProvider {
    protected $wasteID;
    protected $sentID;
    protected $sinkfolders;
    protected $sinkstates;
    protected $excludedFolders; /* fmbiete's contribution r1527, ZP-319 */
    protected $imapLib;

    public function BackendIMAP() {
        $this->wasteID = false;
        $this->sentID = false;
        $this->mboxFolder = "";
        
        if (defined('IMAP_LIBRARY')) {
            switch (IMAP_LIBRARY) {
                case 'IMAPNative':
                case '';
                    $this->imapLib = new IMAPNative();
                    break;
                case 'IMAPHorde':
                    $this->imapLib = new IMAPHorde();
                    break;
                default:
                    throw new FatalException(sprintf("BackendIMAP(): Driver IMAP unknown: %s", IMAP_LIBRARY), 0, null, LOGLEVEL_FATAL);
                    break;
            }
        }
        else {
            $this->imapLib = new IMAPNative();
        }

        if (!$this->imapLib->IsDriverFound()) {
            throw new FatalException("BackendIMAP(): Driver IMAP is not installed", 0, null, LOGLEVEL_FATAL);
        }
    }

    /**----------------------------------------------------------------------------------------------------------
     * default backend methods
     */

    /**
     * Authenticates the user
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     * @throws FatalException   if php-imap module can not be found
     */
    public function Logon($username, $domain, $password) {
        $this->wasteID = false;
        $this->sentID = false;

        /* BEGIN fmbiete's contribution r1527, ZP-319 */
        $this->excludedFolders = array();
        if (defined('IMAP_EXCLUDED_FOLDERS')) {
            $this->excludedFolders = explode("|", IMAP_EXCLUDED_FOLDERS);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->Logon(): Excluding Folders (%s)", IMAP_EXCLUDED_FOLDERS));
        }
        /* END fmbiete's contribution r1527, ZP-319 */

        if ($this->imapLib->Logon("{" . IMAP_SERVER . ":" . IMAP_PORT . "/imap" . IMAP_OPTIONS . "}", $username, $domain, $password)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->Logon(): User '%s' is authenticated on IMAP",$username));
            return true;
        }
        else {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendIMAP->Logon(): can't connect as user %s: %s", $username, $this->imapLib->GetLastError()));
            return false;
        }
    }

    /**
     * Logs off
     * Called before shutting down the request to close the IMAP connection
     * writes errors to the log
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        $this->imapLib->Logoff();
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->Logoff(): IMAP connection closed");
        $this->SaveStorages();
    }

    /**
     * Sends an e-mail
     * This messages needs to be saved into the 'sent items' folder
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        $forward = $reply = (isset($sm->source->itemid) && $sm->source->itemid) ? $sm->source->itemid : false;
        $parent = false;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("IMAPBackend->SendMail(): RFC822: %d bytes  forward-id: '%s' reply-id: '%s' parent-id: '%s' SaveInSent: '%s' ReplaceMIME: '%s'",
                                            strlen($sm->mime), Utils::PrintAsString($sm->forwardflag), Utils::PrintAsString($sm->replyflag),
                                            Utils::PrintAsString((isset($sm->source->folderid) ? $sm->source->folderid : false)),
                                            Utils::PrintAsString(($sm->saveinsent)), Utils::PrintAsString(isset($sm->replacemime)) ));

        if (isset($sm->source->folderid) && $sm->source->folderid) {
            // convert parent folder id back to work on an imap-id
            $parent = $this->getImapIdFromFolderId($sm->source->folderid);
        }

        // by splitting the message in several lines we can easily grep later
        foreach(preg_split("/((\r)?\n)/", $sm->mime) as $rfc822line) {
            ZLog::Write(LOGLEVEL_WBXML, "RFC822: ". $rfc822line);
        }

        $mobj = new Mail_mimeDecode($sm->mime);
        $message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        $Mail_RFC822 = new Mail_RFC822();
        $toaddr = $ccaddr = $bccaddr = "";
        if(isset($message->headers["to"]))
            $toaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["to"]));
        if(isset($message->headers["cc"]))
            $ccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["cc"]));
        if(isset($message->headers["bcc"]))
            $bccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["bcc"]));

        // save some headers when forwarding mails (content type & transfer-encoding)
        $headers = "";
        $forward_h_ct = "";
        $forward_h_cte = "";
        $envelopefrom = "";

        $use_orgbody = false;

        // clean up the transmitted headers
        // remove default headers because we are using imap_mail
        $changedfrom = false;
        $returnPathSet = false;
        $body_base64 = false;
        $org_charset = "";
        $org_boundary = false;
        $multipartmixed = false;
        foreach($message->headers as $k => $v) {
            if ($k == "subject" || $k == "to" || $k == "cc" || $k == "bcc")
                continue;

            if ($k == "content-type") {
                // if the message is a multipart message, then we should use the sent body
                if (preg_match("/multipart/i", $v)) {
                    $use_orgbody = true;
                    $org_boundary = $message->ctype_parameters["boundary"];
                }

                // save the original content-type header for the body part when forwarding
                if ($sm->forwardflag && !$use_orgbody) {
                    $forward_h_ct = $v;
                    continue;
                }

                // set charset always to utf-8
                $org_charset = $v;
                $v = preg_replace("/charset=([A-Za-z0-9-\"']+)/", "charset=\"utf-8\"", $v);
            }

            if ($k == "content-transfer-encoding") {
                // if the content was base64 encoded, encode the body again when sending
                if (trim($v) == "base64") $body_base64 = true;

                // save the original encoding header for the body part when forwarding
                if ($sm->forwardflag) {
                    $forward_h_cte = $v;
                    continue;
                }
            }

            // check if "from"-header is set, do nothing if it's set
            // else set it to IMAP_DEFAULTFROM
            if ($k == "from") {
                if (trim($v)) {
                    $changedfrom = true;
                } elseif (! trim($v) && IMAP_DEFAULTFROM) {
                    $changedfrom = true;
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
                    else $v = $this->username . IMAP_DEFAULTFROM;
                    $envelopefrom = "-f$v";
                }
            }

            // check if "Return-Path"-header is set
            if ($k == "return-path") {
                $returnPathSet = true;
                if (! trim($v) && IMAP_DEFAULTFROM) {
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
                    else $v = $this->username . IMAP_DEFAULTFROM;
                }
            }

            // all other headers stay
            if ($headers) $headers .= "\n";
            $headers .= ucfirst($k) . ": ". $v;
        }

        // set "From" header if not set on the device
        if(IMAP_DEFAULTFROM && !$changedfrom){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
            else $v = $this->username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'From: '.$v;
            $envelopefrom = "-f$v";
        }

        // set "Return-Path" header if not set on the device
        if(IMAP_DEFAULTFROM && !$returnPathSet){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
            else $v = $this->username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'Return-Path: '.$v;
        }

        // if this is a multipart message with a boundary, we must use the original body
        if ($use_orgbody) {
            list(,$body) = $mobj->_splitBodyHeader($sm->mime);
            $repl_body = $this->imapLib->getBody($message);
        }
        else {
            $body = $this->imapLib->getBody($message);
        }

        // reply
        if ($sm->replyflag && $parent) {
            $origmail = $this->imapLib->FetchMessage($parent, $reply, true);
            if (!$origmail)
                throw new StatusException(sprintf("BackendIMAP->SendMail(): Could not open message id '%s' in folder id '%s' to be replied: %s", $reply, $parent, $this->imapLib->GetLastError()), SYNC_COMMONSTATUS_ITEMNOTFOUND);

            $mobj2 = new Mail_mimeDecode($origmail);
            // receive only body
            $body .= $this->imapLib->getBody($mobj2->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8')));
            // unset mimedecoder & origmail - free memory
            unset($mobj2);
            unset($origmail);
        }

        // encode the body to base64 if it was sent originally in base64 by the pda
        // contrib - chunk base64 encoded body
        if ($body_base64 && !$sm->forwardflag) {
            $body = chunk_split(base64_encode($body));
        }

        // forward
        if ($sm->forwardflag && $parent) {
            $origmail = $this->imapLib->FetchMessage($parent, $forward);
            if (!$origmail)
                throw new StatusException(sprintf("BackendIMAP->SendMail(): Could not open message id '%s' in folder id '%s' to be forwarded: %s", $forward, $parent, $this->imapLib->GetLastError()), SYNC_COMMONSTATUS_ITEMNOTFOUND);

            if (!defined('IMAP_INLINE_FORWARD') || IMAP_INLINE_FORWARD === false) {
                // contrib - chunk base64 encoded body
                if ($body_base64) {
                    $body = chunk_split(base64_encode($body));
                }
                //use original boundary if it's set
                $boundary = ($org_boundary) ? $org_boundary : false;
                // build a new mime message, forward entire old mail as file
                list($aheader, $body) = $this->mail_attach("forwarded_message.eml",strlen($origmail),$origmail, $body, $forward_h_ct, $forward_h_cte,$boundary);
                // add boundary headers
                $headers .= "\n" . $aheader;
            }
            else {
                $mobj2 = new Mail_mimeDecode($origmail);
                $mess2 = $mobj2->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

                if (!$use_orgbody) {
                    $nbody = $body;
                }
                else {
                    $nbody = $repl_body;
                }

                $nbody .= "\r\n\r\n";
                $nbody .= "-----Original Message-----\r\n";
                if (isset($mess2->headers['from'])) {
                    $nbody .= "From: " . $mess2->headers['from'] . "\r\n";
                }
                if (isset($mess2->headers['to']) && strlen($mess2->headers['to']) > 0) {
                    $nbody .= "To: " . $mess2->headers['to'] . "\r\n";
                }
                if (isset($mess2->headers['cc']) && strlen($mess2->headers['cc']) > 0) {
                    $nbody .= "Cc: " . $mess2->headers['cc'] . "\r\n";
                }
                if (isset($mess2->headers['date'])) {
                    $nbody .= "Sent: " . $mess2->headers['date'] . "\r\n";
                }
                if (isset($mess2->headers['subject'])) {
                    $nbody .= "Subject: " . $mess2->headers['subject'] . "\r\n";
                }
                $nbody .= "\r\n";
                $nbody .= $this->imapLib->getBody($mess2);

                if ($body_base64) {
                    // contrib - chunk base64 encoded body
                    $nbody = chunk_split(base64_encode($nbody));
                    if ($use_orgbody) {
                    // contrib - chunk base64 encoded body
                        $repl_body = chunk_split(base64_encode($repl_body));
                    }
                }

                if ($use_orgbody) {
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): -------------------");
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): old:\n'$repl_body'\nnew:\n'$nbody'\nund der body:\n'$body'");
                    //$body is quoted-printable encoded while $repl_body and $nbody are plain text,
                    //so we need to decode $body in order replace to take place
                    $body = str_replace($repl_body, $nbody, quoted_printable_decode($body));
                }
                else
                    $body = $nbody;


                if(isset($mess2->parts)) {
                    $attached = false;

                    if ($org_boundary) {
                        $att_boundary = $org_boundary;
                        // cut end boundary from body
                        $body = substr($body, 0, strrpos($body, "--$att_boundary--"));
                    }
                    else {
                        $att_boundary = strtoupper(md5(uniqid(time())));
                        // add boundary headers
                        $headers .= "\n" . "Content-Type: multipart/mixed; boundary=$att_boundary";
                        $multipartmixed = true;
                    }

                    foreach($mess2->parts as $part) {
                        if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {

                            if (isset($part->d_parameters['filename'])) {
                                $attname = $part->d_parameters['filename'];
                            }
                            else if (isset($part->ctype_parameters['name'])) {
                                $attname = $part->ctype_parameters['name'];
                            }
                            else if (isset($part->headers['content-description'])) {
                                $attname = $part->headers['content-description'];
                            }
                            else $attname = "unknown attachment";

                            // ignore html content
                            if ($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
                                continue;
                            }
                            //
                            if ($use_orgbody || $attached) {
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                            // first attachment
                            else {
                                $encmail = $body;
                                $attached = true;
                                $body = $this->enc_multipart($att_boundary, $body, $forward_h_ct, $forward_h_cte);
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                        }
                    }
                    if ($multipartmixed && strpos(strtolower($mess2->headers['content-type']), "alternative") !== false) {
                        //this happens if a multipart/alternative message is forwarded
                        //then it's a multipart/mixed message which consists of:
                        //1. text/plain part which was written on the mobile
                        //2. multipart/alternative part which is the original message
                        $body = "This is a message with multiple parts in MIME format.\n--".
                                $att_boundary.
                                "\nContent-Type: $forward_h_ct\nContent-Transfer-Encoding: $forward_h_cte\n\n".
                                (($body_base64) ? chunk_split(base64_encode($message->body)) : rtrim($message->body)).
                                "\n--".$att_boundary.
                                "\nContent-Type: {$mess2->headers['content-type']}\n\n".
                                @imap_body($this->mbox, $forward, FT_PEEK | FT_UID)."\n\n";
                    }
                    $body .= "--$att_boundary--\n\n";
                }

                unset($mobj2);
            }

            // unset origmail - free memory
            unset($origmail);
        }

        // remove carriage-returns from body
        $body = str_replace("\r\n", "\n", $body);

        if (!$multipartmixed) {
            if (!empty($forward_h_ct)) $headers .= "\nContent-Type: $forward_h_ct";
            if (!empty($forward_h_cte)) $headers .= "\nContent-Transfer-Encoding: $forward_h_cte";
        //  if body was quoted-printable, convert it again
            if (isset($message->headers["content-transfer-encoding"]) && strtolower($message->headers["content-transfer-encoding"]) == "quoted-printable") {
                $body = quoted_printable_encode($body);
            }
        }

        // more debugging
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): parsed message: ". print_r($message,1));
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): headers: $headers");
        /* BEGIN fmbiete's contribution r1528, ZP-320 */
        if (isset($message->headers["subject"])) {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): subject: {$message->headers["subject"]}");
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): subject: no subject set. Set to empty.");
            $message->headers["subject"] = ""; // added by mku ZP-330
        }
        /* END fmbiete's contribution r1528, ZP-320 */
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): body: $body");

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            // changed by mku ZP-330
            $send =  @imap_mail ( $toaddr, $message->headers["subject"], $body, $headers, $ccaddr, $bccaddr);
        }
        else {
            if (!empty($ccaddr)) {
                $headers .= "\nCc: $ccaddr";
            }
            if (!empty($bccaddr)) {
                $headers .= "\nBcc: $bccaddr";
            }
            // changed by mku ZP-330
            $send =  @mail( $toaddr, $message->headers["subject"], $body, $headers, $envelopefrom );
        }

        // email sent?
        if (!$send)
            throw new StatusException(sprintf("BackendIMAP->SendMail(): The email could not be sent. Last IMAP-error: %s", imap_last_error()), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);

        // add message to the sent folder
        // build complete headers
        $headers .= "\nTo: $toaddr";
        $headers .= "\nSubject: " . $message->headers["subject"]; // changed by mku ZP-330

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            if (!empty($ccaddr)) {
                $headers .= "\nCc: $ccaddr";
            }
            if (!empty($bccaddr)) {
                $headers .= "\nBcc: $bccaddr";
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): complete headers: $headers");

        $asf = false;
        if ($this->sentID) {
            $asf = $this->addSentMessage($this->sentID, $headers, $body);
        }
        else if (IMAP_SENTFOLDER) {
            $asf = $this->addSentMessage(IMAP_SENTFOLDER, $headers, $body);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): Outgoing mail saved in configured 'Sent' folder '%s': %s", IMAP_SENTFOLDER, Utils::PrintAsString($asf)));
        }
        // No Sent folder set, try defaults
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): No Sent mailbox set");
            if($this->addSentMessage("INBOX.Sent", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Outgoing mail saved in 'INBOX.Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Outgoing mail saved in 'Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent Items", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail():IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                $asf = true;
            }
        }

        if (!$asf) {
            ZLog::Write(LOGLEVEL_ERROR, "BackendIMAP->SendMail(): The email could not be saved to Sent Items folder. Check your configuration.");
        }

        return $send;
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        // TODO this could be retrieved from the DeviceFolderCache
        if ($this->wasteID == false) {
            //try to get the waste basket without doing complete hierarchy sync
            $wasteId = $this->imapLib->GetWasteBasket();
            if ($wasteId === false) {
                $this->GetHierarchy();
            }
            else {
                $this->wasteID = $wasteId;
            }
        }
        return $this->wasteID;
    }

    /**
     * Returns the content of the named attachment as stream. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to find the attachment must be encoded in that 'attname' property.
     * Data is written directly (with print $data;)
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetAttachmentData('%s')", $attname));

        list($folderid, $id, $part) = explode(":", $attname);

        if (!$folderid || !$id || !$part)
            throw new StatusException(sprintf("BackendIMAP->GetAttachmentData('%s'): Error, attachment name key can not be parsed", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // convert back to work on an imap-id
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        
        $attachment = $this->imapLib->GetAttachmentData($folderid, $id, $part);
        if ($attachment === false)
            throw new StatusException(sprintf("BackendIMAP->GetAttachmentData('%s'): Error, requested part key can not be found: '%d'", $attname, $part), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);
            
        return $attachment;
    }

    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * The IMAP backend simulates a sink by polling status information of the folder
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        $this->sinkfolders = array();
        $this->sinkstates = array();
        return true;
    }

    /**
     * The folder should be considered by the sink.
     * Folders which were not initialized should not result in a notification
     * of IBacken->ChangesSink().
     *
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if found can not be found
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("IMAPBackend->ChangesSinkInitialize(): folderid '%s'", $folderid));

        $imapid = $this->getImapIdFromFolderId($folderid);

        if ($imapid) {
            $this->sinkfolders[] = $imapid;
            return true;
        }

        return false;
    }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */
    public function ChangesSink($timeout = 30) {
        $notifications = array();
        $stopat = time() + $timeout - 1;

        while($stopat > time() && empty($notifications)) {
            foreach ($this->sinkfolders as $imapid) {
                $status = $this->imapLib->ChangesSink($imapid);
                if ($status === false) {
                    ZLog::Write(LOGLEVEL_WARN, sprintf("ChangesSink: could not stat folder '%s': %s ", $this->getFolderIdFromImapId($imapid), $this->imapLib->GetLastError()));
                }
                else {
                    if (! isset($this->sinkstates[$imapid]) )
                        $this->sinkstates[$imapid] = $status;

                    if ($this->sinkstates[$imapid] != $status) {
                        $notifications[] = $this->getFolderIdFromImapId($imapid);
                        $this->sinkstates[$imapid] = $status;
                    }
                }
            }

            if (empty($notifications))
                sleep(5);
        }

        return $notifications;
    }


    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */


    /**
     * Returns a list (array) of folders.
     *
     * @access public
     * @return array/boolean        false if the list could not be retrieved
     */
    public function GetFolderList() {
        $folders = $this->imapLib->GetFolderList();
        if ($folders !== false) {
            foreach ($folders as $folder) {
                // don't return the excluded folders
                $notExcluded = true;
                for ($i = 0, $cnt = count($this->excludedFolders); $notExcluded && $i < $cnt; $i++) {
                    // fix exclude folders with special chars by mku ZP-329
                    if (strpos(strtolower($folder["name"]), strtolower(Utils::Utf7_iconv_encode(Utils::Utf8_to_utf7($this->excludedFolders[$i])))) !== false) {
                        $notExcluded = false;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Pattern: <%s> found, excluding folder: '%s'", $this->excludedFolders[$i], $folder["name"])); // sprintf added by mku ZP-329
                    }
                }

                if ($notExcluded) {
                    $box = array();
                    $box["id"] = $this->convertImapId($folder["name"]);

                    $fhir = explode($folder["delimiter"], $folder["name"]);
                    if (count($fhir) > 1) {
                        $this->imapLib->GetModAndParentNames($fhir, $box["mod"], $imapparent);
                        $box["parent"] = $this->convertImapId($imapparent);
                    }
                    else {
                        $box["mod"] = $folder["name"];
                        $box["parent"] = "0";
                    }
                    $folders[] = $box;
                }
            }
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, "BackendIMAP->GetFolderList(): imap_list failed: " . $this->imapLib->GetLastError());
            return false;
        }

        return $folders;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        $folder = new SyncFolder();
        $folder->serverid = $id;

        // convert back to work on an imap-id
        $imapid = $this->getImapIdFromFolderId($id);

        // explode hierarchy
        $fhir = explode($this->imapLib->GetServerDelimiter(), $imapid);

        // compare on lowercase strings
        switch (strtolower($imapid)) {
            case "inbox":
                $folder->parentid = "0"; // Root
                $folder->displayname = "Inbox";
                $folder->type = SYNC_FOLDER_TYPE_INBOX;
                break;
            case "drafts":
                $folder->parentid = "0";
                $folder->displayname = "Drafts";
                $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
                break;
            case "trash":
                $folder->parentid = "0";
                $folder->displayname = "Trash";
                $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
                $this->wasteID = $id;
                break;
            case "sent":
            case "sent items":
            case IMAP_SENTFOLDER:
                $folder->parentid = "0";
                $folder->displayname = "Sent";
                $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
                $this->sentID = $id;
                break;
            // courier-imap outputs and cyrus-imapd outputs
            case "inbox.drafts":
            case "inbox/drafts":
                $folder->parentid = $this->convertImapId($fhir[0]);
                $folder->displayname = "Drafts";
                $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
                break;
            case "inbox.trash":
            case "inbox/trash":
                $folder->parentid = $this->convertImapId($fhir[0]);
                $folder->displayname = "Trash";
                $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
                $this->wasteID = $id;
                break;
            case "inbox.sent":
            case "inbox/sent":
                $folder->parentid = $this->convertImapId($fhir[0]);
                $folder->displayname = "Sent";
                $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
                $this->sentID = $id;
                break;
            // define the rest as user-mail-folders
            default:
                if (count($fhir) > 1) {
                    $this->imapLib->GetModAndParentNames($fhir, $folder->displayname, $imapparent);
                    $folder->parentid = $this->convertImapId($imapparent);
                    $folder->displayname = Utils::Utf7_to_utf8(Utils::Utf7_iconv_decode($folder->displayname));
                }
                else {
                    $folder->displayname = Utils::Utf7_to_utf8(Utils::Utf7_iconv_decode($imapid));
                    $folder->parentid = "0";
                }
                $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
                break;
        }

        //advanced debugging
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetFolder('%s'): '%s'", $id, $folder));

        return $folder;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * The folder type is ignored in IMAP, as all folders are Email folders
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        ZLog::Write(LOGLEVEL_INFO, sprintf("BackendIMAP->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));
        
        $result = $this->imapLib->ChangeFolder($folderid, $this->getImapIdFromFolderId($folderid), $oldid, Utils::Utf7_iconv_encode(Utils::Utf8_to_utf7($displayname)));
        if ($result !== false) {
            return $this->StatFolder($result);
        }
        else {
            return false;
        }
    }

    /**
     * Deletes a folder
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        ZLog::Write(LOGLEVEL_INFO, sprintf("BackendIMAP->DeleteFolder('%s', '%s')", $id, $parentid));
        
        return $this->imapLib->DeleteFolder($id, $parentid, $this->getImapIdFromFolderId($id), $this->getImapIdFromFolderId($parentid));
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessageList('%s','%s')", $folderid, $cutoffdate));

        $folderid = $this->getImapIdFromFolderId($folderid);

        if ($folderid == false)
            throw new StatusException("Folderid not found in cache", SYNC_STATUS_FOLDERHIERARCHYCHANGED);

        return $this->imapLib->GetMessageList($folderid, $cutoffdate);
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessage('%s','%s')", $folderid,  $id));

        $folderImapid = $this->getImapIdFromFolderId($folderid);

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        if ($stat) {
            return $this->imapLib->GetMessage($folderid, $folderImapid, $id, $contentparameters, $stat);
        }

        return false;
    }

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array/boolean
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->StatMessage('%s','%s')", $folderid,  $id));
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        
        return $this->imapLib->StatMessage($folderImapid, $id);
    }

    /**
     * Called when a message has been changed on the mobile.
     * Added support for FollowUp flag
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param SyncXXX             $message             the SyncObject containing a message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangeMessage('%s','%s','%s')", $folderid, $id, get_class($message)));
        // TODO this could throw several StatusExceptions like e.g. SYNC_STATUS_OBJECTNOTFOUND, SYNC_STATUS_SYNCCANNOTBECOMPLETED

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before changing the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_SYNCCANNOTBECOMPLETED should be thrown

        $folderImapid = $this->getImapIdFromFolderId($folderid);
        $this->imapLib->ChangeMessage($folderImapid, $id, $message, $contentParameters);
        return $this->StatMessage($folderid, $id);
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param int                 $flags               read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SetReadFlag('%s','%s','%s')", $folderid, $id, $flags));
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        
        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before setting the read flag, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown
        return $this->imapLib->SetReadFlag($folderImapid, $id, $flags, $contentParameters);
    }

    /**
     * Changes the 'star' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetStarFlag($folderid, $id, $flags, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SetStarFlag('%s','%s','%s')", $folderid, $id, $flags));
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        
        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before setting the read flag, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown
        return $this->imapLib->SetStarFlag($folderImapid, $id, $flags, $contentParameters);
    }  

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string              $folderid             id of the folder
     * @param string              $id                   id of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->DeleteMessage('%s','%s')", $folderid, $id));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before deleting the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown
        return $this->imapLib->DeleteMessage($folderImapid, $id, $contentParameters);
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     *
     * @param string              $folderid            id of the source folder
     * @param string              $id                  id of the message
     * @param string              $newfolderid         id of the destination folder
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->MoveMessage('%s','%s','%s')", $folderid, $id, $newfolderid));
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        $newfolderImapid = $this->getImapIdFromFolderId($newfolderid);

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before moving the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID should be thrown
        
        return $this->imapLib->MoveMessage($folderImapid, $id, $newfolderImapid, $contentParameters);
    }


    /**
     * Returns the BackendIMAP as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }


    /**----------------------------------------------------------------------------------------------------------
     * public ISearchProvider methods
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_MAILBOX);
    }


    /**
     * Queries the IMAP backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function GetGALSearchResults($searchquery, $searchrange) {
        return false;
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     * @param string $prefix If used with the combined backend here will come the backend id and delimiter
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo, $prefix = '') {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults()"));

        $items = false;
        $searchFolderId = $cpo->GetSearchFolderid();
        $searchRange = explode('-', $cpo->GetSearchRange());
        $filter = $this->getSearchRestriction($cpo);

        // Open the folder to search
        $search = true;

        if (empty($searchFolderid)) {
            $searchFolderid = $this->getFolderIdFromImapId('INBOX');
        }

        // Convert searchFolderId to IMAP id
        $imapid = $this->getImapIdFromFolderId($searchFolderid);

        $listMessages = array();
        $numMessages = 0;
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: Filter <%s>", $filter));

        if ($cpo->GetSearchDeepTraversal()) { // Recursive search
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: Recursive search %s", $imapid));
            $listFolders = $this->imapLib->GetFolderList();
            if ($listFolders === false) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->GetMailboxSearchResults: Error folder list %s", $this->imapLib->GetLastError()));
            }
            else {
                foreach ($listFolders as $subFolder) {
                    $subFolderid = $this->getFolderIdFromImapId($subFolder["name"]);
                    if ($subFolderid !== false) { //only search already cached folders
                        $found = $this->imapLib->Search($subFolder["name"], $filter);
                        if ($found !== false) {
                            $numMessages += count($found);
                            $listMessages[] = array($subFolderid => $found);
                        }
                    }
                }
            }
        }
        else { // Search in folder
            $found = $this->imapLib->Search($imapid, $filter);
            if ($found !== false) {
                $numMessages += count($found);
                $listMessages[] = array($searchFolderid => $found);
            }
        }
            

        if ($numMessages > 0) {
            // range for the search results
            $rangestart = 0;
            $rangeend = SEARCH_MAXRESULTS - 1;

            if (is_array($searchRange) && isset($searchRange[0]) && isset($searchRange[1])) {
                $rangestart = $searchRange[0];
                $rangeend = $searchRange[1];
            }
                    
            $querycnt = $numMessages;
            $items = array();
            $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt + 1;
            $items['range'] = $rangestart.'-'.($querylimit - 1);
            $items['searchtotal'] = $querycnt;
                        
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: %s entries found, returning %s", $items['searchtotal'], $items['range']));

            $p = 0;
            $pc = 0;
            for ($i = $rangestart, $j = 0; $i <= $rangeend && $i < $querycnt; $i++, $j++) {
                $keys = array_keys($listMessages[$p]);
                $cntFolder = count($listMessages[$p][$keys[0]]);
                if ($pc >= $cntFolder) {
                    $p++;
                    $pc = 0;
                    $keys = array_keys($listMessages[$p]);
                }
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: %s %s %s %s", $p, $pc, $keys[0], $listMessages[$p][$keys[0]][$pc]));
                $foundFolderid = $keys[0];
                $items[$j]['class'] = 'Email';
                $items[$j]['longid'] = $prefix . $foundFolderid . ":" . $listMessages[$p][$foundFolderid][$pc];
                $items[$j]['folderid'] = $prefix . $foundFolderid;
                $pc++;
            }
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: No messages found!"));
        }

        return $items;
    }

    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid) {
        return true;
    }

    /**
     * Disconnects from IMAP
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        // Don't close the mailbox, we will need it open in the Backend methods
        return true;
    }


    /**
     * Creates a search restriction
     *
     * @param ContentParameter $cpo
     * @return string
     */
    private function getSearchRestriction($cpo) {
        $searchText = $cpo->GetSearchFreeText();
        $searchGreater = $cpo->GetSearchValueGreater();
        $searchLess = $cpo->GetSearchValueLess();

        $filter = '';
        if ($searchGreater != '') {
            $filter .= ' SINCE "' . $searchGreater . '"';
        } else {
            // Only search in sync messages
            $limitdate = new DateTime();
            switch (SYNC_FILTERTIME_MAX) {
                case SYNC_FILTERTYPE_1DAY:
                    $limitdate = $limitdate->sub(new DateInterval("P1D"));
                    break;
                case SYNC_FILTERTYPE_3DAYS:
                    $limitdate = $limitdate->sub(new DateInterval("P3D"));
                    break;
                case SYNC_FILTERTYPE_1WEEK:
                    $limitdate = $limitdate->sub(new DateInterval("P1W"));
                    break;
                case SYNC_FILTERTYPE_2WEEKS:
                    $limitdate = $limitdate->sub(new DateInterval("P2W"));
                    break;
                case SYNC_FILTERTYPE_1MONTH:
                    $limitdate = $limitdate->sub(new DateInterval("P1M"));
                    break;
                case SYNC_FILTERTYPE_3MONTHS:
                    $limitdate = $limitdate->sub(new DateInterval("P3M"));
                    break;
                case SYNC_FILTERTYPE_6MONTHS:
                    $limitdate = $limitdate->sub(new DateInterval("P6M"));
                    break;
                default:
                    $limitdate = false;
                    break;
            }

            if ($limitdate !== false) {
                // date format : 7 Jan 2012
                $filter .= ' SINCE "' . ($limitdate->format("d M Y")) . '"';
            }
        }
        if ($searchLess != '') {
            $filter .= ' BEFORE "' . $searchLess . '"';
        }

        $filter .= ' BODY "' . $searchText . '"';
        
        return $filter;
    }


    /**----------------------------------------------------------------------------------------------------------
     * protected IMAP methods
     */

    /**
     * Unmasks a hex folderid and returns the imap folder id
     *
     * @param string        $folderid       hex folderid generated by convertImapId()
     *
     * @access protected
     * @return string       imap folder id
     */
    protected function getImapIdFromFolderId($folderid) {
        $this->InitializePermanentStorage();

        if (isset($this->permanentStorage->fmFidFimap)) {
            if (isset($this->permanentStorage->fmFidFimap[$folderid])) {
                $imapId = $this->permanentStorage->fmFidFimap[$folderid];
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, $imapId));
                return $imapId;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, 'not found'));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, 'not initialized!'));
        return false;
    }

    /**
     * Retrieves a hex folderid previousily masked imap
     *
     * @param string        $imapid         Imap folder id
     *
     * @access protected
     * @return string       hex folder id
     */
    protected function getFolderIdFromImapId($imapid) {
        $this->InitializePermanentStorage();

        if (isset($this->permanentStorage->fmFimapFid)) {
            if (isset($this->permanentStorage->fmFimapFid[$imapid])) {
                $folderid = $this->permanentStorage->fmFimapFid[$imapid];
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, $folderid));
                return $folderid;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, 'not found'));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, 'not initialized!'));
        return false;
    }

    /**
     * Masks a imap folder id into a generated hex folderid
     * The method getFolderIdFromImapId() is consulted so that an
     * imapid always returns the same hex folder id
     *
     * @param string        $imapid         Imap folder id
     *
     * @access protected
     * @return string       hex folder id
     */
    protected function convertImapId($imapid) {
        $this->InitializePermanentStorage();

        // check if this imap id was converted before
        $folderid = $this->getFolderIdFromImapId($imapid);

        // nothing found, so generate a new id and put it in the cache
        if (!$folderid) {
            // generate folderid and add it to the mapping
            $folderid = sprintf('%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ));

            // folderId to folderImap mapping
            if (!isset($this->permanentStorage->fmFidFimap))
                $this->permanentStorage->fmFidFimap = array();

            $a = $this->permanentStorage->fmFidFimap;
            $a[$folderid] = $imapid;
            $this->permanentStorage->fmFidFimap = $a;

            // folderImap to folderid mapping
            if (!isset($this->permanentStorage->fmFimapFid))
                $this->permanentStorage->fmFimapFid = array();

            $b = $this->permanentStorage->fmFimapFid;
            $b[$imapid] = $folderid;
            $this->permanentStorage->fmFimapFid = $b;
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->convertImapId('%s') = %s", $imapid, $folderid));

        return $folderid;
    }





    /**
     * Build a multipart RFC822, embedding body and one file (for attachments)
     *
     * @param string        $filenm         name of the file to be attached
     * @param long          $filesize       size of the file to be attached
     * @param string        $file_cont      content of the file
     * @param string        $body           current body
     * @param string        $body_ct        content-type
     * @param string        $body_cte       content-transfer-encoding
     * @param string        $boundary       optional existing boundary
     *
     * @access protected
     * @return array        with [0] => $mail_header and [1] => $mail_body
     */
    protected function mail_attach($filenm, $filesize, $file_cont, $body, $body_ct, $body_cte, $boundary = false) {
        if (!$boundary) $boundary = strtoupper(md5(uniqid(time())));

        //remove the ending boundary because we will add it at the end
        $body = str_replace("--$boundary--", "", $body);

        $mail_header = "Content-Type: multipart/mixed; boundary=$boundary\n";

        // build main body with the sumitted type & encoding from the pda
        $mail_body  = $this->enc_multipart($boundary, $body, $body_ct, $body_cte);
        $mail_body .= $this->enc_attach_file($boundary, $filenm, $filesize, $file_cont);

        $mail_body .= "--$boundary--\n\n";
        return array($mail_header, $mail_body);
    }

    /**
     * Helper for mail_attach()
     *
     * @param string        $boundary       boundary
     * @param string        $body           current body
     * @param string        $body_ct        content-type
     * @param string        $body_cte       content-transfer-encoding
     *
     * @access protected
     * @return string       message body
     */
    protected function enc_multipart($boundary, $body, $body_ct, $body_cte) {
        $mail_body = "This is a multi-part message in MIME format\n\n";
        $mail_body .= "--$boundary\n";
        $mail_body .= "Content-Type: $body_ct\n";
        $mail_body .= "Content-Transfer-Encoding: $body_cte\n\n";
        $mail_body .= "$body\n\n";

        return $mail_body;
    }

    /**
     * Helper for mail_attach()
     *
     * @param string        $boundary       boundary
     * @param string        $filenm         name of the file to be attached
     * @param long          $filesize       size of the file to be attached
     * @param string        $file_cont      content of the file
     * @param string        $content_type   optional content-type
     *
     * @access protected
     * @return string       message body
     */
    protected function enc_attach_file($boundary, $filenm, $filesize, $file_cont, $content_type = "") {
        if (!$content_type) $content_type = "text/plain";
        $mail_body = "--$boundary\n";
        $mail_body .= "Content-Type: $content_type; name=\"$filenm\"\n";
        $mail_body .= "Content-Transfer-Encoding: base64\n";
        $mail_body .= "Content-Disposition: attachment; filename=\"$filenm\"\n";
        $mail_body .= "Content-Description: $filenm\n\n";
        //contrib - chunk base64 encoded attachments
        $mail_body .= chunk_split(base64_encode($file_cont)) . "\n\n";

        return $mail_body;
    }

    /**
     * Adds a message with seen flag to a specified folder (used for saving sent items)
     *
     * @param string        $folderid       id of the folder
     * @param string        $header         header of the message
     * @param long          $body           body of the message
     *
     * @access protected
     * @return boolean      status
     */
    protected function addSentMessage($folderid, $header, $body) {
        $header_body = str_replace("\n", "\r\n", str_replace("\r", "", $header . "\n\n" . $body));
        
        return $this->imapLib->AddSentMessage($folderid, $header_body);
    }

    /**
     * Parses an mimedecode address array back to a simple "," separated string
     *
     * @param array         $ad             addresses array
     *
     * @access protected
     * @return string       mail address(es) string
     */
    protected function parseAddr($ad) {
        $addr_string = "";
        if (isset($ad) && is_array($ad)) {
            foreach($ad as $addr) {
                if ($addr_string) {
                    $addr_string .= ",";
                }
                $addr_string .= $addr->mailbox . "@" . $addr->host;
            }
        }
        else {
            $addr_string = "dummy@zpush.local";
        }
        return $addr_string;
    }

    /* BEGIN fmbiete's contribution r1528, ZP-320 */
    /**
     * Indicates which AS version is supported by the backend.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }
    /* END fmbiete's contribution r1528, ZP-320 */
};

?>