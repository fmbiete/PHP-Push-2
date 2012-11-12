<?php
/***********************************************
* File      :   searchCardDAV.php
* Project   :   Z-Push
* Descr     :   A ISearchProvider implementation to
*               query a CardDAV server for GAL
*               information.
*
* Created   :   11.11.2012
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

include_once('include/carddav.php');
include_once('include/vCard.php');

class BackendSearchCardDAV implements ISearchProvider {
    private $connection;
    private $url;

    /**
     * Initializes the backend to perform the search
     * Connects to the CardDAV server using the values from the configuration
     *
     *
     * @access public
     * @return
     * @throws StatusException
     */
    public function BackendSearchCardDAV($username, $password) {
        if (!function_exists("curl_init")) {
            throw new StatusException("BackendSearchCardDAV(): php-curl is not installed. Search aborted.", SYNC_SEARCHSTATUS_STORE_SERVERERROR, null, LOGLEVEL_FATAL);
        }

        $url = str_replace('%u', $username, CARDDAV_SERVER . ':' . CARDDAV_PORT . CARDDAV_PATH);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendSearchCardDAV('%s')", $url));
        $this->connection = new carddav_backend($url);
        $this->connection->set_auth($username, $password);

        if ($this->connection->check_connection())
        {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendSearchCardDAV(): User '%s' is authenticated on CardDAV", $username));
            $this->url = $url;
        }
        else
        {
            throw new StatusException(sprintf("BackendSearchCardDAV(): Could not bind to server with user '%s' and specified password! Search aborted.", $username), SYNC_SEARCHSTATUS_STORE_CONNECTIONFAILED, null, LOGLEVEL_ERROR);
        }
    }

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_GAL);
    }


    /**
     * Queries the LDAP backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function GetGALSearchResults($searchquery, $searchrange) {
        if (isset($this->connection) && $this->connection !== false) {
            if (strlen($searchquery) < 5) {
                return false;
            }

            $url = $this->url . CARDDAV_PRINCIPAL . '/';
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendSearchCardDAV->GetGALSearchResults(%s, %s) -> URL: %s", $searchquery, $searchrange, $url));
            $this->connection->set_url($url);
            $vcardlist = $this->connection->search_vcards($searchquery, 15, true, false);
            if ($vcardlist === false) {
                ZLog::Write(LOGLEVEL_ERROR, "BackendSearchCardDAV: Error in search query. Search aborted");
                return false;
            }
            
            $xmlvcardlist = new SimpleXMLElement($vcardlist);
            
            // range for the search results, default symbian range end is 50, wm 99,
            // so we'll use that of nokia
            $rangestart = 0;
            $rangeend = 50;

            if ($searchrange != '0') {
                $pos = strpos($searchrange, '-');
                $rangestart = substr($searchrange, 0, $pos);
                $rangeend = substr($searchrange, ($pos + 1));
            }
            $items = array();

            // TODO the limiting of the searchresults could be refactored into Utils as it's probably used more than once
            $querycnt = $xmlvcardlist->count();
            //do not return more results as requested in range
            $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt;
            $items['range'] = $rangestart.'-'.($querycnt-1);
            $items['searchtotal'] = $querycnt;
            
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendSearchCardDAV: %s entries found, returning %s to %s", $querycnt, $rangestart, $querylimit));
            
            $i = 0;
            $rc = 0;
            foreach ($xmlvcardlist->element as $vcard) {
                if ($i >= $rangestart && $i < $querylimit) {
                    $card = new vCard(false, $vcard->vcard->__toString());
                    if ($card->EMAIL) {
                        if (is_scalar($card->EMAIL[0])) {
                            $items[$rc][SYNC_GAL_EMAILADDRESS] = $card->EMAIL[0];
                        }
                        else {
                            $items[$rc][SYNC_GAL_EMAILADDRESS] = $card->EMAIL[0]['Value'];
                        }
                    }
                    $items[$rc][SYNC_GAL_DISPLAYNAME]    = isset($card->FN) && isset($card->FN[0]) ? $card->FN[0] : $items[$rc][SYNC_GAL_EMAILADDRESS];
                    if ($card->TEL) {
                        if (is_scalar($card->TEL[0])) {
                            $items[$rc][SYNC_GAL_PHONE] = $card->TEL[0];
                        }
                        else {
                            $items[$rc][SYNC_GAL_PHONE] = $card->TEL[0]['Value'];
                        }
                    }
                    $items[$rc][SYNC_GAL_OFFICE]         = '';
                    $items[$rc][SYNC_GAL_TITLE]          = isset($card->TITLE) && isset($card->TITLE[0]) ? $card->TITLE[0] : "";
                    $items[$rc][SYNC_GAL_COMPANY]        = isset($card->ORG) && isset($card->ORG[0]) && isset($card->ORG[0]['Name']) ? $card->ORG[0]['Name'] : "";
                    $items[$rc][SYNC_GAL_ALIAS]          = '';
                    $items[$rc][SYNC_GAL_FIRSTNAME]      = isset($card->N) && isset($card->N[0]) && isset($card->N[0]['FirstName']) ? $card->N[0]['FirstName'] : "";
                    $items[$rc][SYNC_GAL_LASTNAME]       = isset($card->N) && isset($card->N[0]) && isset($card->N['LastName']) ? $card->N['LastName'] : "";
                    $items[$rc][SYNC_GAL_HOMEPHONE]      = isset($card->TEL) && isset($card->TEL[0]) && isset($card->TEL[0]['home']) ? $card->TEL[0]['home'] : "";
                    $items[$rc][SYNC_GAL_MOBILEPHONE]    = isset($card->TEL) && isset($card->TEL[0]) && isset($card->TEL[0]['cell']) ? $card->TEL[0]['cell'] : "";
                    unset($card);
                    
                    $rc++;
                }
                $i++;
            }
            
            unset($xmlvcardlist);
            unset($vcardlist);

            return $items;
        }
        else
            return false;
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo) {
        return array();
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
     * Disconnects from CardDAV
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        return true;
    }
}
?>