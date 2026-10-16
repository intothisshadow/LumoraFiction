<?php

/**
 * Creates, finds, and updates accounts.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Security\PasswordPolicy;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;
use PDOException;

/**
 * Every account has a private login name, a public display name, and a public slug. Only the
 * latter two may appear in output a visitor can see (see User::publicProfile()).
 */
final class UserService
{
    private const SLUG_ATTEMPTS = 5;

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Database $database,
        private readonly PasswordPolicy $passwords,
    ) {
    }

    /**
     * Checks everything about a new account that can be checked before saving it, except whether
     * the email address is already registered. That is kept out of this result so the sign-up form
     * can never be used to find out who has an account.
     *
     * @return array<string, string> field name => problem; empty when the input is acceptable
     */
    public function validateNewAccount(string $loginName, string $displayName, string $email, string $password): array
    {
        $errors = [];
        $displayName = AccountRules::normalizeDisplayName($displayName);
        $email = AccountRules::normalizeEmail($email);

        if (($problem = AccountRules::loginNameProblem($loginName)) !== null) {
            $errors['login_name'] = $problem;
        } elseif ($this->findByLoginName($loginName) !== null) {
            $errors['login_name'] = 'That login name is already taken.';
        }

        if (($problem = AccountRules::displayNameProblem($displayName)) !== null) {
            $errors['display_name'] = $problem;
        } elseif (!isset($errors['login_name']) && ($problem = AccountRules::distinctnessProblem($loginName, AccountRules::normalizeDisplayName($displayName))) !== null) {
            $errors['login_name'] = $problem;
        }

        if (($problem = AccountRules::emailProblem($email)) !== null) {
            $errors['email'] = $problem;
        }

        if (($problem = $this->passwords->violation($password, [$loginName, $displayName, $email])) !== null) {
            $errors['password'] = $problem;
        }

        return $errors;
    }

    /**
     * Saves a new account. Callers are expected to have run validateNewAccount() first; this
     * re-checks the format rules so a bad value can never reach the database.
     *
     * @throws InvalidArgumentException when a value fails the format rules
     * @throws DuplicateAccountException when the login name or email address is already registered
     */
    public function create(
        string $loginName,
        string $displayName,
        string $email,
        string $password,
        UserRole $role = UserRole::Reader,
        UserStatus $status = UserStatus::Pending,
    ): User {
        $displayName = AccountRules::normalizeDisplayName($displayName);
        $email = AccountRules::normalizeEmail($email);

        $problem = AccountRules::loginNameProblem($loginName)
            ?? AccountRules::displayNameProblem($displayName)
            ?? AccountRules::distinctnessProblem($loginName, $displayName)
            ?? AccountRules::emailProblem($email)
            ?? $this->passwords->violation($password, [$loginName, $displayName, $email]);

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        for ($attempt = 0; $attempt < self::SLUG_ATTEMPTS; $attempt++) {
            $slug = $this->uniqueSlug($displayName, $loginName, $attempt > 0);
            $now = date('Y-m-d H:i:s');

            try {
                $id = (int) $this->database->insertGetId(
                    'INSERT INTO {prefix}users (login_name, display_name, slug, email, password_hash, password_changed_at, role, status, created_at, updated_at)
                     VALUES (:login_name, :display_name, :slug, :email, :password_hash, :changed, :role, :status, :created, :updated)',
                    [
                        'login_name' => $loginName,
                        'display_name' => $displayName,
                        'slug' => $slug,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'changed' => $now,
                        'role' => $role->value,
                        'status' => $status->value,
                        'created' => $now,
                        'updated' => $now,
                    ],
                );
            } catch (PDOException $exception) {
                $key = $this->duplicateKey($exception);

                if ($key === 'slug') {
                    // Another account took this slug between the check and the insert; pick again.
                    continue;
                }

                if ($key !== null) {
                    throw new DuplicateAccountException($key);
                }

                throw $exception;
            }

            return $this->findById($id) ?? throw new \RuntimeException('The new account could not be read back.');
        }

        throw new \RuntimeException('Could not allocate a unique slug.');
    }

    public function findById(int $id): ?User
    {
        return $this->findWhere('id = :value', $id);
    }

    public function findByLoginName(string $loginName): ?User
    {
        return $this->findWhere('login_name = :value', $loginName);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findWhere('email = :value', AccountRules::normalizeEmail($email));
    }

    public function findBySlug(string $slug): ?User
    {
        return $this->findWhere('slug = :value', $slug);
    }

    /**
     * The public names for a page of listings in one query. Only display names are returned, never login names.
     *
     * @param list<int> $ids
     * @return array<int, string> account id => display name
     */
    public function displayNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $params = [];

        foreach ($ids as $index => $id) {
            $params['id' . $index] = $id;
        }

        $names = [];

        foreach ($this->database->fetchAll(
            'SELECT id, display_name FROM {prefix}users WHERE id IN (' . implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params))) . ')',
            $params,
        ) as $row) {
            $names[(int) $row['id']] = (string) $row['display_name'];
        }

        return $names;
    }

    /**
     * Returns the account only when the password matches. The password hash is always checked, even
     * when no account was found (against a throwaway hash), so a missing account takes as long to
     * reject as a wrong password and the response time does not reveal which accounts exist.
     */
    public function verifyCredentials(string $identity, string $password): ?User
    {
        $identity = trim($identity);
        $column = str_contains($identity, '@') ? 'email' : 'login_name';
        $value = $column === 'email' ? AccountRules::normalizeEmail($identity) : $identity;

        $row = $this->database->fetchOne('SELECT * FROM {prefix}users WHERE ' . $column . ' = :value', ['value' => $value]);

        $hash = $row !== null ? (string) $row['password_hash'] : $this->dummyHash();
        $matches = password_verify($password, $hash);

        if ($row === null || !$matches) {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->database->execute(
                'UPDATE {prefix}users SET password_hash = :hash WHERE id = :id',
                ['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['id']],
            );
        }

        return $this->hydrate($row);
    }

    /**
     * Sets a new password and records when, which ends every session that began before now.
     */
    public function updatePassword(int $userId, string $password): void
    {
        $now = date('Y-m-d H:i:s');

        $this->database->execute(
            'UPDATE {prefix}users SET password_hash = :hash, password_changed_at = :changed, updated_at = :updated WHERE id = :id',
            ['hash' => password_hash($password, PASSWORD_DEFAULT), 'changed' => $now, 'updated' => $now, 'id' => $userId],
        );
    }

    /**
     * Records that the person controls the address. A pending account becomes active; a suspended
     * account stays suspended.
     */
    public function markEmailVerified(int $userId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->database->execute(
            'UPDATE {prefix}users
             SET email_verified_at = COALESCE(email_verified_at, :verified),
                 status = CASE WHEN status = :pending THEN :active ELSE status END,
                 updated_at = :updated
             WHERE id = :id',
            ['verified' => $now, 'pending' => UserStatus::Pending->value, 'active' => UserStatus::Active->value, 'updated' => $now, 'id' => $userId],
        );
    }

    /**
     * Headline numbers for the dashboard.
     *
     * @return array{total: int, by_role: array<string, int>, by_status: array<string, int>}
     */
    public function summary(): array
    {
        $byRole = array_fill_keys(array_map(static fn (UserRole $role): string => $role->value, UserRole::cases()), 0);
        $byStatus = array_fill_keys(array_map(static fn (UserStatus $status): string => $status->value, UserStatus::cases()), 0);

        foreach ($this->database->fetchAll('SELECT role, status, COUNT(*) AS total FROM {prefix}users GROUP BY role, status') as $row) {
            // A value outside the known sets (a corrupt row) is counted nowhere rather than invented as a new bucket.
            if (isset($byRole[$row['role']], $byStatus[$row['status']])) {
                $byRole[$row['role']] += (int) $row['total'];
                $byStatus[$row['status']] += (int) $row['total'];
            }
        }

        return ['total' => array_sum($byStatus), 'by_role' => $byRole, 'by_status' => $byStatus];
    }

    public function setStatus(int $userId, UserStatus $status): void
    {
        $this->database->execute(
            'UPDATE {prefix}users SET status = :status, updated_at = :updated WHERE id = :id',
            ['status' => $status->value, 'updated' => date('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    public function setRole(int $userId, UserRole $role): void
    {
        $this->database->execute(
            'UPDATE {prefix}users SET role = :role, updated_at = :updated WHERE id = :id',
            ['role' => $role->value, 'updated' => date('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    private function findWhere(string $condition, string|int $value): ?User
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}users WHERE ' . $condition, ['value' => $value]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            loginName: (string) $row['login_name'],
            displayName: (string) $row['display_name'],
            slug: (string) $row['slug'],
            email: (string) $row['email'],
            role: UserRole::tryFrom((string) $row['role']) ?? UserRole::Reader,
            // An unrecognised status is treated as suspended so a corrupt value fails closed.
            status: UserStatus::tryFrom((string) $row['status']) ?? UserStatus::Suspended,
            emailVerifiedAt: $this->date($row['email_verified_at'] ?? null),
            passwordChangedAt: $this->date($row['password_changed_at'] ?? null),
            createdAt: $this->date($row['created_at']) ?? new DateTimeImmutable(),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * The slug comes from the display name, with a number added when it is taken, reserved, or
     * identical to the login name (the login name must stay distinct from everything public).
     */
    private function uniqueSlug(string $displayName, string $loginName, bool $randomSuffix): string
    {
        $base = Slug::fromText($displayName);

        // After losing a race for a slug, a random suffix avoids racing for the next number as well.
        if ($randomSuffix) {
            return $base . '-' . bin2hex(random_bytes(3));
        }

        for ($number = 1; $number < 1000; $number++) {
            $candidate = $number === 1 ? $base : $base . '-' . $number;

            if (
                !Slug::isReserved($candidate)
                && strtolower($candidate) !== strtolower($loginName)
                && $this->findBySlug($candidate) === null
            ) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    private function duplicateKey(PDOException $exception): ?string
    {
        if (($exception->errorInfo[1] ?? null) !== 1062) {
            return null;
        }

        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'uniq_login_name') => 'login name',
            str_contains($message, 'uniq_email') => 'email address',
            str_contains($message, 'uniq_slug') => 'slug',
            default => 'account',
        };
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }
}
