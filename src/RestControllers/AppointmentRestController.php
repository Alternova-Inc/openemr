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
use OpenEMR\Validators\AppointmentValidator;


class AppointmentRestController
{
    private $appointmentService;
    private const SUPPORTED_FILTER_TYPES = array(
        "puuid",
        "title",
        "date",
        "status",
        "date_range"
    );
    
    /**
     * @var AppointmentValidator
     */
    private $appointmentValidator;

    public function __construct()
    {
        $this->appointmentService = new AppointmentService();
        $this->appointmentValidator = new AppointmentValidator();
    }

    /**
     * Retrieves a single appointment by its UUID.
     *
     * @param string $appointment_uuid The UUID of the appointment to retrieve.
     * @return mixed Returns the result of the appointment retrieval operation.
     */
    public function getOne(string $appointment_uuid): array
    {
        $serviceResult = $this->appointmentService->getAppointment($appointment_uuid);
        return RestControllerHelper::responseHandler($serviceResult, null, 200);
    }

    /**
     * Updates an appointment by its UUID with the provided data.
     *
     * @param string $appointment_uuid The UUID of the appointment to update.
     * @param array $data An array containing the updated appointment data.
     * @return mixed Returns the result of the appointment update operation.
     */
    public function update(string $appointment_uuid, array $data): array
    {
        $validationResult = $this->appointmentValidator->update_validate($data);
        $validationHandlerResult = RestControllerHelper::validationHandler($validationResult);
        
        if (is_array($validationHandlerResult)) {
            return $validationHandlerResult;
        }

        $serviceResult = $this->appointmentService->updateAppointment($appointment_uuid, $data);
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
    public function getAll(array $data): array
    {
        $validSearchFields = array_filter(
            $data,
            fn($key) => in_array($key, self::SUPPORTED_FILTER_TYPES),
            ARRAY_FILTER_USE_KEY
        );

        $serviceResult = $this->appointmentService->getAppointments($validSearchFields);
    
        return RestControllerHelper::handleProcessingResult($serviceResult, 200);
    }

    public function getAllForPatientByUuid($patient_uuid)
    {
        $patientService = new PatientService();
        $result = ProcessingResult::extractDataArray($patientService->getOne($patient_uuid));
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
     * @param string $patient_uuid The UUID of the patient for whom the appointment is being created.
     * @param array $data An array containing the appointment data.
     * @return mixed Returns the result of the appointment creation operation.
     */
    public function post(string $patient_uuid, array $data): array
    {
        $data['patient_uuid'] = $patient_uuid;
        $data['pc_time'] = date("Y-m-d H:i:s");
        $validationResult = $this->appointmentValidator->validate($data);
        $validationHandlerResult = RestControllerHelper::validationHandler($validationResult);        
        
        if (is_array($validationHandlerResult)) {
             return $validationHandlerResult;
        }

        $serviceResult = $this->appointmentService->insert($patient_uuid, $data);
        return RestControllerHelper::responseHandler(array("id" => $serviceResult), null, 201);
    }

    /**
     * Deletes an appointment by its UUID.
     *
     * @param string $appointment_uuid The UUID of the appointment to delete.
     * @return mixed Returns the result of the appointment deletion operation.
     */
    public function deleteByUuid(string $appointment_uuid): string|null
    {
        $serviceResult = $this->appointmentService->deleteAppointmentByUuid($appointment_uuid);
        return RestControllerHelper::responseHandler($serviceResult, null, 200);;
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
