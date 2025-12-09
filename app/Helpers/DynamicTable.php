<?php

use Carbon\Carbon;

if (!function_exists('getDynamicTableName')) {

    /**
     * Regresa la tabla dinámica basada en el último domingo.
     * Ejemplo:
     * - Si hoy es 14/dic → usa 07/dic → TB07122501
     * - Si hoy es 20/dic → usa 14/dic → TB14122501
     *
     * IMPORTANTE:
     * Cambiar la lógica aquí si la empresa usa otro criterio:
     * quincena, viernes, lunes, fecha fija, etc.
     *
     */
     function getDynamicTableName($date = null)
    {
        $date = $date ? Carbon::parse($date) : Carbon::today();

        // 0 = Sunday (Carbon::SUNDAY)
        // Calcula días desde el último domingo
        $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;

        // Restamos esos días para llegar al domingo más reciente
        $lastSunday = $date->copy()->subDays($daysSinceSunday);

        $formatted = $lastSunday->format('dmy');

        return "TB{$formatted}01";
    }
}
