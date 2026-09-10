<?php

namespace App\Services\Auth;

use Illuminate\Session\Store;

class CsrfService
{
    public function __construct(private Store $session) {}

    public function token(): string
    {
        if (!$this->session->has('csrf_token')) {
            $this->session->put('csrf_token', bin2hex(random_bytes(32)));
        }
        return (string) $this->session->get('csrf_token');
    }

    public function verify(?string $token): bool
    {
        $stored = $this->session->get('csrf_token');
        return is_string($token) && is_string($stored) && hash_equals($stored, $token);
    }
}
