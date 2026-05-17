<?php

namespace App\Service;

final class ApiTokenManager
{
    public function generateToken(): string
    {
        return 'ndb_'.bin2hex(random_bytes(32));
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function prefixFor(string $plainToken): string
    {
        return substr($plainToken, 0, 12);
    }
}
