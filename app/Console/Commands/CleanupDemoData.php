<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class CleanupDemoData extends Command
{
    protected $signature = 'app:cleanup-demo-data
        {--dry-run : Show the protected tables, candidate tables, counts, and delete order without changing data}
        {--execute : Execute the cleanup; this is required for any deletion}';

    protected $description = 'Safely clear demo operational data while preserving users, migrations, and runtime system tables.';

    /** @var list<string> */
    private const PROTECTED_TABLES = ['users', 'migrations'];

    /** @var list<string> */
    private const RUNTIME_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'password_reset_tokens',
        'sessions',
    ];

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan hanya salah satu dari --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        try {
            $plan = $this->buildPlan();

            $this->renderPlan($plan, $execute ? 'EXECUTION PLAN' : 'DRY-RUN PLAN');

            if (! $execute) {
                $this->newLine();
                $this->info('Tidak ada perubahan. Jalankan --execute hanya setelah meninjau hasil ini.');

                return self::SUCCESS;
            }

            $confirmation = $this->ask('Ketik ecommerce_bumdes_restore untuk mengonfirmasi penghapusan');

            if ($confirmation !== 'ecommerce_bumdes_restore') {
                $this->warn('Konfirmasi tidak cocok. Cleanup dibatalkan tanpa perubahan.');

                return self::FAILURE;
            }

            return $this->executePlan($plan);
        } catch (Throwable $exception) {
            $this->error('Cleanup dihentikan: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{database: string, tables: list<string>, preserved: list<string>, candidates: list<string>, counts: array<string, int>, engines: array<string, string>, protected_counts: array<string, int>, protected_fingerprints: array<string, string>, order: list<string>}
     */
    private function buildPlan(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Cleanup hanya boleh berjalan pada koneksi MySQL. Driver aktif: '.$connection->getDriverName());
        }

        $database = (string) $connection->selectOne('SELECT DATABASE() AS database_name')->database_name;

        if ($database !== 'ecommerce_bumdes_restore') {
            throw new \RuntimeException('Database aktif bukan ecommerce_bumdes_restore. Cleanup dihentikan.');
        }

        $tableMetadata = collect($connection->select(
            "SELECT TABLE_NAME, ENGINE\n             FROM information_schema.TABLES\n             WHERE TABLE_SCHEMA = DATABASE()\n               AND TABLE_TYPE = 'BASE TABLE'\n             ORDER BY TABLE_NAME"
        ));
        $tables = $tableMetadata->map(fn (object $row): string => (string) $row->TABLE_NAME)->values()->all();

        foreach (self::PROTECTED_TABLES as $requiredTable) {
            if (! in_array($requiredTable, $tables, true)) {
                throw new \RuntimeException("Tabel wajib {$requiredTable} tidak ditemukan. Cleanup dihentikan.");
            }
        }

        $preserved = array_values(array_unique(array_merge(
            self::PROTECTED_TABLES,
            array_values(array_intersect(self::RUNTIME_TABLES, $tables)),
        )));
        $candidates = array_values(array_diff($tables, $preserved));
        $foreignKeys = $this->foreignKeys($connection);

        $this->assertPreservedTablesDoNotDependOnCandidates($foreignKeys, $preserved, $candidates);
        $order = $this->deletionOrder($candidates, $foreignKeys);
        $counts = [];
        $engines = [];

        foreach ($tableMetadata as $metadata) {
            $table = (string) $metadata->TABLE_NAME;

            if (in_array($table, $candidates, true)) {
                $engines[$table] = strtoupper((string) $metadata->ENGINE);
            }
        }

        foreach ($engines as $table => $engine) {
            if ($engine !== 'INNODB') {
                throw new \RuntimeException("Tabel kandidat {$table} menggunakan engine {$engine}; cleanup dihentikan karena rollback tidak dapat dijamin.");
            }
        }

        foreach ($candidates as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }

        $protectedCounts = [];
        $protectedFingerprints = [];
        foreach (self::PROTECTED_TABLES as $table) {
            $protectedCounts[$table] = (int) $connection->table($table)->count();
            $protectedFingerprints[$table] = $this->tableFingerprint($table);
        }

        return [
            'database' => $database,
            'tables' => $tables,
            'preserved' => $preserved,
            'candidates' => $candidates,
            'counts' => $counts,
            'engines' => $engines,
            'protected_counts' => $protectedCounts,
            'protected_fingerprints' => $protectedFingerprints,
            'order' => $order,
        ];
    }

    /**
     * @return list<array{child: string, parent: string,constraint: string, delete_rule: string}>
     */
    private function foreignKeys(ConnectionInterface $connection): array
    {
        return collect($connection->select(
            "SELECT TABLE_NAME AS child_table, REFERENCED_TABLE_NAME AS parent_table,\n                    CONSTRAINT_NAME AS constraint_name, DELETE_RULE AS delete_rule\n             FROM information_schema.REFERENTIAL_CONSTRAINTS\n             WHERE CONSTRAINT_SCHEMA = DATABASE()\n             ORDER BY TABLE_NAME, REFERENCED_TABLE_NAME, CONSTRAINT_NAME"
        ))->map(fn (object $row): array => [
            'child' => (string) $row->child_table,
            'parent' => (string) $row->parent_table,
            'constraint' => (string) $row->constraint_name,
            'delete_rule' => (string) $row->delete_rule,
        ])->values()->all();
    }

    /**
     * @param list<array{child: string, parent: string,constraint: string, delete_rule: string}> $foreignKeys
     * @param list<string> $preserved
     * @param list<string> $candidates
     */
    private function assertPreservedTablesDoNotDependOnCandidates(array $foreignKeys, array $preserved, array $candidates): void
    {
        foreach ($foreignKeys as $foreignKey) {
            if (in_array($foreignKey['child'], $preserved, true)
                && in_array($foreignKey['parent'], $candidates, true)) {
                throw new \RuntimeException(sprintf(
                    'Foreign key %s dari tabel yang dipertahankan %s ke tabel kandidat %s tidak aman untuk cleanup (aturan delete: %s).',
                    $foreignKey['constraint'],
                    $foreignKey['child'],
                    $foreignKey['parent'],
                    $foreignKey['delete_rule'],
                ));
            }
        }
    }

    /**
     * Produce child-before-parent order without disabling foreign keys.
     *
     * @param list<string> $candidates
     * @param list<array{child: string, parent: string,constraint: string, delete_rule: string}> $foreignKeys
     * @return list<string>
     */
    private function deletionOrder(array $candidates, array $foreignKeys): array
    {
        $candidateSet = array_fill_keys($candidates, true);
        $childrenByParent = array_fill_keys($candidates, []);

        foreach ($foreignKeys as $foreignKey) {
            if (! isset($candidateSet[$foreignKey['child']]) || ! isset($candidateSet[$foreignKey['parent']])) {
                continue;
            }

            if ($foreignKey['child'] === $foreignKey['parent']) {
                throw new \RuntimeException("Self-referencing foreign key {$foreignKey['constraint']} pada {$foreignKey['child']} tidak dapat dipastikan aman.");
            }

            $childrenByParent[$foreignKey['parent']][] = $foreignKey['child'];
        }

        $visited = [];
        $visiting = [];
        $order = [];

        $visit = function (string $table) use (&$visit, &$visited, &$visiting, &$order, $childrenByParent): void {
            if (isset($visited[$table])) {
                return;
            }

            if (isset($visiting[$table])) {
                throw new \RuntimeException("Siklus foreign key terdeteksi pada tabel {$table}; cleanup dihentikan.");
            }

            $visiting[$table] = true;

            foreach (array_unique($childrenByParent[$table] ?? []) as $child) {
                $visit($child);
            }

            unset($visiting[$table]);
            $visited[$table] = true;
            $order[] = $table;
        };

        foreach ($candidates as $table) {
            $visit($table);
        }

        return $order;
    }

    /** @param array{database: string, tables: list<string>, preserved: list<string>, candidates: list<string>, counts: array<string, int>, engines: array<string, string>, protected_counts: array<string, int>, protected_fingerprints: array<string, string>, order: list<string>} $plan */
    private function renderPlan(array $plan, string $heading): void
    {
        $this->info($heading);
        $this->line('Database: '.$plan['database']);
        $this->line('Protected tables: '.implode(', ', self::PROTECTED_TABLES));
        $this->line('Preserved runtime tables: '.implode(', ', array_values(array_diff($plan['preserved'], self::PROTECTED_TABLES)) ?: ['(none)']));
        $this->line('Candidate engines: '.implode(', ', array_map(
            fn (string $table, string $engine): string => $table.'='.$engine,
            array_keys($plan['engines']),
            array_values($plan['engines']),
        )) ?: '(none)');
        $this->line('Protected row counts: '.implode(', ', array_map(
            fn (string $table, int $count): string => $table.'='.$count,
            array_keys($plan['protected_counts']),
            array_values($plan['protected_counts']),
        )));
        $this->newLine();
        $this->line('Candidate tables and row counts:');

        foreach ($plan['counts'] as $table => $count) {
            $this->line(sprintf('  - %s: %d', $table, $count));
        }

        $this->newLine();
        $this->line('Deletion order (child before parent): '.implode(' -> ', $plan['order']) ?: '(none)');
    }

    /** @param array{database: string, tables: list<string>, preserved: list<string>, candidates: list<string>, counts: array<string, int>, engines: array<string, string>, protected_counts: array<string, int>, protected_fingerprints: array<string, string>, order: list<string>} $plan */
    private function executePlan(array $plan): int
    {
        $usersBefore = $plan['protected_fingerprints']['users'];

        DB::transaction(function () use ($plan, $usersBefore): void {
            $activeDatabase = (string) DB::selectOne('SELECT DATABASE() AS database_name')->database_name;

            if ($activeDatabase !== 'ecommerce_bumdes_restore') {
                throw new \RuntimeException('Database aktif berubah sebelum cleanup. Rollback dilakukan.');
            }

            $currentTables = $this->currentTables();
            $currentCandidates = array_values(array_diff($currentTables, $plan['preserved']));
            sort($currentCandidates);
            $plannedCandidates = $plan['candidates'];
            sort($plannedCandidates);

            if ($currentCandidates !== $plannedCandidates) {
                throw new \RuntimeException('Daftar tabel kandidat berubah sejak preflight. Rollback dilakukan.');
            }

            foreach ($plan['counts'] as $table => $expectedCount) {
                $actualCount = (int) DB::table($table)->count();

                if ($actualCount !== $expectedCount) {
                    throw new \RuntimeException("Jumlah record tabel {$table} berubah sejak preflight. Rollback dilakukan.");
                }
            }

            $lockedUsers = DB::table('users')->orderBy('id')->lockForUpdate()->get();

            if ($lockedUsers->count() !== $plan['protected_counts']['users']
                || $this->tableFingerprintFromRows($lockedUsers) !== $usersBefore) {
                throw new \RuntimeException('Record users berubah sejak preflight. Rollback dilakukan.');
            }

            foreach ($plan['order'] as $table) {
                DB::table($table)->delete();
            }

            if ($this->tableFingerprint('users') !== $usersBefore) {
                throw new \RuntimeException('Fingerprint users berubah selama transaksi cleanup. Rollback dilakukan.');
            }
        });

        if ($usersBefore !== $this->tableFingerprint('users')) {
            throw new \RuntimeException('Fingerprint users berubah setelah cleanup. Pemeriksaan gagal.');
        }

        $this->info('Cleanup selesai. users dan migrations tidak disentuh.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function currentTables(): array
    {
        return collect(DB::select(
            "SELECT TABLE_NAME\n             FROM information_schema.TABLES\n             WHERE TABLE_SCHEMA = DATABASE()\n               AND TABLE_TYPE = 'BASE TABLE'\n             ORDER BY TABLE_NAME"
        ))->map(fn (object $row): string => (string) $row->TABLE_NAME)->values()->all();
    }

    private function tableFingerprint(string $table): string
    {
        return $this->tableFingerprintFromRows(DB::table($table)->orderBy('id')->get());
    }

    private function tableFingerprintFromRows(object $rows): string
    {
        return hash('sha256', serialize($rows instanceof \Illuminate\Support\Collection ? $rows->all() : $rows));
    }
}
