<?php

if(!defined('ZWP_TOOLS')){  die(); }
require_once(ZWP_TOOLS . 'util.php');
require_once(ZWP_TOOLS . 'config.php');

class MysqlConnector {
  const OPTIONS_TABLE = "script_options";
  const OPTION_LASTSUCCESSFULRUN_KEY = "last_successful_run_date";

  public function __construct(){
    global $loggerInstance;
    try {
      $cnxString = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME;
      $this->dbo = new PDO($cnxString, DB_USER, DB_PASSWORD);
      $this->dbo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $this->stmt  = $this->dbo->prepare("INSERT INTO registration_events (id_HelloAsso, date, amount, first_name, last_name, email, phone, birth_date, address, postal_code, city, want_to_be_volunteer, is_zwf_adherent, is_zw_professional, how_did_you_know_zwp, want_to_do) VALUES (:id_HelloAsso, :date, :amount, :first_name, :last_name, :email, :phone, :birth_date, :address, :postal_code, :city, :want_to_be_volunteer, :is_zwf_adherent, :is_zw_professional, :how_did_you_know_zwp, :want_to_do)");
    } catch(PDOException $e){
      $loggerInstance->log_error("Failed to connect to mysql: " . $e->getMessage());
      die();
    }
  }

  function __destruct(){
    $this->dbo = NULL;
    $this->stmt = NULL;
  }

  public function getOrderedListOfLastRegistrations(DateTime $until) : array {
    global $loggerInstance;
    try {
      $stmtGetRegistrations = $this->dbo->prepare(
          "SELECT * from ("
          . "  SELECT first_name, last_name, email, MAX(`date`) AS lastRegistrationDate "
          . "  FROM registration_events"
          . "  GROUP BY email"
          . ") AS tmp"
          . " WHERE lastRegistrationDate > :until"
          . " ORDER BY lastRegistrationDate");
      $strDate = $this->dateTimeToMysqlStr($until); // This variable can't be inlined: it would yield an "Only variables should be passed by reference" error
      $stmtGetRegistrations->bindParam(':until', $strDate);
      $stmtGetRegistrations->execute();
      $ret = array();
      while($row = $stmtGetRegistrations->fetch()){
        $ret[] = new SimplifiedRegistrationEvent($row["first_name"], $row["last_name"], $row["email"], $row["lastRegistrationDate"]);
      }
      return $ret;
    } catch(PDOException $e){
      $loggerInstance->log_error("Failed to retrieve latest registrations from mysql: " . $e->getMessage());
      die();
    }
  }

  /**
   * This method is supposed to be called with the emails of the last members who registered, and it
   * returns information about those of them who were members in the past and who have been deactivated.
   * Currently it's used to send a notification to admins, because the accounts of returning members need
   * to be manually reactivated on some of our tools.
   * @param string[] $membersEmail A list of mail of people who just registered
   * @param DateTime $registeredBefore The date after which we expect users haven't registered
   * @return SimplifiedRegistrationEvent[] data about members in $membersEmail who already registered
   *                                       but who never registered after $registeredBefore
   */
  public function findMembersInArrayWhoDoNotRegisteredAfterGivenDate(array $membersEmail, DateTime $registeredBefore) : array {
    global $loggerInstance;
    if ( count($membersEmail) == 0 ){
      return array();
    }

    try {
      $in = str_repeat('?', count($membersEmail));
      $stmtGetReturningMembers = $this->dbo->prepare(
        "SELECT * FROM ("
        . "  SELECT first_name, last_name, email, MAX(date) AS lastRegistration "
        . "  FROM registration_events"
        . "  WHERE email IN ($in)"
        . "  GROUP BY email"
        . ") AS tmp"
        . " WHERE lastRegistration < ?");
      $params = array_merge($membersEmail, [$this->dateTimeToMysqlStr($registeredBefore)]);
      $stmtGetReturningMembers->execute($params);
      $ret = array();
      foreach($stmtGetReturningMembers->fetchAll() as $row){
        $ret[] = new SimplifiedRegistrationEvent($row["first_name"], $row["last_name"], $row["email"], $row["lastRegistrationDate"]);
      }
      return $ret;
    } catch(PDOException $e){
      $loggerInstance->log_error("Failed to find returning old members from mysql: " . $e->getMessage());
      die();
    }
  }

