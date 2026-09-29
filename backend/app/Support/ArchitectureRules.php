<?php

declare(strict_types=1);

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Verificador de la REGLA DE DEPENDENCIAS entre capas.
 *
 * Que es codigo de AUDITORIA automatizada, no de negocio: recorre el arbol de
 * `app/` y comprueba que las capas internas (Domain, Application) NO importen
 * framework de persistencia ni de presentacion.
 *
 * Utilidad practica: responde con evidencia objetiva a la pregunta
 * "si cambio la fuente de almacenamiento (memoria/SQLite -> PostgreSQL),
 * cuantos archivos tengo que modificar?".
 *   - Domain: 0 archivos (no importa nada de la base de datos)
 *   - Application: 0 archivos (solo interfaces del dominio)
 *   - Infrastructure: un archivo por repositorio + el binding del contenedor
 *
 * Se ejecuta con:  php artisan architecture:check
 */
final class ArchitectureRules
{
    /**
     * Capas que no deben depender de infraestructura tecnica.
     *
     * @var list<string>
     */
    public const INNER_LAYERS = ['Domain', 'Application'];

    /**
     * Simbolos prohibidos en las capas internas.
     *
     * @var list<string>
     */
    public const FORBIDDEN_SYMBOLS = [
        'Illuminate\\Database',
        'Illuminate\\Http',
        'Illuminate\\Foundation',
        'Illuminate\\Support\\Facades',
        'Eloquent\\',
        'PDO',
        'mysqli',
    ];

    /**
     * @return list<array{file: string, symbol: string, message: string}>
     */
    public function violations(): array
    {
        $violations = [];

        foreach (self::INNER_LAYERS as $layer) {
            foreach ($this->phpFilesIn(app_path($layer)) as $file) {
                $contents = (string) file_get_contents($file);

                foreach (self::FORBIDDEN_SYMBOLS as $symbol) {
                    if (str_contains($contents, $symbol)) {
                        $violations[] = [
                            'file' => $this->relativePath($file),
                            'symbol' => $symbol,
                            'message' => sprintf(
                                'La capa %s importa "%s". Las capas internas deben ser independientes de la tecnologia.',
                                $layer,
                                $symbol,
                            ),
                        ];
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Acoplamiento a la base de datos por capa (archivos que importan Eloquent).
     *
     * @return array<string, int>
     */
    public function databaseCouplingByLayer(): array
    {
        $report = [];

        foreach (['Http', 'Application', 'Domain', 'Infrastructure'] as $layer) {
            $coupled = 0;

            foreach ($this->phpFilesIn(app_path($layer)) as $file) {
                if (str_contains((string) file_get_contents($file), 'Illuminate\\Database')) {
                    $coupled++;
                }
            }

            $report[$layer] = $coupled;
        }

        return $report;
    }

    /**
     * Archivos que habria que tocar para cambiar la fuente de almacenamiento.
     *
     * @return array<string, list<string>>
     */
    public function filesToChangeWhenSwappingStorage(): array
    {
        return [
            'Domain (entidades, value objects, politica de traslape)' => [],
            'Application (casos de uso y DTOs)' => [],
            'Infrastructure/Persistence/Repositories/*' => $this->relativePaths(
                $this->phpFilesIn(app_path('Infrastructure/Persistence/Repositories')),
            ),
            'Infrastructure/Persistence/*Mapper.php' => $this->relativePaths(
                $this->phpFilesIn(app_path('Infrastructure/Persistence')),
                'Mapper.php',
            ),
            'Migraciones de base de datos' => $this->relativePaths(
                $this->phpFilesIn(base_path('database/migrations')),
            ),
            'Providers/RepositoryServiceProvider.php (bindings)' => [
                'app/Providers/RepositoryServiceProvider.php',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory, string $suffix = '.php'): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function relativePaths(array $files, string $mustEndWith = ''): array
    {
        $result = array_map(fn (string $f): string => $this->relativePath($f), $files);

        if ($mustEndWith !== '') {
            $result = array_values(array_filter(
                $result,
                static fn (string $p): bool => str_ends_with($p, $mustEndWith),
            ));
        }

        sort($result);

        return $result;
    }

    private function relativePath(string $absolute): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($base)))
            : $absolute;
    }
}
