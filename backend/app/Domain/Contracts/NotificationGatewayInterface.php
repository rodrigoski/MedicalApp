<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\DomainEvent;

/**
 * Puerto de salida hacia el microservicio de notificaciones.
 *
 * La capa de aplicacion solo conoce esta interfaz; la implementacion HTTP
 * (NotificationServiceClient) vive en Infraestructura. Si manana las
 * notificaciones se envian por una cola o un gRPC, se cambia la implementacion
 * y nada mas.
 */
interface NotificationGatewayInterface
{
    /**
     * Entrega un evento al microservicio. DEBE devolver false en vez de lanzar
     * excepcion: una caida del microservicio no puede tumbar la transaccion de
     * negocio (el evento queda en la outbox y se reintenta).
     */
    public function deliver(DomainEvent $event): bool;

    public function isReachable(): bool;
}
