<?php

require_once 'CRM/Core/Page.php';

class CRM_Cardreader_Page_Callback extends CRM_Core_Page {
  protected $_data = NULL;
  protected $_defaultLocationType = NULL;
  protected $_pseudoconstants = NULL;
  function run() {
    $this->_data = $_POST;
    if(empty($this->_data)){
      return;
    }
    $this->getOptions();
    $this->prepareData();
    if(!empty($this->_data)){
      $contact_exist = $this->getContact();
      if(!$contact_exist){
        $this->createContact();
      }
    }
    parent::run();
  }


  function createContact() {
    $params = array(
      'version' => 3,
      'contact_type' => 'Individual',
      'first_name'   => $this->_data['personFirstName'],
      'last_name'    => $this->_data['personLastName'],
    );
    if (!empty($this->_data['emails'])) {
      $params['email'] = reset(reset($this->_data['emails']));
    }

    $newContact = civicrm_api('contact', 'create', $params);
    $contactId = '';
    if ($newContact['is_error'] == 0 && !empty($newContact['values'])) {
      $contactId = reset(reset($newContact['values']));

    }
    if (!empty($contactId)) {
      foreach(array('phones' => 'Phone', 'emails' => 'Email', 'urls' => 'Website', 'addresses' => 'Address') as $type => $entity)  {
        if(!empty($this->_data[$type])) {
          foreach($this->_data[$type] as $params) {
            $params['contact_id'] = $contactId;
            $this->wrap_civicrm_api($entity, $params);
          }
        }
      }
    }
  }

  function getContact(){
    $params = array(
      'version'    => 3,
      'sequential' => 1,
      'first_name' => $this->_data['personFirstName'],
      'last_name'  => $this->_data['personLastName'],
    );
    if (!empty($this->_data['emails'])) {
      $params['email'] = reset(reset($this->_data['emails']));
    }

    $getContacts= civicrm_api('Contact', 'get', $params);
    if(!empty($getContacts['values'])) {
      return true;
    }
    return false;
  }

  public function prepareData() {
    foreach(array('phones', 'emails','urls','addresses') as $type ) {
      $data = array();
      if(!empty($this->_data[$type])) {
        foreach($this->_data[$type] as $locType => $datas) {
          $value = reset($datas);
          if(is_numeric($locType)) {
            $locType = $this->_defaultLocationType->name;
          }
          list($locationTypeId, $locationType) = $this->getNearestMatch('location_type', $locType);

          if($type == 'phones') {
            $phoneInfo = preg_split('/'.$locationType.'/i', $locType);
            if(count($phoneInfo) == 2 && !empty($phoneInfo[1])) {
              list($phoneTypeId, $phoneType) = $this->getNearestMatch('phone_type', $phoneInfo[1]);
            } else {
              list($phoneTypeId, $phoneType) = $this->getNearestMatch('phone_type');
            }
            
            $data[] = array(
              'location_type_id' => $locationTypeId,
              'phone_type' => $phoneTypeId,
              'phone' => $value,
              'is_primary' => '1',
            );
          } elseif($type == 'emails') {
            $data[] = array(
              'email' => $value,
              'location_type_id' => $locationTypeId,
              'is_primary' => '1',
            );
          } elseif($type == 'urls') {
            list($urlTypeId, $urlType) = $this->getNearestMatch('website_type', $locType);
            $data[] = array(
              'website_type_id' => $urlTypeId,
              'url' => $value,
              'is_primary' => '1',

            );
          } elseif($type == 'ims') {
            list($providerTypeId, $providerType) = $this->getNearestMatch('provider', $locType);
            $data[] = array(
              'location_type_id' => $locType,
              'provider_id' => $providerTypeId,
              'name' => $value,
              'is_primary' => '1',

            );
          } elseif($type == 'addresses') {
            $address = array();
            foreach($datas as $name => $val) {
              $address['location_type_id'] = $locationTypeId;
              $address['is_primary'] = '1';
              if ($name == 'country') {
                list($address['country_id'], ) = $this->getNearestMatch('country', $val);
              } else if($name == 'street') {
                $address['street_address'] = $val;
              } else if($name == 'city') {
                $address['city'] = $val;
              } else if($name == 'zip') {
                $address['postal_code'] = $val;
              } else if($name == 'state') {
                //list($address['state_province_id'], ) = $this->getNearestMatch('state_province', $val);
              }
            }
            $data[] = $address;
          }
        }
      }
      $this->_data[$type] = $data;
    }
  }


