<?php
// Database connection and transaction helpers.

declare(strict_types=1);

final class Database
{
    // Defaults are for local XAMPP/WAMP. On live hosting, copy
    // config/db_credentials.sample.php to config/db_credentials.php and fill it in.
    private string $host = '127.0.0.1';
    private string $db_name = 'fieldsync_db';
    private string $username = 'root';
    private string $password = '';
    private int $port = 3306;
    private ?PDO $conn = null;
    private int $transactionDepth = 0;

    public function __construct()
    {
        $file = __DIR__ . '/db_credentials.php';
        if (is_file($file)) {
            $cfg = require $file;
            if (is_array($cfg)) {
                $this->host = (string)($cfg['host'] ?? $this->host);
                $this->port = (int)($cfg['port'] ?? $this->port);
                $this->db_name = (string)($cfg['database'] ?? $this->db_name);
                $this->username = (string)($cfg['username'] ?? $this->username);
                $this->password = (string)($cfg['password'] ?? $this->password);
            }
        }
    }

    public function databaseName(): string
    {
        return $this->db_name;
    }

    // One shared PDO connection per request.
    public function getConnection(): PDO
    {
        if ($this->conn instanceof PDO) {
            return $this->conn;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->db_name
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ];

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log('[FATAL DB CONNECTION ERROR] ' . $e->getMessage());
            throw new RuntimeException(
                'Database unavailable. Check config/db_credentials.php.',
                (int)$e->getCode(),
                $e
            );
        }
        return $this->conn;
    }

    // Check if a transaction is currently active
    public function inTransaction(): bool
    {
        return $this->getConnection()->inTransaction();
    }

    // Begin transaction or push savepoint if already inside transaction
    public function beginTransaction(): bool
    {
        $pdo = $this->getConnection();
        if ($this->transactionDepth === 0 || !$pdo->inTransaction()) {
            $this->transactionDepth = 1;
            return $pdo->beginTransaction();
        }

        $this->transactionDepth++;
        $savepoint = 'sp_depth_' . $this->transactionDepth;
        $pdo->exec("SAVEPOINT `{$savepoint}`");
        return true;
    }

    // Commit current level or release savepoint
    public function commit(): bool
    {
        $pdo = $this->getConnection();
        if ($this->transactionDepth <= 1) {
            $this->transactionDepth = 0;
            return $pdo->inTransaction() ? $pdo->commit() : true;
        }

        $savepoint = 'sp_depth_' . $this->transactionDepth;
        $pdo->exec("RELEASE SAVEPOINT `{$savepoint}`");
        $this->transactionDepth--;
        return true;
    }

    // Rollback current level or rollback to savepoint
    public function rollBack(): bool
    {
        $pdo = $this->getConnection();
        if ($this->transactionDepth <= 1) {
            $this->transactionDepth = 0;
            return $pdo->inTransaction() ? $pdo->rollBack() : true;
        }

        $savepoint = 'sp_depth_' . $this->transactionDepth;
        $pdo->exec("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        $this->transactionDepth--;
        return true;
    }

    // Create an explicit named savepoint
    public function createSavepoint(string $name): void
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $this->getConnection()->exec("SAVEPOINT `{$safeName}`");
    }

    // Rollback to an explicit named savepoint
    public function rollbackSavepoint(string $name): void
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $this->getConnection()->exec("ROLLBACK TO SAVEPOINT `{$safeName}`");
    }

    // Release an explicit named savepoint
    public function releaseSavepoint(string $name): void
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $this->getConnection()->exec("RELEASE SAVEPOINT `{$safeName}`");
    }

    // Run a transaction callback with rollback and nested savepoint support.
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->getConnection();
        $isRoot = !$pdo->inTransaction();
        $spName = 'sp_' . bin2hex(random_bytes(6));

        if ($isRoot) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec("SAVEPOINT `{$spName}`");
        }

        try {
            $result = $callback($pdo, $this);
            if ($isRoot) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } else {
                $pdo->exec("RELEASE SAVEPOINT `{$spName}`");
            }
            return $result;
        } catch (Throwable $e) {
            if ($isRoot) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec("ROLLBACK TO SAVEPOINT `{$spName}`");
            }
            error_log('[TRANSACTION EXCEPTION ROLLBACK] ' . $e->getMessage());
            throw $e;
        }
    }

    // Execute callable inside a named savepoint sandbox without killing the outer transaction
    public function savepointSandbox(string $savepointName, callable $callback): mixed
    {
        $pdo = $this->getConnection();
        $safeName = 'sp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $savepointName) . '_' . bin2hex(random_bytes(3));
        $pdo->exec("SAVEPOINT `{$safeName}`");

        try {
            $result = $callback($pdo, $this);
            $pdo->exec("RELEASE SAVEPOINT `{$safeName}`");
            return $result;
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT `{$safeName}`");
            error_log("[SAVEPOINT SANDBOX ROLLBACK: {$safeName}] " . $e->getMessage());
            throw $e;
        }
    }
}