<?php

declare(strict_types=1);

namespace Auth\Service;

use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Session\Container;

/**
 * AuthService
 *
 * Servicio de autenticación que conecta contra la tabla `users` de mavoo_gestion.
 * Re-implementa la lógica de Laravel Breeze (email + password bcrypt).
 */
class AuthService
{
    private AuthenticationService $authService;

    private Adapter $dbAdapter;

    public function __construct(AuthenticationService $authService, Adapter $dbAdapter)
    {
        $this->authService = $authService;
        $this->dbAdapter = $dbAdapter;
    }

    /**
     * Intenta autenticar al usuario.
     * Nota: Laravel usa bcrypt, por lo que usamos un adaptador personalizado
     * que verifica con password_verify en lugar de comparación directa.
     */
    public function login(string $email, string $password): bool
    {
        // Buscar usuario en BD
        $stmt = $this->dbAdapter->query(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email]
        );
        $user = $stmt->current();

        if (! $user) {
            return false;
        }

        // Verificar contraseña con bcrypt (compatible con Laravel)
        if (! password_verify($password, $user['password'])) {
            return false;
        }

        // Guardar identidad en sesión
        $session = new Container('auth');
        $session->identity = [
            'id' => $user['id'],
            'uuid' => $user['uuid'],
            'name' => $user['name'],
            'email' => $user['email'],
        ];

        // Cargar roles y permisos del usuario (tablas de Spatie)
        $session->roles = $this->getUserRoles((int) $user['id']);
        $session->permissions = $this->getUserPermissions((int) $user['id']);

        return true;
    }

    /**
     * Cierra la sesión del usuario.
     */
    public function logout(): void
    {
        $session = new Container('auth');
        $session->getManager()->destroy();
    }

    /**
     * Retorna la identidad actual del usuario o null.
     */
    public function getIdentity(): ?array
    {
        $session = new Container('auth');

        return $session->identity ?? null;
    }

    /**
     * Verifica si hay un usuario autenticado.
     */
    public function isAuthenticated(): bool
    {
        return $this->getIdentity() !== null;
    }

    /**
     * Verifica si el usuario tiene un rol específico.
     */
    public function hasRole(string $role): bool
    {
        $session = new Container('auth');
        $roles = $session->roles ?? [];

        return in_array($role, $roles, true);
    }

    /**
     * Verifica si el usuario tiene un permiso específico (por rol o asignación directa).
     */
    public function isAllowed(string $permission): bool
    {
        // 'dios' tiene permiso para todo
        if ($this->hasRole('dios')) {
            return true;
        }

        $session = new Container('auth');
        $permissions = $session->permissions ?? [];

        return in_array($permission, $permissions, true);
    }

    /**
     * Carga los roles del usuario desde las tablas de Spatie.
     */
    public function getUserRoles(int $userId): array
    {
        $result = $this->dbAdapter->query(
            'SELECT r.name FROM roles r
             INNER JOIN model_has_roles mhr ON mhr.role_id = r.id
             WHERE mhr.model_id = ? AND mhr.model_type = ?',
            [$userId, 'App\\Models\\User']
        );

        $roles = [];
        foreach ($result as $row) {
            $roles[] = $row['name'];
        }

        return $roles;
    }

    /**
     * Carga los permisos del usuario (directos + heredados de roles).
     */
    public function getUserPermissions(int $userId): array
    {
        // Permisos directos del usuario
        $directResult = $this->dbAdapter->query(
            'SELECT p.name FROM permissions p
             INNER JOIN model_has_permissions mhp ON mhp.permission_id = p.id
             WHERE mhp.model_id = ? AND mhp.model_type = ?',
            [$userId, 'App\\Models\\User']
        );

        // Permisos heredados de los roles del usuario
        $roleResult = $this->dbAdapter->query(
            'SELECT p.name FROM permissions p
             INNER JOIN role_has_permissions rhp ON rhp.permission_id = p.id
             INNER JOIN model_has_roles mhr ON mhr.role_id = rhp.role_id
             WHERE mhr.model_id = ? AND mhr.model_type = ?',
            [$userId, 'App\\Models\\User']
        );

        $permissions = [];
        foreach ($directResult as $row) {
            $permissions[] = $row['name'];
        }
        foreach ($roleResult as $row) {
            $permissions[] = $row['name'];
        }

        return array_unique($permissions);
    }
}
