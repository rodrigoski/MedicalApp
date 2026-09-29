<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;

/**
 * Identificador de trazabilidad de una peticion.
 *
 * POR QUE EXISTE: cuando el usuario ve un error, la pregunta siempre es la
 * misma: "que estaba pasando en el servidor en ese instante". Sin un
 * identificador compartido entre el log, la respuesta HTTP y el evento que se
 * publico, esa pregunta obliga a correlacionar por marca de tiempo, y dos
 * peticiones simultaneas son indistinguibles.
 *
 * DECISION: el identificador lo genera el borde (este middleware) y no cada
 * capa. Se propaga desde la cabecera `X-Request-Id` si el cliente o un proxy
 * upstream (Nginx, en este proyecto) ya la genero, de modo que el identificador
 * sobrevive a varios saltos; si no, se crea aqui. Todas las capas de respuesta
 * lo leen del atributo de la peticion, por lo que anadirlo al cuerpo no obliga
 * a pasar el valor por parametro.
 */
final class RequestId
{
    /**
     * Atributo de la peticion donde queda disponible el identificador.
     */
    public const ATTRIBUTE = '_request_id';

    /**
     * Cabecera de entrada y de salida.
     */
    public const HEADER = 'X-Request-Id';

    /**
     * Longitud maxima aceptada de un identificador entrante.
     *
     * Limite deliberado: el valor se propaga a cabeceras de respuesta y a logs.
     * Aceptar una cadena arbitrariamente larga permitiria inyectar saltos de
     * linea y contaminar el log, que es exactamente el tipo de log injection que
     * un atacante busca cuando controla una cabecera.
     */
    private const MAX_LENGTH = 64;

    /**
     * Resuelve el identificador de la peticion: reutiliza el entrante si es
     * valido y, si no, genera uno nuevo.
     */
    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $incoming = $request->header(self::HEADER);

        // Solo se acepta un conjunto reducido de caracteres. Ademas de evitar la
        // inyeccion de saltos de linea, evita que un id que venga del exterior
        // contenga caracteres de control que rompiesen la lectura del log.
        $isUsable = is_string($incoming)
            && $incoming !== ''
            && strlen($incoming) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9._\-]+$/', $incoming) === 1;

        $requestId = $isUsable ? $incoming : bin2hex(random_bytes(8));

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }

    /**
     * Identificador de la peticion en curso.
     *
     * Se autorrepara en vez de devolver `null` cuando el middleware todavia no
     * ha pasado. El caso real es una ruta que construye su respuesta antes de
     * que el middleware corra, o un `ApiResponse` emitido desde un listener de
     * eventos. Devolver `null` obligaria a cada cliente a comprobar el campo
     * antes de leerlo, y la documentacion promete que siempre esta presente.
     */
    public static function current(): ?string
    {
        $request = request();

        if ($request === null) {
            // Fuera del ciclo de una peticion (consola, test unitario). No hay
            // identificador que dar, y no se inventa uno: seria enganoso.
            return null;
        }

        $value = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::resolve($request);
    }
}
