<?php
/**
 * Locum is a software library that abstracts ILS functionality into a
 * catalog discovery layer for use with such things as bolt-on OPACs like
 * SOPAC.
 * 
 *
 * @package Locum
 * @category Locum Connector
 * @author John Blyberg
 */
 
 /*
  * pear install MDB2_Driver_mssql
  * apt-get install php5-mssql
  */
 
class locum_polaris_40 {
 
  public $locum_config;
 
  /**
  * Grabs bib info from XRECORD and returns it in a Locum-ready array.
  *
  * @param int $bnum Bib number to scrape
  * @param boolean $skip_cover Forget about grabbing cover images.  Default: FALSE
  * @return boolean|array Will either return a Locum-ready array or FALSE
  */
  public function scrape_bib($bnum, $skip_cover = FALSE) {

    $psql_username = $this->locum_config['polaris_sql']['username'];
    $psql_password = $this->locum_config['polaris_sql']['password'];
    $psql_database = $this->locum_config['polaris_sql']['database'];
    $psql_server = $this->locum_config['polaris_sql']['server'];
    $psql_port = $this->locum_config['polaris_sql']['port'];
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];

    $polaris_dsn = 'mssql://' . $psql_username . ':' . $psql_password . '@' . $psql_server . ':' . $psql_port . '/' . $psql_database;
    $polaris_db =& MDB2::connect($polaris_dsn);

    $bib['bnum'] = $bnum;

    // Grab the bib record from the database
    $polaris_db_sql = 'SELECT * FROM [Polaris].[Polaris].[BibliographicRecords] WHERE [BibliographicRecordID] = ' . $bnum;
    $polaris_db_query = $polaris_db->query($polaris_db_sql);
    $polaris_bib_result = $polaris_db_query->fetchRow(MDB2_FETCHMODE_ASSOC);

