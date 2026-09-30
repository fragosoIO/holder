<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\CompanyWorkspace;
use App\Domain\Github\TokenCipher;
use App\Domain\HolderException;
use App\Domain\Ids;
use App\Infrastructure\Db;

final class IdentityService
{
    private const ROLES = ['owner', 'admin', 'member', 'viewer'];

    public function __construct(
        private readonly Db $db,
        private readonly CompanyWorkspace $workspaces,
        private readonly TokenCipher $tokens,
    ) {}

    /**
     * @return array{token: string, user: array<string, mixed>, companies: list<array<string, mixed>>}
     */
    public function ensureLocalOwner(): array
    {
        $email = 'owner@holder.local';
        $existing = $this->db->one('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        if ($existing === null) {
            $this->createOwner($email, 'Owner', password_hash('owner', PASSWORD_DEFAULT), 'Holder', 'Run the company.');
        }
        $open = $this->db->one(
            'SELECT s.token FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.email = :email AND s.expires_at > NOW() ORDER BY s.created_at DESC LIMIT 1',
            ['email' => $email],
        );
        if ($open !== null) {
            $session = $this->session((string) $open['token']);
            if ($session !== null) {
                return [
                    'token' => (string) $open['token'],
                    'user' => $session['user'],
                    'companies' => $session['companies'],
                ];
            }
        }

        return $this->login($email, 'owner');
    }

    /**
     * @return array{token: string, user: array<string, mixed>, companies: list<array<string, mixed>>}
     */
    public function bootstrap(string $email, string $password, string $name, string $company, string $mission): array
    {
        $existing = $this->db->one('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        if ($existing === null) {
            $this->createOwner($email, $name, password_hash($password, PASSWORD_DEFAULT), $company, $mission);
        }

        return $this->login($email, $password);
    }

