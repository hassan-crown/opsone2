<?php

namespace App\Operations\Upgrade;

use Illuminate\Database\Migrations\Migrator;
use ReflectionClass;
use ReflectionMethod;

/**
 * The migrations this code ships and their expand/contract marker (NFR-15, ADR-0028).
 */
class MigrationCatalogue
{
    /**
     * Statements in `up()` that remove or rewrite what older code may still read or write.
     */
    private const DESTRUCTIVE = '/->(dropColumn|dropColumns|renameColumn|dropPrimary|dropUnique|dropForeign|dropConstrainedForeignId|dropTimestamps|dropSoftDeletes|change)\s*\(|Schema::(drop|dropIfExists|rename|dropColumns)\s*\(|\b(DROP|RENAME|MODIFY|CHANGE)\s+(TABLE|COLUMN)\b/i';

    /** @var array<string, string>|null */
    private ?array $files = null;

    /** @var array<string, array{kind: MigrationKind, destructive: bool}> */
    private array $inspected = [];

    /**
     * @param  list<string>|null  $paths
     */
    public function __construct(private readonly Migrator $migrator, private readonly ?array $paths = null) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->files());
    }

    public function has(string $name): bool
    {
        return isset($this->files()[$name]);
    }

    public function kind(string $name): MigrationKind
    {
        return $this->inspect($name)['kind'];
    }

    /**
     * Whether `up()` contains drop/rename/change statements (a mis-marked "expand" is refused).
     */
    public function destructive(string $name): bool
    {
        return $this->inspect($name)['destructive'];
    }

    /**
     * @return array<string, string> name → path, in run order
     */
    private function files(): array
    {
        if ($this->files === null) {
            $paths = $this->paths ?? [database_path('migrations'), ...$this->migrator->paths()];
            $this->files = $this->migrator->getMigrationFiles(array_values(array_unique($paths)));
        }

        return $this->files;
    }

    /**
     * @return array{kind: MigrationKind, destructive: bool}
     */
    private function inspect(string $name): array
    {
        if (isset($this->inspected[$name])) {
            return $this->inspected[$name];
        }

        $path = $this->files()[$name] ?? null;
        if ($path === null) {
            return $this->inspected[$name] = ['kind' => MigrationKind::Unmarked, 'destructive' => false];
        }

        $migration = require $path;
        if (! is_object($migration)) {
            return $this->inspected[$name] = ['kind' => MigrationKind::Unmarked, 'destructive' => self::scan((string) file_get_contents($path))];
        }

        $class = new ReflectionClass($migration);
        $kind = match (true) {
            $class->getAttributes(Contract::class) !== [] => MigrationKind::Contract,
            $class->getAttributes(Expand::class) !== [] => MigrationKind::Expand,
            default => MigrationKind::Unmarked,
        };

        $source = (string) file_get_contents($path);
        if ($class->hasMethod('up')) {
            $up = new ReflectionMethod($migration, 'up');
            $lines = explode("\n", $source);
            $source = implode("\n", array_slice($lines, (int) $up->getStartLine() - 1, (int) $up->getEndLine() - (int) $up->getStartLine() + 1));
        }

        return $this->inspected[$name] = ['kind' => $kind, 'destructive' => self::scan($source)];
    }

    private static function scan(string $source): bool
    {
        return preg_match(self::DESTRUCTIVE, $source) === 1;
    }
}
