<?php
// Simple account registration and login.

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Auth
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(private Database $database)
    {
    }

    // Recovery code shown to the user once, e.g. 3F9A-0C1B-77D2-A4E8-91BC-5D60.
    private function makeRecoveryCode(): string
    {
        return strtoupper(implode('-', str_split(bin2hex(random_bytes(12)), 4)));
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    // Create an account inside a database transaction.
    // Returns ['user_id' => int, 'recovery_code' => string] (the code is shown once).
    public function register(string $fullName, string $email, string $password): array
    {
        $fullName = trim($fullName);
        $email = strtolower(trim($email));

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120) {
            throw new InvalidArgumentException('Enter your name (2-120 characters).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $recoveryCode = $this->makeRecoveryCode();
        $recoveryHash = password_hash($this->normalizeCode($recoveryCode), PASSWORD_DEFAULT);

        try {
            $userId = (int)$this->database->transaction(
                function (PDO $pdo) use ($fullName, $email, $hash, $recoveryHash): int {
                    $check = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1 FOR UPDATE');
                    $check->execute([':email' => $email]);
                    if ($check->fetchColumn() !== false) {
                        throw new InvalidArgumentException('That email is already registered.');
                    }

                    $insert = $pdo->prepare(
                        'INSERT INTO users (full_name, email, password_hash, recovery_hash)
                         VALUES (:full_name, :email, :password_hash, :recovery_hash)'
                    );
                    $insert->execute([
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':password_hash' => $hash,
                        ':recovery_hash' => $recoveryHash,
                    ]);
                    return (int)$pdo->lastInsertId();
                }
            );
            return ['user_id' => $userId, 'recovery_code' => $recoveryCode];
        } catch (PDOException $e) {
            // Two people registering the same email at the same moment.
            if ((string)$e->getCode() === '23000') {
                throw new InvalidArgumentException('That email is already registered.');
            }
            throw $e;
        }
    }

    // Returns the user row on success, or null for a wrong email/password.
    public function authenticate(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $pdo = $this->database->getConnection();
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, email, password_hash FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id')
                ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => (int)$user['user_id']]);
        }

        unset($user['password_hash']);
        return $user;
    }

    // Reset a forgotten password with the recovery code. Returns a NEW recovery code.
    // Throws RuntimeException('bad') for a wrong email/code (same message either way).
    public function resetPassword(string $email, string $recoveryCode, string $newPassword): string
    {
        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        $email = strtolower(trim($email));
        $code = $this->normalizeCode($recoveryCode);
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $newCode = $this->makeRecoveryCode();
        $newRecoveryHash = password_hash($this->normalizeCode($newCode), PASSWORD_DEFAULT);

        $this->database->transaction(function (PDO $pdo) use ($email, $code, $newHash, $newRecoveryHash): void {
            $stmt = $pdo->prepare('SELECT user_id, recovery_hash FROM users WHERE email = :email LIMIT 1 FOR UPDATE');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();
            if (!$user || empty($user['recovery_hash']) || !password_verify($code, (string)$user['recovery_hash'])) {
                throw new RuntimeException('The email or recovery code is incorrect.');
            }
            $pdo->prepare('UPDATE users SET password_hash = :p, recovery_hash = :r WHERE user_id = :id')
                ->execute([':p' => $newHash, ':r' => $newRecoveryHash, ':id' => (int)$user['user_id']]);
        });

        return $newCode;
    }
}
