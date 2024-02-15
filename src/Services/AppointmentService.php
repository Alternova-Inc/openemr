<?php

/**
 * AppointmentService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use MongoDB\Driver\Query;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Events\Services\ServiceDeleteEvent;
use OpenEMR\Services\Search\DateSearchField;
use OpenEMR\Services\Search\FhirSearchWhereClauseBuilder;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use OpenEMR\Validators\ProcessingResult;
use Particle\Validator\Exception\InvalidValueException;
use Particle\Validator\Validator;

class AppointmentService extends BaseService
{
    const TABLE_NAME = "openemr_postcalendar_events";
    const PATIENT_TABLE = "patient_data";
    const PRACTITIONER_TABLE = "users";
    const FACILITY_TABLE = "facility";

    private const FILTER_METHODS_MAPPER = array(
        "puuid" => "getAppointmentsByPatientUuid",
        "pc_title" => "getAppointmentsByTitle",
        "pc_eventDate" => "getAppointmentsByDate",
        "pc_apptstatus" => "getAppointmentsStatus",
        "date_range" => "getAppointmentsByDateRange"
    );

    /**
     * @var EncounterService
     */
    private $encounterService;

    /**
     * @var PatientService
     */
    private $patientService;

  /**
   * Default constructor.
   */
    public function __construct()
    {
        parent::__construct(self::TABLE_NAME);
        UuidRegistry::createMissingUuidsForTables([self::TABLE_NAME, self::PATIENT_TABLE, self::PRACTITIONER_TABLE,
            self::FACILITY_TABLE]);
    }

    public function setEncounterService(EncounterService $service)
    {
        $this->encounterService = $service;
    }

    public function getEncounterService()
    {
        if (empty($this->encounterService)) {
            $this->encounterService = new EncounterService();
        }
        return $this->encounterService;
    }

    public function setPatientService(PatientService $patientService)
    {
        $this->patientService = $patientService;
    }

    public function getPatientService()
    {
        if (empty($this->patientService)) {
            $this->patientService = new PatientService();
        }
        return $this->patientService;
    }

    public function getUuidFields(): array
    {
        return ['puuid', 'pce_aid_uuid', 'pc_uuid', 'facility_uuid', 'billing_location_uuid' ];
    }

    /**
     * Validates the data for updating an appointment.
     *
     * @param array $appointment An array containing the appointment data to be validated.
     * @return ValidationResult Returns the result of the validation operation.
     */
    public function update_validate(array $appointment)
    {
        $validator = new Validator();

        $validator->optional('pc_title')->string();
        $validator->optional('pc_room')->string();
        $validator->optional('pc_eventDate')->datetime('Y-m-d');
        $validator->optional('pc_hometext')->string();
        $validator->optional('pc_startTime')->length(5);
        $validator->optional('pc_endTime')->length(5);
        $validator->optional('pc_duration')->numeric();
        $validator->optional('pc_apptstatus')->string();

        return $validator->validate($appointment);
    }

    public function validate($appointment)
    {
        $validator = new Validator();

        $validator->required('pc_catid')->numeric();
        $validator->required('pc_title')->lengthBetween(2, 150);
        $validator->required('pc_duration')->numeric();
        $validator->required('pc_room')->string();
        $validator->required('pc_hometext')->string();
        $validator->required('pc_apptstatus')->string();
        $validator->required('pc_eventDate')->datetime('Y-m-d');
        $validator->required('pc_startTime')->length(5); // HH:MM is 5 chars
        $validator->required('pc_endTime')->length(5); // HH:MM is 5 chars
        $validator->required('pc_facility')->numeric();
        $validator->required('pc_billing_location')->numeric();
        $validator->optional('pc_aid')->numeric()
            ->callback(function ($value, $data) {
                $id = QueryUtils::fetchSingleValue('Select id FROM users WHERE id = ? ', 'id', [$value]);
                if (empty($id)) {
                    throw new InvalidValueException('pc_aid must be for a valid user', 'pc_aid');
                }
                return true;
            });
        $validator->optional('puuid')->callback(function ($value, $data) {
            $puuidBinary = $this->decodeUuid($value);
            $account_sql = "SELECT * FROM `patient_data` WHERE `uuid` = ?";
            $uuid = sqlQuery($account_sql, array($puuidBinary));
            if (empty($uuid)) {
                throw new InvalidValueException('uuid must be for a valid patient', 'uuid');
            }
            return true;
        });

        return $validator->validate($appointment);
    }

    public function search($search, $isAndCondition = true)
    {
        $sql = "SELECT pce.pc_eid,
                       pce.pc_uuid,
                       pd.puuid,
                       pd.fname,
                       pd.lname,
                       pd.DOB,
                       pd.pid,
                       providers.uuid AS pce_aid_uuid,
                       providers.npi AS pce_aid_npi,
                       pce.pc_aid,
                       pce.pc_apptstatus,
                       pce.pc_eventDate,
                       pce.pc_startTime,
                       pce.pc_endTime,
                       pce.pc_time,
              	       pce.pc_facility,
                       pce.pc_billing_location,
                       pce.pc_catid,
                       pce.pc_pid,
                       pce.pc_duration,
                       f1.name as facility_name,
                       f1_map.uuid as facility_uuid,
                       f2.name as billing_location_name,
                       f2_map.uuid as billing_location_uuid
                       FROM (
                             SELECT
                               pc_eid,
                               uuid AS pc_uuid, -- we do this because our uuid registry requires the field to be named this way
                               pc_aid,
                               pc_apptstatus,
                               pc_eventDate,
                               pc_startTime,
                               pc_duration,
                               pc_endTime,
                               pc_time,
                               pc_facility,
                               pc_billing_location,
                               pc_catid,
                               pc_pid
                            FROM
                                 openemr_postcalendar_events
                       ) pce
                       LEFT JOIN facility as f1 ON pce.pc_facility = f1.id
                       LEFT JOIN uuid_mapping as f1_map ON f1_map.target_uuid=f1.uuid AND f1_map.resource='Location'
                       LEFT JOIN facility as f2 ON pce.pc_billing_location = f2.id
                       LEFT JOIN uuid_mapping as f2_map ON f2_map.target_uuid=f2.uuid AND f2_map.resource='Location'
                       LEFT JOIN (
                           select uuid AS puuid
                           ,fname
                           ,lname
                           ,DOB
                           ,pid
                           FROM
                                patient_data
                      ) pd ON pd.pid = pce.pc_pid
                       LEFT JOIN users as providers ON pce.pc_aid = providers.id";

        $whereClause = FhirSearchWhereClauseBuilder::build($search, $isAndCondition);

        $sql .= $whereClause->getFragment();
        $sqlBindArray = $whereClause->getBoundValues();
        $statementResults =  QueryUtils::sqlStatementThrowException($sql, $sqlBindArray);

        $processingResult = new ProcessingResult();
        while ($row = sqlFetchArray($statementResults)) {
            $processingResult->addData($this->createResultRecordFromDatabaseResult($row));
        }

        return $processingResult;
    }

    /**
     * Decodes a UUID string into bytes.
     *
     * @param string $puuid The UUID string to decode.
     * @return string|null Returns the decoded UUID as bytes, or null if decoding fails.
     */
    private function decodeUuid(string $puuid)
    {
        try{
            return UuidRegistry::uuidToBytes($puuid);
        } catch (\Exception $e) {}
    }

    /**
     * Transforms the query result into a custom JSON object.
     *
     * @param mixed $result The result of the query.
     * @return array Returns an array containing the transformed result.
     */
    private function renderResults($result): array
    {
        $results = array();
        while ($row = sqlFetchArray($result)) {
            array_push($results, array(
                "pc_uuid" => UuidRegistry::uuidToString($row["pc_uuid"]),
                "puuid" => UuidRegistry::uuidToString($row["puuid"]),
                "pc_title" => $row["pc_title"],
                "pc_hometext" => $row["pc_hometext"],
                "pc_time" => $row["pc_time"],
                "pd_fname" => $row["fname"],
                "pd_lname" => $row["lname"],
                "pd_email" => $row["email"],
                "pd_drivers_license" => $row["drivers_license"],
                "pc_room" => $row["pc_room"],
                "pc_eventDate" => $row["pc_eventDate"],
                "pc_startTime" => $row["pc_startTime"],
                "pc_endTime" => $row["pc_endTime"],
                "pc_duration" => $row["pc_duration"],
                "pc_apptstatus" => $row["pc_apptstatus"],
            ));
        }
        return $results;
    }

    /**
     * Retrieves all medical appointments that are in the database.
     *
     * @param string $sql The SQL query string to retrieve appointments.
     * @return ADORecordSet_mysqli Returns the result set of all appointments.
     */
    private function getAllAppointments(string $sql)
    {
        $sql .= " ORDER BY `pce`.`pc_time` DESC";
        return sqlStatement($sql);
    }

    /**
     * Allows filtering medical appointments by patient UUID.
     *
     * @param string $puuid The UUID of the patient to filter appointments.
     * @param string $sql The SQL query string to which the filter condition will be appended.
     * @return ADORecordSet_mysqli|null Returns the result set of filtered appointments if patient is found; otherwise, returns null.
     */
    private function getAppointmentsByPatientUuid(string $puuid, string $sql)
    {
        $puuidBinary = $this->decodeUuid($puuid);
        $account_sql = "SELECT * FROM `patient_data` WHERE `uuid` = ?";
        $patient = sqlQuery($account_sql, array($puuidBinary));

        if(!empty($patient)) {
            $sql .= " WHERE `pd`.`pid` = " . $patient["pid"];
            $sql .= " ORDER BY `pce`.`pc_time` DESC";
            return sqlStatement($sql);
        }

        return null;
    }

    /**
     * Allows filtering medical appointments by a specific date.
     *
     * @param string $date The date to filter appointments.
     * @param string $sql The SQL query string to which the filter condition will be appended.
     * @return ADORecordSet_mysqli Returns the result set of filtered appointments.
     */
    private function getAppointmentsByDate(string $date, string $sql)
    {
        $sql .= " WHERE `pce` . `pc_eventDate` = '$date'";
        $sql .= " ORDER BY `pce`.`pc_time` DESC";
        return sqlStatement($sql);
    }

    /**
     * Allows filtering medical appointments within a date range.
     *
     * @param string $dateRange The date range in the format "start_date:end_date" to filter appointments.
     * @param string $sql The SQL query string to which the filter condition will be appended.
     * @return ADORecordSet_mysqli Returns the result set of filtered appointments.
     */
    private function getAppointmentsByDateRange(string $dateRange, string $sql)
    {
        $dateRange = str_replace(" ", "", $dateRange);
        $split_date = explode(":", $dateRange);
        $start_date = $split_date[0];
        $end_range = $split_date[1];

        $sql .= " WHERE `pce` . `pc_eventDate` BETWEEN '$start_date' AND '$end_range'";
        $sql .= " ORDER BY `pce`.`pc_time` DESC";
        return sqlStatement($sql);
    }

    /**
     * Allows filtering medical appointments based on their title.
     *
     * @param string $title The title of appointments to filter.
     * @param string $sql The SQL query string to which the filter condition will be appended.
     * @return ADORecordSet_mysqli Returns the result set of filtered appointments.
     */
    private function getAppointmentsByTitle(string $title, string $sql)
    {
        $sql .= " WHERE LOWER(`pc_title`) LIKE '%$title%'";
        $sql .= " ORDER BY `pce`.`pc_time` DESC";
        return sqlStatement($sql);
    }

    /**
     * Allows filtering medical appointments based on their status.
     *
     * @param string $status The status of appointments to filter.
     * @param string $sql The SQL query string to which the filter condition will be appended.
     * @return ADORecordSet_mysqli Returns the result set of filtered appointments.
     */
    private function getAppointmentsStatus(string $status, string $sql)
    {
        $sql .= " WHERE LOWER(`pc_apptstatus`) LIKE '%$status%'";
        $sql .= " ORDER BY `pce`.`pc_time` DESC";
        return sqlStatement($sql);
    }

    /**
     * Retrieves a list of appointments available in the database.
     *
     * @param string $filter_type The type of filter to apply.
     * @param string $value The value to filter appointments by.
     * @return ProcessingResult Returns a ProcessingResult object containing the retrieved appointments.
     */
    public function getAppointments(string $filter_type, string $value): ProcessingResult
    {
        $queryset = null;
        
        $sql .= "SELECT pce.pc_eid, pce.uuid AS pc_uuid, pd.uuid AS puuid,";
        $sql .= "pd.fname, pd.lname, pd.drivers_license, pd.pid, pd.email, pce.pc_apptstatus,";
        $sql .= "pce.pc_eventDate, pce.pc_startTime, pce.pc_endTime, pce.pc_time,";
        $sql .= "pce.pc_pid,pce.pc_hometext, pce.pc_title, pce.pc_room,pce.pc_duration ";
        $sql .= "FROM `openemr_postcalendar_events` AS `pce`";
        $sql .= "LEFT JOIN `patient_data` AS `pd` ON `pd`.`pid` = `pce`.`pc_pid`";

        if (array_key_exists($filter_type, self::FILTER_METHODS_MAPPER)) {
            $queryset = $this->{self::FILTER_METHODS_MAPPER[$filter_type]}($value, $sql);
        } else {
            $queryset = $this->getAllAppointments($sql);
        }

        $data = $this->renderResults($queryset);
        $processingResult = new ProcessingResult();
        $processingResult->addData($data);
        return $processingResult;
    }

    /**
     * Updates the data of a medical appointment.
     *
     * @param string $euuid The UUID of the appointment.
     * @param array $data An array containing the updated appointment data.
     * @return array|null Returns an array with the updated appointment data if successful; otherwise, returns null.
     */
    public function updateAppointment(string $euuid, array $data): array|null
    {
        $puuidBinary = $this->decodeUuid($euuid);

        if(!$puuidBinary) {
            return null;
        }

        $query = $this->buildUpdateColumns($data);
        $sql = " UPDATE openemr_postcalendar_events SET ";
        $sql .= $query['set'];
        $sql .= " WHERE `uuid` = ?";

        array_push($query['bind'], $puuidBinary);
        sqlStatement($sql, $query['bind']);
        return $this->getAppointment($euuid);
    }

    public function getAppointmentsForPatient($pid)
    {
        $sqlBindArray = array();

        $sql = "SELECT pce.pc_eid,
                       pce.uuid AS pc_uuid,
                       pd.fname,
                       pd.lname,
                       pd.DOB,
                       pd.pid,
                       pd.email,
                       pd.uuid AS puuid,
                       providers.uuid AS pce_aid_uuid,
                       providers.npi AS pce_aid_npi,
                       pce.pc_aid,
                       pce.pc_apptstatus,
                       pce.pc_eventDate,
                       pce.pc_startTime,
                       pce.pc_endTime,
                       pce.pc_time,
              	       pce.pc_facility,
                       pce.pc_billing_location,
                       pce.pc_catid,
                       pce.pc_pid,
                       pce.pc_hometext,
                       pce.pc_room,
                       pce.pc_duration,
                       f1.name as facility_name,
                       f1_map.uuid as facility_uuid,
                       f2.name as billing_location_name,
                       f2_map.uuid as billing_location_uuid
                       FROM openemr_postcalendar_events as pce
                       LEFT JOIN facility as f1 ON pce.pc_facility = f1.id
                       LEFT JOIN uuid_mapping as f1_map ON f1_map.target_uuid=f1.uuid AND f1_map.resource='Location'
                       LEFT JOIN facility as f2 ON pce.pc_billing_location = f2.id
                       LEFT JOIN uuid_mapping as f2_map ON f2_map.target_uuid=f2.uuid AND f2_map.resource='Location'
                       LEFT JOIN patient_data as pd ON pd.pid = pce.pc_pid
                       LEFT JOIN users as providers ON pce.pc_aid = providers.id";

        if ($pid) {
            $sql .= " WHERE pd.pid = ?";
            array_push($sqlBindArray, $pid);
        }

        $records = QueryUtils::fetchRecords($sql, $sqlBindArray);
        $finalRecords = [];
        if (!empty($records)) {
            foreach ($records as $record) {
                $finalRecords[] = $this->createResultRecordFromDatabaseResult($record);
            }
        }
        return $finalRecords;
    }
    
    public function getAppointment(string $euuid)
    {
        $puuidBinary = $this->decodeUuid($euuid);
        $sql = "SELECT pce.pc_eid,
                       pce.uuid AS pc_uuid,
                       pd.fname,
                       pd.lname,
                       pd.DOB,
                       pd.pid,
                       pd.email,
                       pd.uuid AS puuid,
                       providers.uuid AS pce_aid_uuid,
                       providers.npi AS pce_aid_npi,
                       pce.pc_aid,
                       pce.pc_title,
                       pce.pc_apptstatus,
                       pce.pc_eventDate,
                       pce.pc_startTime,
                       pce.pc_endTime,
                       pce.pc_time,
                       pce.pc_duration,
              	       pce.pc_facility,
                       pce.pc_billing_location,
                       pce.pc_catid,
                       pce.pc_room,
                       pce.pc_pid,
                       pce.pc_hometext,
                       f1.name as facility_name,
                       f1_map.uuid as facility_uuid,
                       f2.name as billing_location_name,
                       f2_map.uuid as billing_location_uuid
                       FROM openemr_postcalendar_events as pce
                       LEFT JOIN facility as f1 ON pce.pc_facility = f1.id
                       LEFT JOIN uuid_mapping as f1_map ON f1_map.target_uuid=f1.uuid AND f1_map.resource='Location'
                       LEFT JOIN facility as f2 ON pce.pc_billing_location = f2.id
                       LEFT JOIN uuid_mapping as f2_map ON f2_map.target_uuid=f2.uuid AND f2_map.resource='Location'
                       LEFT JOIN patient_data as pd ON pd.pid = pce.pc_pid
                       LEFT JOIN users as providers ON pce.pc_aid = providers.id
                       WHERE pce.uuid = ?";

        $records = QueryUtils::fetchRecords($sql, [$puuidBinary]);
        $finalRecords = [];
        if (!empty($records)) {
            foreach ($records as $record) {
                $finalRecords[] = $this->createResultRecordFromDatabaseResult($record);
            }
        }
        return $finalRecords;
    }

    /**
     * Inserts a new medical appointment into the database.
     *
     * @param string $puuid The UUID of the patient for whom the appointment is being inserted.
     * @param array $data An array containing the appointment data.
     * @return mixed|null Returns the result of the insertion operation if successful; otherwise, returns null.
     */
    public function insert(string $puuid, array $data)
    {
        $puuidBinary = $this->decodeUuid($puuid);
        $account_sql = "SELECT * FROM `patient_data` WHERE `uuid` = ?";
        $patient = sqlQuery($account_sql, array($puuidBinary));

        if(!empty($patient)) {
            $pid = $patient["pid"];
            $uuid = (new UuidRegistry())->createUuid();
            $sql  = " INSERT INTO openemr_postcalendar_events SET";
            $sql .= "     uuid=?,";
            $sql .= "     pc_pid=?,";
            $sql .= "     pc_catid=?,";
            $sql .= "     pc_title=?,";
            $sql .= "     pc_duration=?,";
            $sql .= "     pc_hometext=?,";
            $sql .= "     pc_eventDate=?,";
            $sql .= "     pc_apptstatus=?,";
            $sql .= "     pc_startTime=?,";
            $sql .= "     pc_endTime=?,";
            $sql .= "     pc_facility=?,";
            $sql .= "     pc_billing_location=?,";
            $sql .= "     pc_informant=1,";
            $sql .= "     pc_eventstatus=1,";
            $sql .= "     pc_sharing=1,";
            $sql .= "     pc_aid=?,";
            $sql .= "     pc_room=?,";
            $sql .= "     pc_time=?";

            $results = sqlInsert(
                $sql,
                array(
                    $uuid,
                    $pid,
                    $data["pc_catid"],
                    $data["pc_title"],
                    $data["pc_duration"],
                    $data["pc_hometext"],
                    $data["pc_eventDate"],
                    $data['pc_apptstatus'],
                    $data["pc_startTime"],
                    $data["pc_endTime"],
                    $data["pc_facility"],
                    $data["pc_billing_location"],
                    $data["pc_aid"] ?? null,
                    $data["pc_room"],
                    $data["pc_time"],                    
                )
            );
            return $results;   
        }
    }

    /**
     * @param $eid
     * @param $recurr_affect
     * @param $event_selected_date
     * @return void
     */
    public function deleteAppointment($eid, $recurr_affect, $event_selected_date)
    {
        // =======================================
        //  multi providers event
        // =======================================
        if ($GLOBALS['select_multi_providers']) {
            // what is multiple key around this $eid?
            $row = sqlQuery("SELECT pc_multiple FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));

            // obtain current list of providers regarding the multiple key
            $providers_current = array();
            $up = sqlStatement("SELECT pc_aid FROM openemr_postcalendar_events WHERE pc_multiple=?", array($row['pc_multiple']));
            while ($current = sqlFetchArray($up)) {
                $providers_current[] = $current['pc_aid'];
            }

            // establish a WHERE clause
            if ($row['pc_multiple']) {
                $whereClause = "pc_multiple = ?";
                $whereBind = $row['pc_multiple'];
            } else {
                $whereClause = "pc_eid = ?";
                $whereBind = $eid;
            }

            if ($recurr_affect == 'current') {
                // update all existing event records to exclude the current date
                foreach ($providers_current as $provider) {
                    // update the provider's original event
                    // get the original event's repeat specs
                    $origEvent = sqlQuery("SELECT pc_recurrspec FROM openemr_postcalendar_events " .
                        " WHERE pc_aid <=> ? AND pc_multiple=?", array($provider,$row['pc_multiple']));
                    $oldRecurrspec = unserialize($origEvent['pc_recurrspec'], ['allowed_classes' => false]);
                    $selected_date = date("Y-m-d", strtotime($event_selected_date));
                    if ($oldRecurrspec['exdate'] != "") {
                        $oldRecurrspec['exdate'] .= "," . $selected_date;
                    } else {
                        $oldRecurrspec['exdate'] .= $selected_date;
                    }

                    // mod original event recur specs to exclude this date
                    sqlStatement("UPDATE openemr_postcalendar_events SET " .
                        " pc_recurrspec = ? " .
                        " WHERE " . $whereClause, array(serialize($oldRecurrspec), $whereBind));
                }
            } elseif ($recurr_affect == 'future') {
                // update all existing event records to stop recurring on this date-1
                $selected_date = date("Y-m-d", (strtotime($event_selected_date) - 24 * 60 * 60));
                foreach ($providers_current as $provider) {
                    // In case of a change in the middle of the event
                    if (strcmp($_POST['event_start_date'], $event_selected_date) != 0) {
                        // update the provider's original event
                        sqlStatement("UPDATE openemr_postcalendar_events SET " .
                            " pc_enddate = ? " .
                            " WHERE " . $whereClause, array($selected_date), $whereBind);
                    } else { // In case of a change in the event head
                        // as we need to notify events that we are deleting this record we need to grab all of the pc_eid
                        // so we can process the events
                        $pc_eids = QueryUtils::fetchTableColumn(
                            "SELECT pc_eid FROM openemr_postcalendar_events WHERE " . $whereClause,
                            'pc_eid',
                            [$whereBind]
                        );
                        foreach ($pc_eids as $pc_eid) {
                            $this->deleteAppointmentRecord($pc_eid);
                        }
                    }
                }
            } else {
                // really delete the event from the database
                // as we need to notify events that we are deleting this record we need to grab all of the pc_eid
                // so we can process the events
                $pc_eids = QueryUtils::fetchTableColumn(
                    "SELECT pc_eid FROM openemr_postcalendar_events WHERE " . $whereClause,
                    'pc_eid',
                    [$whereBind]
                );
                foreach ($pc_eids as $pc_eid) {
                    $this->deleteAppointmentRecord($pc_eid);
                }
            }
        } else { //  single provider event
            if ($recurr_affect == 'current') {
                // mod original event recur specs to exclude this date
                // get the original event's repeat specs
                $origEvent = sqlQuery("SELECT pc_recurrspec FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));
                $oldRecurrspec = unserialize($origEvent['pc_recurrspec'], ['allowed_classes' => false]);
                $selected_date = date("Ymd", strtotime($_POST['selected_date']));
                if ($oldRecurrspec['exdate'] != "") {
                    $oldRecurrspec['exdate'] .= "," . $selected_date;
                } else {
                    $oldRecurrspec['exdate'] .= $selected_date;
                }

                sqlStatement("UPDATE openemr_postcalendar_events SET " .
                    " pc_recurrspec = ? " .
                    " WHERE pc_eid = ?", array(serialize($oldRecurrspec),$eid));
            } elseif ($recurr_affect == 'future') {
                // mod original event to stop recurring on this date-1
                $selected_date = date("Ymd", (strtotime($_POST['selected_date']) - 24 * 60 * 60));
                sqlStatement("UPDATE openemr_postcalendar_events SET " .
                    " pc_enddate = ? " .
                    " WHERE pc_eid = ?", array($selected_date,$eid));
            } else {
                // fully delete the event from the database
                $this->deleteAppointmentRecord($eid);
            }
        }
    }

    /**
     * Removes a medical appointment for a patient using the appointment's UUID.
     *
     * @param string $euuid The UUID of the appointment.
     * @return string|null Returns "ok" if the appointment is deleted successfully; otherwise, returns null.
     */
    public function deleteAppointmentByUuid(string $euuid): string|null
    {
        $puuidBinary = $this->decodeUuid($euuid);
        $sql = "DELETE FROM `openemr_postcalendar_events`";
        $sql .= " WHERE `uuid` = ?";

        if($this->getAppointment($euuid)) {
            sqlQuery($sql, array($puuidBinary));
            return "ok";
        }

        return null;
    }

    public function deleteAppointmentRecord($eid)
    {
        $servicePreDeleteEvent = new ServiceDeleteEvent($this, $eid);
        $this->getEventDispatcher()->dispatch($servicePreDeleteEvent, ServiceDeleteEvent::EVENT_PRE_DELETE);
        QueryUtils::sqlStatementThrowException("DELETE FROM openemr_postcalendar_events WHERE pc_eid = ?", $eid);
        $servicePostDeleteEvent = new ServiceDeleteEvent($this, $eid);
        $this->getEventDispatcher()->dispatch($servicePostDeleteEvent, ServiceDeleteEvent::EVENT_POST_DELETE);
    }

    /**
     * Returns a list of categories
     * @return array
     */
    public function getCalendarCategories()
    {
        $sql = "SELECT pc_catid, pc_constant_id, pc_catname, pc_cattype,aco_spec FROM openemr_postcalendar_categories "
        . " WHERE pc_active = 1 ORDER BY pc_seq";
        return QueryUtils::fetchRecords($sql);
    }

    /**
     * check to see if a status code exist as a check in
     * @param $option
     * @return bool
     */
    public function isCheckInStatus($option)
    {
        $row = sqlQuery("SELECT toggle_setting_1 FROM list_options WHERE " .
            "list_id = 'apptstat' AND option_id = ? AND activity = 1", array($option));
        if (empty($row['toggle_setting_1'])) {
            return(false);
        }

        return(true);
    }

    /**
     * check to see if a status code exist as a check out
     * @param $option
     * @return bool
     */
    public function isCheckOutStatus($option)
    {
        $row = sqlQuery("SELECT toggle_setting_2 FROM list_options WHERE " .
            "list_id = 'apptstat' AND option_id = ? AND activity = 1", array($option));
        if (empty($row['toggle_setting_2'])) {
            return(false);
        }

        return(true);
    }

    public function isPendingStatus($option)
    {
        // TODO: @adunsulag is there ANY way to track this in the database of what statii are pending?
        if ($option == '^') {
            return true;
        }
        return false;
    }

    /**
     * Returns a list of appointment statuses (also used with encounters).
     * @return array
     */
    public function getAppointmentStatuses()
    {
        $listService = new ListService();
        $options = $listService->getOptionsByListName('apptstat', ['activity' => 1]);
        return $options;
    }

    /**
     * Checks to see if the passed in status is a valid appointment status for calendar appointments.
     * @param $status_option_id The status to check if its a valid appointment status
     * @return bool True if its valid, false otherwise
     */
    public function isValidAppointmentStatus($status_option_id)
    {
        $listService = new ListService();
        $option = $listService->getListOption('apptstat', $status_option_id);
        if (!empty($option)) {
            return true;
        }
        return false;
    }

    /**
     * Updates the status for an appointment.  TODO: should be refactored at some point to update the entire record
     * @param $eid number The id of the appointment event
     * @param $status string The status the appointment event should be set to.
     * @param $user number The user performing the update
     * @param $encounter number The encounter of the appointment
     */
    public function updateAppointmentStatus($eid, $status, $user, $encounter = '')
    {
        $appt = $this->getAppointment($eid);
        if (empty($appt)) {
            throw new \InvalidArgumentException("Appointment does not exist for eid " . $eid);
        } else {
            // TODO: Not sure why getAppointment returns an array of records instead of a single record
            $appt = $appt[0];
        }

        $sql = "UPDATE " . self::TABLE_NAME . " SET pc_apptstatus = ? WHERE pc_eid = ? ";
        $binds = [$status, $eid];

        if (!empty($appt['pid'])) {
            $trackerService = new PatientTrackerService();
            $trackerService->manage_tracker_status($appt['pc_eventDate'], $appt['pc_startTime'], $eid, $appt['pid'], $user, $status, $appt['pc_room'], $encounter);
        } else {
            $this->getLogger()->error("AppointmentService->updateAppointmentStatus() failed to update manage_tracker_status"
            . " as patient pid was empty", ['pc_eid' => $eid, 'status' => $status, 'user' => $user, 'encounter' => $encounter]);
        }
        return QueryUtils::sqlStatementThrowException($sql, $binds);
    }

    /**
     * @param $eid
     * @param $pid
     * @return array The most recent encounter for a given appointment
     */
    public function getEncounterForAppointment($pc_eid, $pid)
    {
        $appointment = $this->getAppointment($pc_eid)[0];
        $date = $appointment['pc_eventDate'];
        // we grab the most recent encounter for today's date for the given patient
        $encounterService = $this->getEncounterService();
        $dateField = new DateSearchField('date', ['eq' . $date], DateSearchField::DATE_TYPE_DATE);
        $pidField = new TokenSearchField('pid', [new TokenSearchValue($pid)]);
        // returns the most recent encounter for the given appointment..
        // TODO: @adunsulag we should look at in the future of making an actual join table between encounters and appointments...
        // this fuzzy match by date seems like it will have major problems for both inpatient settings as well as any kind
        // of emergency care (patient sees doctor, patient does telehealth visit during the night due to crisis situation).
        $encounterResult = $encounterService->search(['date' => $dateField, 'pid' => $pidField], true, null, ['limit' => 1]);
        if ($encounterResult->hasData()) {
            $result = $encounterResult->getData();
            return array_pop($result);
        }
        return null;
    }

    public function createEncounterForAppointment($eid)
    {
        $appointment = $this->getAppointment($eid)[0];
        $patientService = $this->getPatientService();
        $patientUuid = UuidRegistry::uuidToString($patientService->getUuid($appointment['pid']));

        $userService = new UserService();
        $user = $userService->getUser($appointment['pc_aid']);
        $authGroup = UserService::getAuthGroupForUser($user['username']);

        $pos_code = QueryUtils::fetchSingleValue(
            "SELECT pos_code FROM facility WHERE id = ?",
            'pos_code',
            [$appointment['pc_facility']]
        );

        $data = [
            'pc_catid' => $appointment['pc_catid']
            // TODO: where would we get this information if it wasn't defaulted to ambulatory?  Should this be a globals setting?
            // this is imitating the work from encounter_events.inc.php::todaysEncounterCheck
            ,'class_code' => EncounterService::DEFAULT_CLASS_CODE
            ,'puuid' => $patientUuid
            ,'pid' => $appointment['pid']
            ,'provider_id' => $user['id']
            ,'reason' => $appointment['pc_hometext'] ?? xl('Please indicate visit reason')
            ,'facility_id' => $appointment['pc_facility']
            ,'billing_facility' => $appointment['pc_billing_location']
            ,'pos_code' => $pos_code
            ,'user' => $user['username']
            ,'group' => $authGroup
        ];

        $encounterService = $this->getEncounterService();
        $result = $encounterService->insertEncounter($patientUuid, $data);
        if ($result->hasData()) {
            $result = $result->getData();
            return $result[0]['encounter'];
        }
        return null;
    }

    /**
     * Returns the calendar category record from a supplied category id
     * @return array
     */
    public function getOneCalendarCategory($cat_id)
    {
        $sql = "SELECT * FROM openemr_postcalendar_categories WHERE pc_catid = ?";
        return QueryUtils::fetchRecords($sql, [$cat_id]);
    }
}
