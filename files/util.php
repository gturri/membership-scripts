<?php

if(!defined('ZWP_TOOLS')){  die(); }
register_shutdown_function( "fatal_handler" );
require_once ZWP_TOOLS . 'logging.php';

function do_curl_query($curl){
  global $loggerInstance;
  $ret = new CurlResult();
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  $ret->response = curl_exec($curl);

  if ( $ret->response === false ){
    $err_msg = "Failed curl query: [" . curl_errno($curl) . "]: " . curl_error($curl);
    $loggerInstance->log_error($err_msg);
    die($err_msg);
  }
  $ret->httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

  curl_close($curl);
  return $ret;
}

class CurlResult {
  public $response;
  public $httpCode;
}


function dateToStr(DateTime $d) : string {
  return $d->format('Y-m-d\TH:i:s');
}

function fatal_handler() {
  global $loggerInstance;
  $errfile = "unknown file";
  $errstr  = "shutdown";
  $errno   = E_CORE_ERROR;
  $errline = 0;

  $error = error_get_last();

  if( $error !== NULL) {
    $errno   = $error["type"];
    $errfile = $error["file"];
    $errline = $error["line"];
    $errstr  = $error["message"];

    $loggerInstance->log_error("A PHP fatal error occured: $errstr (type=$errno, at $errfile:$errline)");
    die();
  }
}


class RegistrationEvent {
  public $helloasso_event_id;
  public $event_date;
  public $amount;
  public $first_name;
  public $last_name;
  public $email;
  public $phone;
  public $address;
  public $postal_code;
  public $birth_date; // String with format dd/mm/yyyy
  public $city;
  public $want_to_be_volunteer; // Beware, this is a string with value either "Oui" or "Non"
  public $is_zw_professional;   // Beware, this is a string with value either "Oui" or "Non"
  public $is_zwf_adherent;      // Beware, this is a string with value either "Oui" or "Non"
  public $how_did_you_know_zwp;
  public $want_to_do;
}

class SimplifiedRegistrationEvent {
  public $first_name;
  public $last_name;
  public $event_date;
  public $email;

  public function __construct($first_name, $last_name, $email, $event_date){
    $this->first_name = $first_name;
    $this->last_name = $last_name;
    $this->email = $email;
    $this->event_date = $event_date;
  }
}

class OutdatedMemberDeleter {
  private $now;
  private $groups;
  private $timeZone = 1;
  private $thisYear;
  private $februaryFirstThisYear;

  public function __construct(DateTime $now, array $groupsWithDeletableUsers){
    $this->now = $now;
    $this->groups = $groupsWithDeletableUsers;
    $this->thisYear = $this->now->format("Y");
    $this->timeZone = new DateTimeZone("Europe/Paris");
    $this->februaryFirstThisYear = new DateTime($this->thisYear . "-02-01", $this->timeZone);
  }

  /**
   * When someone joins during year N, her membership is valid until 31 December of year N.
   * But we want to keep members in the mailing list only on 1st February N+1 (to let time for members
   * to re-new their membership, otherwise we would have 0 members on 1st January at midnight)
   */
  public function getDateAfterWhichMembershipIsConsideredValid() :DateTime {
    if ( $this->now >= $this->februaryFirstThisYear ){
      return new DateTime($this->thisYear . "-01-01", $this->timeZone);
    } else {
      return new DateTime(($this->thisYear-1) . "-01-01", $this->timeZone);
    }
  }

  public function needToDeleteOutdatedMembers(DateTime $lastSuccessfulRun) : bool {
    return $this->now >= $this->februaryFirstThisYear && $lastSuccessfulRun < $this->februaryFirstThisYear;
  }

  public function deleteOutdatedMembersIfNeeded(DateTime $lastSuccessfulRun, MysqlConnector $mysql) : void {
    global $loggerInstance;
    if ( ! $this->needToDeleteOutdatedMembers($lastSuccessfulRun) ){
      $loggerInstance->log_info("No need to delete outdated members");
      return;
    }

    $loggerInstance->log_info("We're going to delete outdated members");
    $mailsToKeep = $mysql->getOrderedListOfLastRegistrations($this->getDateAfterWhichMembershipIsConsideredValid());

    foreach($this->groups as $group){
      $currentUsers = $group->getUsers();
      $usersToDelete = array_diff($currentUsers, $mailsToKeep);
      $group->deleteUsers($usersToDelete);
    }
  }
}

interface GroupWithDeletableUsers {
  public function getUsers(): array;
  public function deleteUsers(array $emails): void;
}