<?php

declare(strict_types=1);

namespace Jugador\Service;

use Laminas\Session\Container;

/**
 * AuthService específico del módulo Jugador.
 *
 * Lee del Container('auth') donde Auth\\Service\\AuthService guarda
 * la identidad del usuario después del login.
 */
class AuthService
{
    private Container $session;

    public function __construct()
    {
        $this->session = new Container('auth');
    }

    public function hasIdentity(): bool
    {
        return $this->session->offsetExists('identity') && ! empty($this->session->identity);
    }

    public function getIdentity(): ?array
    {
        if (! $this->hasIdentity()) {
            return null;
        }
        $identity = $this->session->identity;

        return is_array($identity) ? $identity : (array) $identity;
    }

    public function getIdentityOrNull(): ?array
    {
        $id = $this->getIdentity();

        return $id ?: null;
    }

    public function getUserId(): ?int
    {
        $id = $this->getIdentity();
        if (! $id) {
            return null;
        }

        return (int) ($id['id'] ?? $id->id ?? 0) ?: null;
    }
}
