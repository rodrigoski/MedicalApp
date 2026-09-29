<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Services\EventPublisher;
use App\Infrastructure\Persistence\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/**
 * Carga (o recarga) el conjunto de datos de demostracion.
 *
 * Importa datos a traves de los CASOS DE USO, no con inserts directos. Es una
 * decision deliberada: si el seeder usara SQL, una cita de demostracion podria
 * violar la regla de no traslape y el conjunto de datos seria irreal. Al pasar
 * por AppointmentService, los datos demo siempre son validos y ademas se
 * demuestra que la regla funciona.
 */
final class SeedDemoDataCommand extends Command
{
    protected $signature = 'clinic:seed-demo {--fresh : Elimina los datos de demostracion anteriores}';

    protected $description = 'Carga medicos, pacientes y citas de demostracion usando los casos de uso.';

    public function handle(DemoDataSeeder $seeder, EventPublisher $publisher): int
    {
        if ($this->option('fresh')) {
            $this->line('Eliminando datos de demostracion anteriores...');
            $seeder->purge();
        }

        $summary = $seeder->run();

        $this->newLine();
        $this->components->info('Datos de demostracion cargados:');
        $this->table(
            ['Recurso', 'Cantidad'],
            [
                ['Medicos', (string) $summary['doctors']],
                ['Pacientes', (string) $summary['patients']],
                ['Citas creadas', (string) $summary['appointments_created']],
                ['Citas rechazadas por la regla de no traslape', (string) $summary['appointments_rejected']],
            ],
        );

        $this->newLine();
        $this->line('Entregando eventos al microservicio de notificaciones...');
        $result = $publisher->dispatchPending(100);
        $this->line(sprintf(
            '  entregados: %d | fallidos: %d | pendientes: %d',
            $result['delivered'],
            $result['failed'],
            $result['pending'],
        ));

        $this->newLine();
        $this->components->info('Listo. Pruebe: curl -H "X-Api-Key: dev-clinic-key-change-me" http://localhost:8000/api/v1/stats');

        return self::SUCCESS;
    }
}