  public function wrap_civicrm_api($entity, $params) {
    $result = civicrm_api3($entity, 'create', $params);
    if ($result['is_error']) {
      // if api call failed
      $error = " FAIL: ". $result['error_message'] . "\n";
      crm_core_error::debug_var('error', $error);
      $id = false;

    } else {
      // general case: success
      reset( $result['values'] );
      $id = key( $result['values'] );
    }
    return $id;
  }

  public function getOptions() {
    $this->_defaultLocationType = CRM_Core_BAO_LocationType::getDefault();
    //$this->_defaultLocationType = $defaultLocationType->id;
    $pseudoconstants = array(
      'location_type' => 'address',
      'phone_type'    => 'phone',
      'provider'      => 'im',
      'website_type'  => 'website',
      'country'       => 'address',
      'country_code'  => CRM_Core_PseudoConstant::countryIsoCode(), // no need to flip this one.
      'state_province'=> array_merge( array_flip(CRM_Core_PseudoConstant::stateProvince(false, false)),
                                      array_flip(CRM_Core_PseudoConstant::stateProvinceAbbreviation())),

    );

    foreach ($pseudoconstants as $key => $source) {
      if (!is_string($source)) continue;
      $result = civicrm_api3($source, 'getoptions', array('field' => $key.'_id'));
      if (!$result['is_error']) {
        $pseudoconstants[$key] = array_flip($result['values']);
      }
    }
    $this->_pseudoconstants = $pseudoconstants;
  }

  function getNearestMatch($inputType, $input = '') {
    $inputCombination = $closest = '';
    if ($inputType == 'country') {
      $input = str_replace(array('.', ','), '' , $input);
      $input = str_replace('US', 'United States' , $input);
      $inputCombination = explode(" ", $input);
    } else if (!empty($input)) {
      $inputCombination = (array)$input;
    }
    if($inputType == 'country' && count($inputCombination) > 1) {
      $inputCombination = $this->getAllCombos($inputCombination);
      array_unshift($inputCombination, $input);
    }
    // no shortest distance found, yet
    $shortest = -1;
    if(!empty($inputCombination)) {
      // loop through words to find the closest
      foreach($inputCombination as $input) {
        foreach ($this->_pseudoconstants[$inputType] as $word => $id) {

          // calculate the distance between the input word,
          // and the current word
          $lev = levenshtein($input, $word);
          // check for an exact match
          if ($lev == 0) {
            // closest word is this one (exact match)
            $closest = $word;
            $shortest = 0;
            // break out of the loop; we've found an exact match
            break 2;
          } else if ( false && $lev <= 8) {
            // closest word is this one (exact match)
            $closest = $word;

            $shortest = $lev;
            // break out of the loop; we've found an exact match
            break 2;
          }

          // if this distance is less than the next found shortest
          // distance, OR if a next shortest word has not yet been found
          if ($lev <= $shortest || $shortest < 0) {
            // set the closest match, and shortest distance
            $closest  = $word;
            $shortest = $lev;
          }
        }
      }
    }
    
    if(!empty($closest) && !empty($this->_pseudoconstants[$inputType][$closest])) {
      return array($this->_pseudoconstants[$inputType][$closest], $closest);
    }

    if ($inputType == 'location_type') {
      return array($this->_defaultLocationType->id, $this->_defaultLocationType->name);
    } else if (($inputType == 'country' || $inputType == 'state_province') && empty($closest)) {
      return array();
    }

    if (empty($closest)) {
      $tmp = $this->_pseudoconstants[$inputType];
      $id = reset($tmp);
      $name = key($tmp);
      return array($id, $name);
    }
  }


  function getAllCombos($arr) {
    $combinations = array();
    $words = sizeof($arr);
    $combos = 1;
    for($i = $words; $i > 0; $i--) {
      $combos *= $i;
    }
    while(sizeof($combinations) < $combos) {
      shuffle($arr);
      $combo = implode(" ", $arr);
      if(!in_array($combo, $combinations)) {
        $combinations[] = $combo;
      }
    }
    return $combinations;
  }

}

