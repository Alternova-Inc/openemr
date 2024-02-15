<?php

/**
 * AppointmentRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\PatientService;
use OpenEMR\Validators\ProcessingResult;

class AppointmentRestController
{
    private $appointmentService;
    private const UNKNOW_FILTER_TYPE = "Unknow";
    private const ARRAY_MODE = 2;
    private const SUPPORTED_FILTER_TYPES = array(
        "puuid",
        "pc_title",
        "pc_eventDate",
        "pc_apptstatus",
        "date_range"
    );
    
    public function __construct()
    {
        $this->appointmentService = new AppointmentService();
    }

    /**
     * Retrieves a single appointment by its UUID.
     *
     * @param string $euuid The UUID of the appointment to retrieve.
     * @return mixed Returns the result of the appointment retrieval operation.
     */
    public function getOne(string $euuid)
    {
        $serviceResult = $this->appointmentService->getAppointment($euuid);
        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }

    /**
     * Updates an appointment by its UUID with the provided data.
     *
     * @param string $euuid The UUID of the appointment to update.
     * @param array $data An array containing the updated appointment data.
     * @return mixed Returns the result of the appointment update operation.
     */
    public function update(string $euuid, array $data)
    {
        $serviceResult = $this->appointmentService->updateAppointment($euuid, $data);
        $validationResult = $this->appointmentService->update_validate($data);

        $validationHandlerResult = RestControllerHelper::validationHandler($validationResult);
        if (is_array($validationHandlerResult)) {
            return $validationHandlerResult;
        }

        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }

    public function getOneForPatient($auuid, $patientUuid)
    {
        $serviceResult = $this->appointmentService->search(['puuid' => $patientUuid, 'pc_uuid' => $auuid]);
        $data = ProcessingResult::extractDataArray($serviceResult);
        return RestControllerHelper::responseHandler($data[0] ?? [], null, 200);
    }

    /**
     * Retrieves all appointments based on the provided filters.
     *
     * @param array $data An array containing the filter criteria.
     * @return mixed Returns the result of retrieving appointments based on the filters.
     */
    public function getAll(array $data)
    {
        $filter_value = "";
        $filter_type = self::UNKNOW_FILTER_TYPE;
        $validSearchFields = array_filter(
            $data,
            fn($key) => in_array($key, self::SUPPORTED_FILTER_TYPES),
            self::ARRAY_MODE
        );
        
        if (!empty($validSearchFields)) {
            $filter_type = key($validSearchFields);
            $filter_value = reset($validSearchFields);        
        }

        $serviceResult = $this->appointmentService->getAppointments(
            $filter_type, $filter_value
        );
    
        return RestControllerHelper::handleProcessingResult($serviceResult, 200);
    }

    public function getAllForPatientByUuid($puuid)
    {
        $patientService = new PatientService();
        $result = ProcessingResult::extractDataArray($patientService->getOne($puuid));
        if (!empty($result)) {
            $serviceResult = $this->appointmentService->getAppointmentsForPatient($result[0]['pid']);
        } else {
            $serviceResult = [];
        }

        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }

    public function getAllForPatient($pid)
    {
        $serviceResult = $this->appointmentService->getAppointmentsForPatient($pid);
        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }

    /**
     * Creates a new appointment for a patient identified by UUID.
     *
     * @param string $puuid The UUID of the patient for whom the appointment is being created.
     * @param array $data An array containing the appointment data.
     * @return mixed Returns the result of the appointment creation operation.
     */
    public function post(string $puuid, array $data)
    {
        $data['puuid'] = $puuid;
        $data['pc_time'] = date("Y-m-d H:i:s");
        $validationResult = $this->appointmentService->validate($data);
        $validationHandlerResult = RestControllerHelper::validationHandler($validationResult);        
        if (is_array($validationHandlerResult)) {
            return $validationHandlerResult;
        }

        $serviceResult = $this->appointmentService->insert($puuid, $data);
        return RestControllerHelper::responseHandler(array("id" => $serviceResult), null, 200);
    }

    /**
     * Deletes an appointment by its UUID.
     *
     * @param string $euuid The UUID of the appointment to delete.
     * @return mixed Returns the result of the appointment deletion operation.
     */
    public function deleteByUuid(string $euuid)
    {
        $serviceResult = $this->appointmentService->deleteAppointmentByUuid($euuid);
        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }

    public function delete($eid)
    {
        try {
            $this->appointmentService->deleteAppointmentRecord($eid);
            $serviceResult = ['message' => 'record deleted'];
        } catch (\Exception $exception) {
            (new SystemLogger())->errorLogCaller($exception->getMessage(), ['trace' => $exception->getTraceAsString(), 'eid' => $eid]);
            return RestControllerHelper::responseHandler(['message' => 'Failed to delete appointment'], null, 500);
        }
        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }
}