  public function deleteRegistrationsOlderThan(DateTime $upTo) {
    global $loggerInstance;
    try {
      $stmtDeleteOldRegistrations = $this->dbo->prepare(
          "DELETE from registration_events WHERE date < :upTo"
      );

      $strDate = $this->dateTimeToMysqlStr($upTo); // This variable can't be inlined: it would yield an "Only variables should be passed by reference" error
      $stmtDeleteOldRegistrations->bindParam(':upTo', $strDate);
      $ret = $stmtDeleteOldRegistrations->execute();
      if ($ret === FALSE) {
        $loggerInstance->log_error("delete query returned FALSE. Something unexpected went wrong");
        die();
      }
    } catch(PDOException $e){
      $loggerInstance->log_error("Failed to delete old registrations: " . $e->getMessage());
      die();
    }
  }

  private function dateTimeToMysqlStr(DateTime $d) : string {
    return $d->format('Y-m-d\TH:i:s');
  }

  public function registerEvent(RegistrationEvent $event){
    global $loggerInstance;
    try {
      $loggerInstance->log_info("Going to register on mysql user " . $event->first_name . " " . $event->last_name);
      $want_to_be_volunteer = $event->want_to_be_volunteer == "Oui";
      $is_zwf_adherent = $event->is_zwf_adherent == "Oui";
      $is_zw_professional = $event->is_zw_professional == "Oui";
      $birth_date = is_null($event->birth_date) ? null : DateTime::createFromFormat('d/m/Y', $event->birth_date)->format('Y-m-d');

      $this->stmt->bindParam(':id_HelloAsso', $event->helloasso_event_id);
      $this->stmt->bindParam(':date', $event->event_date);
      $this->stmt->bindParam(':amount', $event->amount);
      $this->stmt->bindParam(':first_name', $event->first_name);
      $this->stmt->bindParam(':last_name', $event->last_name);
      $this->stmt->bindParam(':email', $event->email);
      $this->stmt->bindParam(':phone', $event->phone);
      $this->stmt->bindParam(':birth_date', $birth_date);
      $this->stmt->bindParam(':address', $event->address);
      $this->stmt->bindParam(':postal_code', $event->postal_code);
      $this->stmt->bindParam(':city', $event->city);
      $this->stmt->bindParam(':want_to_be_volunteer', $want_to_be_volunteer);
      $this->stmt->bindParam(':is_zwf_adherent', $is_zwf_adherent);
      $this->stmt->bindParam(':is_zw_professional', $is_zw_professional);
      $this->stmt->bindParam(':how_did_you_know_zwp', $event->how_did_you_know_zwp);
      $this->stmt->bindParam(':want_to_do', $event->want_to_do);

      $this->stmt->execute();
      $loggerInstance->log_info("Done with this mysql registration");
    } catch(PDOException $e){
      if($e->errorInfo[1] == 1062){ // case of duplicated entry. See https://dev.mysql.com/doc/refman/8.0/en/server-error-reference.html#error_er_dup_entry
        $loggerInstance->log_info("Event with idHelloAsso " . $event->helloasso_event_id . " already registered. Skipping");
      } else {
        $loggerInstance->log_error("Failed to insert event to mysql: " . $e->getMessage());
        die();
      }
    }
  }

  public function readLastSuccessfulRunStartDate() : DateTime {
    global $loggerInstance;
    $data = $this->readOption(self::OPTION_LASTSUCCESSFULRUN_KEY);
    $date = unserialize($data);
    if ($date === FALSE){
      $loggerInstance->log_error("Failed to deserialize last successful run start date. data in db may be corrupted. Got: $data");
      die();
    }
    return $date;
  }

  private function readOption(string $key) : string {
    global $loggerInstance;
    try {
      $stmtOpt = $this->dbo->prepare("SELECT value FROM " . self::OPTIONS_TABLE . " WHERE `key`= :key");
      $stmtOpt->bindParam(':key', $key);
      $stmtOpt->execute();
      $row = $stmtOpt->fetch();
      if ( $row === FALSE ){
        $loggerInstance->log_error("Failed to load from sql option with key $key because it seems absent");
        die();
      }
      return $row["value"];
    } catch(PDOException $e){
      $loggerInstance->log_error("Failed load from sql option with key $key because of error $e->getMessage())");
    }
  }

  public function writeLastSuccessfulRunStartDate(DateTime $startDate) : void{
    $this->writeOption(self::OPTION_LASTSUCCESSFULRUN_KEY, serialize($startDate));
  }

  private function writeOption(string $key, string $value) : void {
    global $loggerInstance;
    try {
      $stmtOpt = $this->dbo->prepare("UPDATE " . self::OPTIONS_TABLE . " SET value=:value WHERE `key`=:key");
      $stmtOpt->bindParam(':value', $value);
      $stmtOpt->bindParam(':key', $key);
      $stmtOpt->execute();
    } catch(PDOException $e){
      $loggerInstance->log_error("Failed write to sql option with key $key and value $value because of error $e->getMessage())");
    }
  }
}
