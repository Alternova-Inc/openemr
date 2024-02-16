<?php

namespace OpenEMR\Validators;

use Particle\Validator\Exception\InvalidValueException;
use Particle\Validator\Validator;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Database\QueryUtils;


/**
 * Supports Appointments Record Validation.
 *
 */
class AppointmentValidator
{
    /**
     * Decodes a UUID string into bytes.
     *
     * @param string $puuid The UUID string to decode.
     * @return string|null Returns the decoded UUID as bytes, or null if decoding fails.
     */
    private function decodeUuid(string $puuid): string|null
    {
        try {
            return UuidRegistry::uuidToBytes($puuid);
        } catch (\Exception $e) {
            return null;
        }
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


    /**
     * Validates the data for creating or updating an appointment.
     *
     * @param mixed $appointment The appointment data to be validated.
     * @return ValidationResult Returns the result of the validation operation.
     */
    public function validate(array $appointment)
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
        $validator->optional('patient_uuid')->callback(function ($value, $data) {
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
}
