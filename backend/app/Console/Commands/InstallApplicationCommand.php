<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Instalador de la aplicacion.
 *
 * Lo ejecuta el servicio `migrator` de docker-compose al arrancar el stack.
 * Existe como comando (y no como una cadena de `&&` en el compose) por tres
 * razones:
 *   1. Es idempotente: se puede ejecutar en cada `docker compose up`.
 *   2. Falla rapido y con un mensaje claro si la base de datos no esta lista
 *      (espera activa en lugar de un `sleep` a ciegas).
 *   3. Centraliza los pasos en un unico sitio versionable.
 *
 * Uso:  php artisan app:install --seed
 */
final class InstallApplicationCommand extends Command
{
    protected $signature = 'app:install
        {--seed : Cargar el conjunto de datos de demostracion}
        {--fresh : Recrear el esquema desde cero (migrate:fresh)}
        {--wait=60 : Segundos maximos de espera a la base de datos}';

    protected $description = 'Espera la base de datos, ejecuta migraciones y opcionalmente carga datos demo.';

    public function handle(): int
    {
        $maxWait = (int) $this->option('wait');

        if (! $this->waitForDatabase($maxWait)) {
            $this->error("La base de datos no respondio en {$maxWait}s. Abortando.");
            $this->comment('Revise el estado del servicio: docker compose ps');

            return self::FAILURE;
        }

        $this->info('Base de datos disponible.');

        $this->line('  -> Limpiando caches de configuracion y rutas');
        $this->runArtisan('config:clear');
        $this->runArtisan('route:clear');
        $this->runArtisan('view:clear');

        if ($this->option('fresh')) {
            $this->line('  -> migrate:fresh (se recrea el esquema)');
            $this->runArtisan('migrate:fresh', ['--force' => true]);
        } else {
            $this->line('  -> migrate');
            $this->runArtisan('migrate', ['--force' => true]);
        }

        if ($this->option('seed')) {
            $this->line('  -> db:seed (operadores)');
            $this->runArtisan('db:seed', ['--force' => true]);

            // Los datos de demostracion clinicos se cargan por los casos de
            // uso, no por SQL: asi el conjunto respeta la regla de no traslape.
            $this->line('  -> clinic:seed-demo (medicos, pacientes y citas)');
            $this->runArtisan('clinic:seed-demo');
        }

        $this->info('Instalacion completada.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): void
    {
        $exitCode = Artisan::call($command, $parameters, $this->output);

        if ($exitCode !== 0) {
            $this->error(sprintf('  El comando "%s" fallo con codigo %d.', $command, $exitCode));
        }
    }

    /**
     * Espera activa a la base de datos con reintentos y backoff suave.
     */
    private function waitForDatabase(int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        $attempt = 0;

        while (microtime(true) < $deadline) {
            $attempt++;

            try {
                DB::connection()->getPdo();
                DB::select('SELECT 1');

                if ($attempt > 1) {
                    $this->line(sprintf('  Base de datos lista tras %d intento(s).', $attempt));
                }

                return true;
            } catch (Throwable $e) {
                $this->line(sprintf(
                    '  Intento %d: base de datos aun no disponible (%s). Reintentando...',
                    $attempt,
                    class_basename($e::class),
                ));

                sleep(min(5, $attempt));
            }
        }

        return false;
    }
}
