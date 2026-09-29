<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------
    | Parametros de la clinica
    |----------------------------------------------------------------------
    |
    | Valores operativos, no reglas de negocio puras: por eso viven en config
    | y no en el dominio. Un hospital con jornada de 24 h cambiaria estos
    | valores; el Hospital de las 8 a 18, otro. La POLITICA (que no haya
    | traslapes) no se configura: es invariable.
    |
    */

    'max_appointments_per_doctor_per_day' => (int) env(
        'CLINIC_MAX_APPOINTMENTS_PER_DOCTOR_PER_DAY',
        24,
    ),

    /*
    | Tolerancia para agendar citas en el pasado. 0 = estricto.
    | Se mantiene en 0 a proposito: la tolerancia abriria la puerta a agendar
    | en el pasado por desfase de reloj, y la regla de negocio es "no pasado".
    */
    'past_date_tolerance_minutes' => (int) env(
        'CLINIC_PAST_DATE_TOLERANCE_MINUTES',
        0,
    ),

    'default_timezone' => env('CLINIC_TIMEZONE', 'America/Bogota'),

    'working_hours' => [
        'start' => (int) env('CLINIC_WORKING_HOURS_START', 8),
        'end' => (int) env('CLINIC_WORKING_HOURS_END', 18),
        'slot_minutes' => (int) env('CLINIC_SLOT_MINUTES', 30),
    ],

    'pagination' => [
        'default_per_page' => 15,
        'max_per_page' => 100,
    ],

    'events' => [
        'prune_after_days' => (int) env('CLINIC_EVENTS_RETENTION_DAYS', 90),
        'batch_size' => 50,
    ],

];