    /**
     * @return array{token: string, user: array<string, mixed>, companies: list<array<string, mixed>>}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->db->one('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        if ($user === null || !is_string($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            throw new HolderException('invalid_login', 'Email or password is wrong.', 401);
        }

        $token = Ids::token();
        $this->db->exec(
            'INSERT INTO sessions (token, user_id, expires_at) VALUES (:token, :user_id, NOW() + INTERVAL \'30 days\')',
            ['token' => $token, 'user_id' => $user['id']],
        );

        return [
            'token' => $token,
            'user' => $this->userResource($user),
            'companies' => $this->companiesFor((string) $user['id']),
        ];
    }

    public function logout(string $token): void
    {
        $this->db->exec('DELETE FROM sessions WHERE token = :token', ['token' => $token]);
    }

    /**
     * @return array{user: array<string, mixed>, companies: list<array<string, mixed>>}|null
     */
    public function session(string $token): ?array
    {
        $row = $this->db->one(
            'SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = :token AND s.expires_at > NOW()',
            ['token' => $token],
        );
        if ($row === null) {
            return null;
        }

        return [
            'user' => $this->userResource($row),
            'companies' => $this->companiesFor((string) $row['id']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function companiesFor(string $userId): array
    {
        $rows = $this->db->all(
            'SELECT c.*, m.role FROM companies c JOIN memberships m ON m.company_id = c.id WHERE m.user_id = :user_id ORDER BY c.created_at',
            ['user_id' => $userId],
        );

        return array_map($this->companyResource(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function createCompany(string $userId, string $name, string $mission): array
    {
        $id = Ids::uuid();
        $this->db->transaction(function () use ($id, $userId, $name, $mission): void {
            $this->db->exec(
                'INSERT INTO companies (id, name, mission) VALUES (:id, :name, :mission)',
                ['id' => $id, 'name' => $name, 'mission' => $mission],
            );
            $this->db->exec(
                'INSERT INTO memberships (company_id, user_id, role) VALUES (:company_id, :user_id, :role)',
                ['company_id' => $id, 'user_id' => $userId, 'role' => 'owner'],
            );
        });

        return $this->requireCompany($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function requireCompany(string $userId, string $companyId): array
    {
        $membership = $this->membership($userId, $companyId);
        if ($membership === null) {
            throw new HolderException('not_found', 'Company not found.', 404);
        }

        return $this->companyResource($membership);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCompany(string $userId, string $companyId, string $name, string $mission): array
    {
        $membership = $this->requireMembership($userId, $companyId);
        $this->assertRole((string) $membership['role'], ['owner', 'admin']);
        $this->db->exec(
            'UPDATE companies SET name = :name, mission = :mission WHERE id = :id',
            ['name' => $name, 'mission' => $mission, 'id' => $companyId],
        );

        return $this->requireCompany($userId, $companyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function setGithubToken(string $userId, string $companyId, string $token): array
    {
        $membership = $this->requireMembership($userId, $companyId);
        $this->assertCanManage((string) $membership['role']);
        $token = trim($token);
        $stored = $token === '' ? null : $this->tokens->seal($token);
        $this->db->exec(
            'UPDATE companies SET github_token = :github_token WHERE id = :id',
            ['github_token' => $stored, 'id' => $companyId],
        );

        return $this->requireCompany($userId, $companyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function invite(string $userId, string $companyId, string $email, string $role): array
    {
        $membership = $this->requireMembership($userId, $companyId);
        $this->assertRole((string) $membership['role'], ['owner', 'admin']);
        $this->assertRole($role, ['admin', 'member', 'viewer']);
        $id = Ids::uuid();
        $token = Ids::token();
        $this->db->exec(
            'INSERT INTO invites (id, company_id, email, role, token) VALUES (:id, :company_id, :email, :role, :token)',
            [
                'id' => $id,
                'company_id' => $companyId,
                'email' => $email,
                'role' => $role,
                'token' => $token,
            ],
        );

        return [
            'id' => $id,
            'email' => $email,
            'role' => $role,
            'token' => $token,
        ];
    }

    /**
     * @return array{token: string, user: array<string, mixed>, companies: list<array<string, mixed>>}
     */
    public function acceptInvite(string $token, string $name, string $password): array
    {
        $invite = $this->db->one(
            'SELECT * FROM invites WHERE token = :token AND accepted_at IS NULL',
            ['token' => $token],
        );
        if ($invite === null) {
            throw new HolderException('not_found', 'Invite not found.', 404);
        }

        $email = (string) $invite['email'];
        $user = $this->db->one('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        if ($user === null) {
            if ($name === '' || $password === '') {
                throw new HolderException('missing_field', 'Name and password are required.', 422);
            }
            $userId = Ids::uuid();
            $this->db->exec(
                'INSERT INTO users (id, name, email, password_hash) VALUES (:id, :name, :email, :password_hash)',
                [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ],
            );
        } else {
            $userId = (string) $user['id'];
            if (!is_string($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                throw new HolderException('invalid_login', 'Password does not match the existing account.', 401);
            }
        }

        $this->db->transaction(function () use ($invite, $userId, $token): void {
            $this->db->exec(
                'INSERT INTO memberships (company_id, user_id, role) VALUES (:company_id, :user_id, :role) ON CONFLICT DO NOTHING',
                [
                    'company_id' => $invite['company_id'],
                    'user_id' => $userId,
                    'role' => $invite['role'],
                ],
            );
            $this->db->exec(
                'UPDATE invites SET accepted_at = NOW() WHERE token = :token',
                ['token' => $token],
            );
        });

        $fresh = $this->db->one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($fresh === null) {
            throw new HolderException('not_found', 'User not found.', 404);
        }

        $session = Ids::token();
        $this->db->exec(
            'INSERT INTO sessions (token, user_id, expires_at) VALUES (:token, :user_id, NOW() + INTERVAL \'30 days\')',
            ['token' => $session, 'user_id' => $userId],
        );

        return [
            'token' => $session,
            'user' => $this->userResource($fresh),
            'companies' => $this->companiesFor($userId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requireMembership(string $userId, string $companyId): array
    {
        $membership = $this->membership($userId, $companyId);
        if ($membership === null) {
            throw new HolderException('not_found', 'Company not found.', 404);
        }

        return $membership;
    }

    public function assertCanWrite(string $role): void
    {
        $this->assertRole($role, ['owner', 'admin', 'member']);
    }

    public function assertCanManage(string $role): void
    {
        $this->assertRole($role, ['owner', 'admin']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function membership(string $userId, string $companyId): ?array
    {
        return $this->db->one(
            'SELECT c.*, m.role FROM companies c JOIN memberships m ON m.company_id = c.id WHERE c.id = :company_id AND m.user_id = :user_id',
            ['company_id' => $companyId, 'user_id' => $userId],
        );
    }

    /**
     * @param list<string> $allowed
     */
    private function assertRole(string $role, array $allowed): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new HolderException('invalid_role', 'Role must be owner, admin, member, or viewer.', 422);
        }
        if (!in_array($role, $allowed, true)) {
            throw new HolderException('forbidden', 'You cannot do that in this company.', 403);
        }
    }

    private function createOwner(string $email, string $name, string $passwordHash, string $company, string $mission): void
    {
        $userId = Ids::uuid();
        $companyId = Ids::uuid();
        $this->db->transaction(function () use ($userId, $companyId, $email, $name, $passwordHash, $company, $mission): void {
            $this->db->exec(
                'INSERT INTO users (id, name, email, password_hash) VALUES (:id, :name, :email, :password_hash)',
                ['id' => $userId, 'name' => $name, 'email' => $email, 'password_hash' => $passwordHash],
            );
            $this->db->exec(
                'INSERT INTO companies (id, name, mission) VALUES (:id, :name, :mission)',
                ['id' => $companyId, 'name' => $company, 'mission' => $mission],
            );
            $this->db->exec(
                'INSERT INTO memberships (company_id, user_id, role) VALUES (:company_id, :user_id, :role)',
                ['company_id' => $companyId, 'user_id' => $userId, 'role' => 'owner'],
            );
        });
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function userResource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function companyResource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'mission' => (string) $row['mission'],
            'role' => (string) $row['role'],
            'workspacePath' => $this->workspaces->ensure((string) $row['id']),
            'githubConnected' => is_string($row['github_token'] ?? null) && $row['github_token'] !== '',
        ];
    }
}
