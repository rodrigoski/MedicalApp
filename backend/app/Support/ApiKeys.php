<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gestion de las API keys internas.
 *
 * Se admiten varias claves separadas por coma para permitir rotacion sin
 * caida: se agrega la nueva, se despliega, y cuando todos los clientes usan la
 * nueva se retira la vieja del entorno.
 *
 * Las claves se cachean en memoria durante el proceso porque se consultan en
 * CADA escritura; leer un .env por peticion seria un desperdicio de I/O.
 */
final class ApiKeys
{
    private static ?array $valid = null;

    public static function all(): array
    {
        if (self::$valid !== null) {
            return self::$valid;
        }

        $raw = (string) config('app.internal_api_keys', '');

        $keys = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $key): bool => $key !== '',
        ));

        return self::$valid = $keys;
    }

    /**
     * Comparacion en tiempo constante contra todas las claves configuradas.
     *
     * hash_equals() no devuelve temprano: el tiempo depende del numero de
     * bytes comparados, no de donde aparece la diferencia. Por eso recorremos
     * TODAS las claves y acumulamos el resultado con OR logico, en vez de
     * devolver en la primera coincidencia (eso si filtraria informacion).
     */
    public static function anyValid(string $provided): bool
    {
        $keys = self::all();

        if ($keys === []) {
            // Sin claves configuradas se rechaza todo: fallar cerrado.
            return false;
        }

        $valid = false;

        foreach ($keys as $key) {
            $valid = hash_equals($key, $provided) || $valid;
        }

        return $valid;
    }

    /**
     * Solo para pruebas: limpia la cache de claves.
     */
    public static function flush(): void
    {
        self::$valid = null;
    }
}