    // Grab the bib record from the Polaris API
    $polaris_xml = $this->simpleXMLToArray(simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/bib/' . $bnum));
    
    if (count($polaris_xml['BibGetRows']['BibGetRow'])) {
      foreach ($polaris_xml['BibGetRows']['BibGetRow'] as $xmlBib_arr) {
        $polaris_xml_bib[preg_replace('/:/i', '', strtolower(trim($xmlBib_arr['Label'])))][] = trim($xmlBib_arr['Value']);
      }
    } else {
      return FALSE;
    }

    // Assign the values
    $bib['bib_created'] = substr($polaris_bib_result['creationdate'], 0, 10);
    $bib['bib_lastupdate'] = substr($polaris_bib_result['modificationdate'], 0, 10);
    $bib['bib_prevupdate'] = substr($polaris_bib_result['modificationdate'], 0, 10);
    $bib['modified'] = $polaris_bib_result['modificationdate'];
    $bib['bib_revs'] = 1; // Not tracked in Polaris?
    $bib['lang'] = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $polaris_bib_result['marclanguage']));
    $bib['lang'] = $bib['lang'] ? $bib['lang'] : 'eng'; // add defult config option. hard-coded for now.
    $bib['loc_code'] = "unused";
    //$bib['mat_code'] = $xmat_types_arr[$polaris_xml_bib['format'][0]];
    $bib['mat_code'] = $polaris_xml_bib['format'][0];
    if ($polaris_bib_result['displayinpac'] == 1) { $bib['suppress'] = 0; } else { $bib['suppress'] = 1; }
    $bib['author'] = $polaris_bib_result['browseauthor'];
    if (count($polaris_xml_bib['other author'])) {
      $bib['addl_author'] = serialize($polaris_xml_bib['other author']);
    } else {
      $bib['addl_author'] = NULL;
    }
    $bib['title'] = $polaris_bib_result['browsetitle'];
    if (preg_match('/\[(.*?)\]/', $polaris_xml_bib['medium'][0], $medium_match)) {
      $bib['title_medium'] = $medium_match[1];
    } else {
      $bib['title_medium'] = NULL;
    }
    $bib['edition'] = $polaris_xml_bib['edition'][0] ? $polaris_xml_bib['edition'][0] : NULL;
    $bib['series'] = $polaris_xml_bib['series'][0] ? $polaris_xml_bib['series'][0] : NULL;
    $bib['callnum'] = $polaris_bib_result['browsecallno'];
    $bib['pub_info'] = $polaris_xml_bib['publisher, date'][0] ? $polaris_xml_bib['publisher, date'][0] : NULL;
    $bib['pub_year'] = $polaris_bib_result['publicationyear'];
    if ($polaris_xml_bib['isbn'][1]) {
      $bib['stdnum'] = $polaris_xml_bib['isbn'][1];
    } else if ($polaris_xml_bib['isbn'][0]) {
      $bib['stdnum'] = $polaris_xml_bib['isbn'][0];
    } else {
      $bib['stdnum'] = NULL;
    }
    if ($bib['stdnum'] && preg_match('/ /', $stdnum)) {
      $stdnum_arr = explode(' ', $bib['stdnum']);
      $bib['stdnum'] = $stdnum_arr[0];
    }
    
    $bib['upc'] = '00000000000000000000'; // Not supported yet (and not really needed)
    $bib['cover_img'] = ''; // Not supported yet
    $bib['download_link'] = ''; // Not supported yet
    
    $bib['lccn'] = $polaris_xml_bib['lccn'][0] ? $polaris_xml_bib['lccn'][0] : NULL;
    $bib['descr'] = $polaris_xml_bib['description'][0] ? $polaris_xml_bib['description'][0] : NULL;
    
    
    if ($polaris_xml_bib['note'][0]) {
      $bib_notes = $polaris_xml_bib['note'];
      if ($polaris_xml_bib['summary'][0]) {
        $bib_notes = array_merge($bib_notes, $polaris_xml_bib['summary']);
      }
      $bib['notes'] = serialize($bib_notes);
    } else {
      $bib['notes'] = NULL;
    }
    
    if ($polaris_xml_bib['subject'][0]) {
      $bib_subjects = $polaris_xml_bib['subject'];
      if ($polaris_xml_bib['genre'][0]) {
        $bib_subjects = array_merge($bib_subjects, $polaris_xml_bib['genre']);
      }
      $bib['subjects'] = $bib_subjects;
    }

    return $bib;
  }
 
  /**
  * Parses item status for a particular bib item.
  *
  * @param string $bnum Bib number to query
  * @return array Returns a Locum-ready availability array
  */
  public function item_status($bnum) {
 
    $psql_username = $this->locum_config['polaris_sql']['username'];
    $psql_password = $this->locum_config['polaris_sql']['password'];
    $psql_database = $this->locum_config['polaris_sql']['database'];
    $psql_server = $this->locum_config['polaris_sql']['server'];
    $psql_port = $this->locum_config['polaris_sql']['port'];
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];
 
    if (!$bnum) { return FALSE; }

    // Grab the bib record from the Polaris API
    $polaris_xml = simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/bib/' . $bnum);
    foreach ($polaris_xml->BibGetRows->BibGetRow as $xmlBibObj) {
      $polaris_xml_bib[strtolower(substr(trim($xmlBibObj->Label), 0, -1))][trim($xmlBibObj->Occurence)] = trim($xmlBibObj->Value);
    }

    if ($polaris_xml_bib['current holds'][1]) {
      $status['holds'] = $polaris_xml_bib['current holds'][1];
    } else {
      $status['holds'] = 0;
    }

    // Grab the item holdings from the Polaris API
    $polaris_xml = simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/bib/' . $bnum . '/holdings');
    $holdings = $this->simpleXMLToArray($polaris_xml->BibHoldingsGetRows);
    $holdings = $holdings['BibHoldingsGetRow'];
    
    $items = array();
    if (is_array($holdings[0])) {
      foreach ($holdings as $holding) {
        $items[] = $this->avail_attr($holding);
      }
    } else if ($holdings['LocationID']) { 
      $items[] = $this->avail_attr($holdings);
    }
    $status['items'] = $items;

    return $this->verify_item_attributes($bnum);

    return $status;

  }
  

  /**
  * Returns an array of patron information
  *
  * @param string $pid Patron barcode number or record number
  * @return boolean|array Array of patron information or FALSE if login fails
  */
  public function patron_info($pid) {
    
    $psql_username = $this->locum_config['polaris_sql']['username'];
    $psql_password = $this->locum_config['polaris_sql']['password'];
    $psql_database = $this->locum_config['polaris_sql']['database'];
    $psql_server = $this->locum_config['polaris_sql']['server'];
    $psql_port = $this->locum_config['polaris_sql']['port'];
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];
    $pid = trim($pid);
    
    $polaris_dsn = 'mssql://' . $psql_username . ':' . $psql_password . '@' . $psql_server . ':' . $psql_port . '/' . $psql_database;
    $polaris_db =& MDB2::connect($polaris_dsn);
    // Grab the patron record from the database
    $polaris_db_sql = "SELECT * FROM [Polaris].[Polaris].[Patrons] WHERE [PatronID] = $pid OR [Barcode] = '$pid'";
    $polaris_db_query = $polaris_db->query($polaris_db_sql);
    $polaris_patron = $polaris_db_query->fetchRow(MDB2_FETCHMODE_ASSOC);
    
    $PatronID = $polaris_patron['patronid'];
    $Barcode = $polaris_patron['barcode'];
    
    if (!$PatronID || !$Barcode) { return FALSE; }
    
    // Grab patron info from the Polaris API
    $polaris_xml = simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/patron/' . $Barcode . '/basicdata');
    $patron_data_arr = $this->simpleXMLToArray($polaris_xml);
    
    $polaris_db_sql = 'SELECT * FROM [Polaris].[Polaris].[PatronRegistration] WHERE [PatronID] = ' . $PatronID;
    $polaris_db_query = $polaris_db->query($polaris_db_sql);
    $polaris_patron_details = $polaris_db_query->fetchRow(MDB2_FETCHMODE_ASSOC);

    $polaris_db_sql = 'SELECT TOP 1 [Polaris].[Polaris].[PatronAddresses].[PatronID], [Polaris].[Polaris].[Addresses].[StreetOne] FROM [Polaris].[Polaris].[PatronAddresses] LEFT OUTER JOIN [Polaris].[Polaris].[Addresses] ON [Polaris].[Polaris].[Addresses].[AddressID] = [Polaris].[Polaris].[PatronAddresses].[AddressID] WHERE [Polaris].[Polaris].[PatronAddresses].[PatronID] = ' . $PatronID;
    $polaris_db_query = $polaris_db->query($polaris_db_sql);
    $polaris_patron_addr = $polaris_db_query->fetchRow(MDB2_FETCHMODE_ASSOC);
    
    $patron['pnum'] = $PatronID;
    $patron['cardnum'] = $Barcode;
    $patron['checkouts'] = $patron_data_arr['PatronBasicData']['ItemsOutCount'];
    $patron['homelib'] = $polaris_patron['organizationid'];
    $patron['balance'] = number_format($patron_data_arr['PatronBasicData']['ChargeBalance'], 2);
    $exp_date_arr = date_parse($polaris_patron_details['expirationdate']);
    $patron['expires'] = mktime(0, 0, 0, $exp_date_arr['month'], $exp_date_arr['day'], $exp_date_arr['year']);
    $patron['name'] = $polaris_patron_details['namefirst'] . ' ' . $polaris_patron_details['namelast'];
    $patron['address'] = $polaris_patron_addr['streetone'];
    $patron['tel1'] = $polaris_patron_details['phonevoice1'];
    $patron['tel2'] = $polaris_patron_details['phonevoice2'];
    $patron['email'] = $polaris_patron_details['emailaddress'];
    
    return $patron;
    
  }

  /**
  * Returns an array of patron checkouts
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @return boolean|array Array of patron checkouts or FALSE if login fails
  */
  public function patron_checkouts($cardnum, $pin = NULL) {
    
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];

    $polaris_xml = simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/patron/' . $cardnum . '/itemsout/all');
    $polaris_checkout_arr = $this->simpleXMLToArray($polaris_xml);
    $checkout_arr = $polaris_checkout_arr['PatronItemsOutGetRows']['PatronItemsOutGetRow'];
    
    $items = array();
    if (is_array($checkout_arr[0])) {
      foreach ($checkout_arr as $checkout) {
        $items[] = $this->prep_checkouts($checkout);
      }
    } else if ($checkout_arr['ItemID']) { 
      $items[] = $this->prep_checkouts($checkout_arr);
    }
    return $items;
    
  }


  /**
  * Returns an array of patron checkouts for history
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @return boolean|array Array of patron checkouts or FALSE if login fails
  */
  public function patron_checkout_history($cardnum, $pin = NULL) {
    
  }

  /**
  * Opts patron in or out of checkout history
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @return boolean|array Array of patron checkouts or FALSE if login fails
  */
  public function patron_checkout_history_toggle($cardnum, $pin = NULL, $action) {
 
  }

  /**
  * Returns an array of patron holds
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @return boolean|array Array of patron holds or FALSE if login fails
  */
  public function patron_holds($cardnum, $pin = NULL) {

    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];
    
    $polaris_xml = simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/patron/' . $cardnum . '/holdrequests/all');
    $polaris_holds_arr = $this->simpleXMLToArray($polaris_xml);

    $holds_arr = $polaris_holds_arr['PatronHoldRequestsGetRows']['PatronHoldRequestsGetRow'];

    $holds = array();
    if (is_array($holds_arr[0])) {
      foreach ($holds_arr as $hold) {
        $holds[] = $this->prep_holds($hold);
      }
    } else if ($holds_arr['BibID']) { 
      $holds[] = $this->prep_holds($holds_arr);
    }

    return $holds;
 
  }

  /**
  * Renews items and returns the renewal result
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @param array Array of varname => item numbers to be renewed, or NULL for everything.
  * @return boolean|array Array of item renewal statuses or FALSE if it cannot renew for some reason
  */
  public function renew_items($cardnum, $pin = NULL, $items = NULL) {
    
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];

    $content = '<ItemsOutActionData><Action>renew</Action><LogonBranchID>1</LogonBranchID><LogonUserID>1</LogonUserID><LogonWorkstationID>1</LogonWorkstationID><RenewData><IgnoreOverrideErrors>true</IgnoreOverrideErrors></RenewData></ItemsOutActionData>';
    if (is_array($items)) {
      foreach ($items as $itemID => $itemvar) {
        $polaris_uri = '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/patron/' . $cardnum . '/itemsout/' . $itemID;
        $renew_query_result[$itemID] = $this->simpleXMLToArray(simplexml_load_string($this->curl_put($polaris_uri, $content)));
        $error_msg = $renew_query_result['ItemRenewResult']['BlockRows']['ItemRenewBlockRow']['ErrorDesc'];
        //$renew_result['error'] = $error_msg ? $error_msg : NULL;
      }
      $items_out = $this->patron_checkouts($cardnum);

      $renew_result_arr = array();
      foreach ($renew_query_result as $itemID => $renew_query_result_arr) {
        $renew_result = array();
        $num_renews = NULL;
        $duedate = NULL;
        $renew_block_arr = $renew_query_result_arr['ItemRenewResult']['BlockRows']['ItemRenewBlockRow'];
        if (is_array($renew_block_arr[0])) {
          $error_msg = '';
          foreach ($renew_block_arr as $renew_block) {
            if ($renew_block['ErrorDesc']) {
              $error_msg .= '* ' . $renew_block['ErrorDesc'] . ' ';
            }
          }
        } else {
          if ($renew_query_result_arr['ItemRenewResult']['BlockRows']['ItemRenewBlockRow']['ErrorDesc']) {
            $error_msg = $renew_query_result_arr['ItemRenewResult']['BlockRows']['ItemRenewBlockRow']['ErrorDesc'];
          }
        }
        foreach ($items_out as $item) {
          if ($item['inum'] == $itemID) {
            $num_renews = (int) $item['numrenews'];
            $duedate = $item['duedate'];
            $varname = $item['varname'];
          }
        }
        $renew_result['num_renews'] = $num_renews;
        $renew_result['error'] = trim($error_msg);
        $renew_result['varname'] = $varname;
        $renew_result['new_duedate'] = $duedate;
      
        $renew_result_arr[$itemID] = $renew_result;
      }
      return $renew_result_arr;
    } else {
      if (!$items) {
        $polaris_uri = '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/patron/' . $cardnum . '/itemsout/0';
        $renew_query_result = $this->simpleXMLToArray(simplexml_load_string($this->curl_put($polaris_uri, $content)));
        return $renew_query_result;
      }
    }
  }
  
  
  


  /**
  * Updates holds/reserves
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @param array $cancelholds Array of varname => item/bib numbers to be cancelled, or NULL for everything.
  * @param array $holdfreezes_to_update Array of updated holds freezes.
  * @param array $pickup_locations Array of pickup location changes.
  * @return boolean TRUE or FALSE if it cannot cancel for some reason
  */
  public function update_holds($cardnum, $pin = NULL, $cancelholds = array(), $holdfreezes_to_update = array(), $pickup_locations = array()) {
 
  }

  /**
  * Places holds
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $bnum Bib item record number to place a hold on
  * @param string $inum Item number to place a hold on if required (presented as $varname in locum)
  * @param string $pin Patron pin/password
  * @param string $pickup_loc Pickup location value
  * @return boolean TRUE or FALSE if it cannot place the hold for some reason
  */
  public function place_hold($cardnum, $bnum, $inum = NULL, $pin = NULL, $pickup_loc = NULL) {
    
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];

    $ActivationDate = date('Y-m-d\TH:i:s.00');
    $patron_info = $this->patron_info($cardnum);
    $pickup_loc = $pickup_loc ? $pickup_loc : $patron_info['homelib'];
    $pnum = $patron_info['pnum'];

    $polaris_uri = '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/holdrequest';
    $content = '<HoldRequestCreateData><PatronID>' . $pnum . '</PatronID><BibID>' . $bnum . '</BibID><ItemBarcode/><VolumeNumber/> <Designation/><PickupOrgID>' . $pickup_loc . '</PickupOrgID><PatronNotes/><ActivationDate>' . $ActivationDate . '</ActivationDate><WorkstationID>1</WorkstationID><UserID>1</UserID><RequestingOrgID>' . $pickup_loc . '</RequestingOrgID><TargetGUID></TargetGUID></HoldRequestCreateData>';

    $request_result = $this->simpleXMLToArray(simplexml_load_string($this->curl_post($polaris_uri, $content)));

    if ($request_result['StatusType'] == 2 && $request_result['StatusValue'] == 1) {
      $request['success'] = 1;
      $request['choose_location'] = NULL;
    } else if (in_array($request_result['StatusValue'], array(3,4,5,6,7))) {
      $polaris_uri = '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/holdrequest/' . $request_result['RequestGUID'];
      $content = '<HoldRequestReplyData><TxnGroupQualifier>' . $request_result['TxnGroupQualifer'] . '</TxnGroupQualifier><TxnQualifier>' . $request_result['TxnQualifier'] . '</TxnQualifier> <RequestingOrgID>' . $pickup_loc . '</RequestingOrgID><Answer>1</Answer><State>' . ($request_result['StatusValue'] - 2) . '</State></HoldRequestReplyData>';

      $reply_request_result = $this->simpleXMLToArray(simplexml_load_string($this->curl_put($polaris_uri, $content)));

      if ($reply_request_result['StatusType'] == 2 && $reply_request_result['StatusValue'] == 1) {
        $request['success'] = 1;
        $request['choose_location'] = NULL;
      } else {
        $request['success'] = 0;
        $request['choose_location'] = NULL;
      }
      $request_result['Message'] = $reply_request_result['Message'];
    } else {
      $request['success'] = 0;
      $request['choose_location'] = NULL;
      
      $branches = $this->get_branch_list();
      if ($request_result['StatusType'] == 1 && $request_result['StatusValue'] == 2) {
        foreach ($branches as $branch) {
          $branch_arr[$branch['OrganizationID']] = $branch['DisplayName'];
        }
        $request['choose_location']['options'] = $branch_arr;
      }
      
    }
    $request['error'] = $request_result['Message'];
    $request['selection'] = NULL; // Not supported yet (may not need to be in Polaris?)
    
    return $request;
 
  }

  /**
  * Returns an array of patron fines
  *
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @return boolean|array Array of patron fines or FALSE if login fails
  */
  public function patron_fines($cardnum, $pin = NULL) {
    
  }

  /**
  * Pays patron fines.
  * @param string $cardnum Patron barcode/card number
  * @param string $pin Patron pin/password
  * @param array payment_details
  * @return array Payment result
  */
  public function pay_patron_fines($cardnum, $pin = NULL, $payment_details) {
 
  }


  ///// Extra Tools & Internal functions
  
  private function simpleXMLToArray($xml, $flattenValues=true, $flattenAttributes = true, $flattenChildren=true, $valueKey='@value', $attributesKey='@attributes', $childrenKey='@children') {

    $return = array();
    if (!($xml instanceof SimpleXMLElement)) { return $return; }
    $name = $xml->getName();
    $_value = trim((string)$xml);
    if (strlen($_value)==0) { $_value = null; };

    if ($_value!==null) {
      if (!$flattenValues) { $return[$valueKey] = $_value; }
      else { $return = $_value; }
    }

    $children = array();
    $first = true;
    foreach ($xml->children() as $elementName => $child) {
      $value = $this->simpleXMLToArray($child, $flattenValues, $flattenAttributes, $flattenChildren, $valueKey, $attributesKey, $childrenKey);
      if (isset($children[$elementName])) {
        if ($first) {
          $temp = $children[$elementName];
          unset($children[$elementName]);
          $children[$elementName][] = $temp;
          $first = false;
        }
        $children[$elementName][] = $value;
      } else {
        $children[$elementName] = $value;
      }
    }
    if (count($children)>0) {
      if (!$flattenChildren) { $return[$childrenKey] = $children; }
      else { $return = array_merge($return,$children); }
    }

    $attributes = array();
    foreach ($xml->attributes() as $name=>$value) {
      $attributes[$name] = trim($value);
    }
    if (count($attributes)>0) {
      if (!$flattenAttributes) { $return[$attributesKey] = $attributes; }
      else { $return = array_merge($return, $attributes); }
    }

    return $return;
  }

  /**
  * Internal function to determine availability attributes
  */
  private function avail_attr($holding) {
    
    $avail_tokens = explode(',', $this->locum_config['polaris_custom_config']['polaris_available_token']);

    $avail_attr['location'] = $holding['CollectionName'];
    $avail_attr['loc_code'] = $holding['CollectionID'];
    $avail_attr['callnum'] = $holding['CallNumber'];
    $avail_attr['statusmsg'] = $holding['CircStatus'];
    $avail_attr['avail'] = in_array($holding['CircStatus'], $avail_tokens) ? 1 : 0;
    if ($holding['DueDate'] && !is_array($holding['DueDate'])) {
      $due_date_arr = date_parse($holding['DueDate']);
      $avail_attr['due'] = mktime(23, 59, 59, $due_date_arr['month'], $due_date_arr['day'], $due_date_arr['year']);
    } else {
      $avail_attr['due'] = NULL;
    }
    if ($avail_attr['due']) {
      $avail_attr['statusmsg'] = 'Due ' . date($this->locum_config['polaris_custom_config']['polaris_display_date_fmt'], $avail_attr['due']);
    }
    $avail_attr['age'] = $this->get_age($holding['CollectionID']);
    $avail_attr['branch'] = $holding['LocationID'];

    return $avail_attr;
  }
  
  /**
  * Internal function to determine age from location
  */
  private function get_age($loc_code) {
    
    $psql_username = $this->locum_config['polaris_sql']['username'];
    $psql_password = $this->locum_config['polaris_sql']['password'];
    $psql_database = $this->locum_config['polaris_sql']['database'];
    $psql_server = $this->locum_config['polaris_sql']['server'];
    $psql_port = $this->locum_config['polaris_sql']['port'];
    
    $age = $this->locum_config['polaris_custom_config']['polaris_default_age'];
    
    if (count($this->locum_config['polaris_record_ages'])) {
      foreach ($this->locum_config['polaris_record_ages'] as $item_age => $match_crit) {
        if (preg_match('/^\//', $match_crit)) {
          // Use Collection abbreviation
          
          $polaris_dsn = 'mssql://' . $psql_username . ':' . $psql_password . '@' . $psql_server . ':' . $psql_port . '/' . $psql_database;
          $polaris_db =& MDB2::connect($polaris_dsn);
          // Grab the bib record from the database
          $polaris_db_sql = 'SELECT [Abbreviation] FROM [Polaris].[Polaris].[Collections] WHERE [CollectionID] = ' . $loc_code;
          $polaris_db_query = $polaris_db->query($polaris_db_sql);
          $polaris_loc_abbrev = $polaris_db_query->fetchOne();
          
          if (preg_match($match_crit, $polaris_loc_abbrev)) { $age = $item_age; }
        } else {
          // Use Collection ID
          if (in_array($loc_code, locum::csv_parser($match_crit))) { $age = $item_age; }
        }
      }
    }
    return $age;
  }

  
  /**
  * Internal function to prepare individual checkout arrays for patron_checkouts
  */
  private function prep_checkouts($checkout_arr) {

    $item['varname'] = $checkout_arr['ItemID'];
    $item['inum'] = $checkout_arr['ItemID'];
    $item['bnum'] = $checkout_arr['BibID'];
    $item['title'] = ucwords($checkout_arr['Title']);
    $item['ill'] = 0; // Not supported yet
    $item['numrenews'] = $checkout_arr['RenewalCount'];
    $due_date_arr = date_parse(preg_replace('/T/i', ' ', $checkout_arr['DueDate']));
    $item['duedate'] = mktime(23, 59, 59, $due_date_arr['month'], $due_date_arr['day'], $due_date_arr['year']);
    $item['callnum'] = $checkout_arr['CallNumber'];
    
    return $item;
  }

  
  /**
  * Internal function to prepare individual checkout arrays for patron_checkouts
  */
  private function prep_holds($holds_arr) {
    
    $hold['bnum'] = $holds_arr['BibID'];
    $hold['title'] = ucwords($holds_arr['Title']);
    $hold['ill'] = 0; // Not supported yet
    $hold['status'] = $holds_arr['QueuePosition'] . ' of ' . $holds_arr['QueueTotal'];
    $hold['is_frozen'] = 0; // Not supported yet
    $hold['can_freeze'] = 0; // Not supported yet
    $pickup_loc['selectid'] = $holds_arr['HoldRequestID'];
    $pickup_loc['selected'] = $holds_arr['PickupBranchID'];
    $branch_list = $this->get_branch_list();
    foreach ($branch_list as $branch) {
      $pickup_loc['options'][$branch['OrganizationID']] = $branch['DisplayName'];
    }
    $hold['pickuploc'] = $pickup_loc;
    
    return $hold;
  }
  
  /**
  * Internal function to prepare individual checkout arrays for patron_checkouts
  */
  private function get_branch_list($orgCode = 'branch') {
    
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $orgID = $this->locum_config['polaris_api']['orgID'];
    $appID = $this->locum_config['polaris_api']['appID'];
    $langID = $this->locum_config['polaris_api']['langID'];
    
    $polaris_xml = simplexml_load_file('http://' . $pils_server . '/PAPIService/REST/public/v1/' . $langID . '/' . $appID . '/' . $orgID . '/organizations/' . $orgCode);
    $polaris_branch_arr = $this->simpleXMLToArray($polaris_xml);
    $branch_arr = $polaris_branch_arr['OrganizationsGetRows']['OrganizationsGetRow'];

    if (is_array($branch_arr[0])) {
      $branches = $branch_arr;
    } else {
      $branches = array();
      $branches[] = $branch_arr;
    }
    
    return $branches;
  }
  
  /**
  * Internal function to determine age from location
  */
  private function curl_put($uri, $content = NULL, $pin = NULL) {
    
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $PAPIAccessKeyID = $this->locum_config['polaris_api']['PAPIAccessKeyID'];
    $PAPIAccessKey = $this->locum_config['polaris_api']['PAPIAccessKey'];
    $url = 'http://' . $pils_server . $uri;
    
    $ch = curl_init();

    $date_1123 = gmdate('D, d M Y H:i:s \G\M\T');
    $concat = "PUT" . $url . $date_1123;
    if ($pin) { $concat .= $pin; }
    
    $sha1_sig = base64_encode(hash_hmac('sha1', $concat, $PAPIAccessKey, true));
    
    $headers = array(
      'Authorization: PWS ' . $PAPIAccessKeyID . ':' . $sha1_sig,
      'PolarisDate: ' . $date_1123,
      'Content-Type: text/xml',
      'Host: ' . $pils_server,
    );
    
    $fh = fopen('php://memory', 'rw');
    fwrite($fh, $content);
    rewind($fh);
    curl_setopt($ch, CURLOPT_INFILE, $fh);
    curl_setopt($ch, CURLOPT_INFILESIZE, strlen($content));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_PUT, 1);
    
    $output = curl_exec($ch);
    return $output;
    
  }
  
  /**
  * Internal function to determine age from location
  */
  private function curl_post($uri, $content = NULL, $pin = NULL) {
    
    $pils_server = $this->locum_config['ils_config']['ils_server'];
    $PAPIAccessKeyID = $this->locum_config['polaris_api']['PAPIAccessKeyID'];
    $PAPIAccessKey = $this->locum_config['polaris_api']['PAPIAccessKey'];
    $url = 'http://' . $pils_server . $uri;
    
    $ch = curl_init();

    $date_1123 = gmdate('D, d M Y H:i:s \G\M\T');
    $concat = "POST" . $url . $date_1123;
    if ($pin) { $concat .= $pin; }
    
    $sha1_sig = base64_encode(hash_hmac('sha1', $concat, $PAPIAccessKey, true));
    
    $headers = array(
      'Authorization: PWS ' . $PAPIAccessKeyID . ':' . $sha1_sig,
      'PolarisDate: ' . $date_1123,
      'Content-Type: text/xml',
      'Host: ' . $pils_server,
      'Content-Length: ' . strlen($content),
    );
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    
    $output = curl_exec($ch);
    return $output;
    
  }
  
  public function verify_item_attributes($bnum, $modify = FALSE) {
    
    $psql_username = $this->locum_config['polaris_sql']['username'];
    $psql_password = $this->locum_config['polaris_sql']['password'];
    $psql_database = $this->locum_config['polaris_sql']['database'];
    $psql_server = $this->locum_config['polaris_sql']['server'];
    $psql_port = $this->locum_config['polaris_sql']['port'];

    $polaris_dsn = 'mssql://' . $psql_username . ':' . $psql_password . '@' . $psql_server . ':' . $psql_port . '/' . $psql_database;
    $polaris_db =& MDB2::connect($polaris_dsn);
    
    $polaris_db_sql = 'SELECT [MaterialTypeID], [DisplayInPAC] FROM [Polaris].[Polaris].[ItemRecords] WHERE [AssociatedBibRecordID] = ' . $bnum;
    
    $polaris_db_query = $polaris_db->query($polaris_db_sql);
    $polaris_bib_result = $polaris_db_query->fetchAll(MDB2_FETCHMODE_ASSOC);
    
    foreach ($polaris_bib_result as $item_result) {
      
    }
    
    if ($modify) {
      require($this->locum_config['locum_config']['dsn_file']);
      $scas_db =& MDB2::connect($dsn);
    }
    
    return array($bnum, $item_arr);
    
  }

}