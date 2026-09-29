<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ArchitectureRules;
use Illuminate\Console\Command;

/**
 * VERIFICACION AUTOMATIZADA DE LA ARQUITECTURA POR CAPAS.
 *
 * Responde con evidencia objetiva a tres preguntas de la auditoria:
 *   1. ¿Alguna capa interna importa la base de datos?  -> no deberia.
 *   2. ¿Cuantos archivos dependen de Eloquent, por capa?
 *   3. Si cambio la fuente de almacenamiento, ¿que archivos toco?
 *
 * Se puede ejecutar en CI: devuelve codigo 1 si hay violaciones.
 */
final class ArchitectureCheckCommand extends Command
{
    protected $signature = 'architecture:check {--detailed : Muestra tambien el plan de migracion de almacenamiento}';

    protected $description = 'Verifica la regla de dependencias entre capas y reporta acoplamiento a la BD.';

    public function handle(ArchitectureRules $rules): int
    {
        $this->info('Verificando la regla de dependencias entre capas...');
        $this->newLine();

        $violations = $rules->violations();

        if ($violations === []) {
            $this->components->info('OK  Ninguna capa interna depende de la base de datos ni del framework web.');
        } else {
            $this->components->error(sprintf('FALLO  %d violacion(es) de la regla de dependencias:', count($violations)));

            foreach ($violations as $violation) {
                $this->line(sprintf('   - %s: %s', $violation['file'], $violation['message']));
            }
        }

        $this->newLine();
        $this->info('Acoplamiento a la base de datos por capa:');
        $this->table(
            ['Capa', 'Archivos que importan Eloquent'],
            array_map(
                static fn (string $layer, int $count): array => [$layer, (string) $count],
                array_keys($rules->databaseCouplingByLayer()),
                array_values($rules->databaseCouplingByLayer()),
            ),
        );

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Archivos a modificar si se cambia la fuente de almacenamiento:');
            $this->newLine();

            foreach ($rules->filesToChangeWhenSwappingStorage() as $area => $files) {
                $this->line(sprintf(' <info>%s</info>', $area));

                if ($files === []) {
                    $this->line('    (ninguno)');
                } else {
                    foreach ($files as $file) {
                        $this->line('    - '.$file);
                    }
                }

                $this->newLine();
            }
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
