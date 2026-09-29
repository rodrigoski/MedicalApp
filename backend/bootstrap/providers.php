<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Proveedores de la aplicacion
|--------------------------------------------------------------------------
| RepositoryServiceProvider es el contenedor de dependencias: es el unico
| archivo que conecta interfaces (Dominio) con implementaciones
| (Infraestructura).
*/

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
];
