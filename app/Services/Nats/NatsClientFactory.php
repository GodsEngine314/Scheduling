<?php

namespace App\Services\Nats;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Exception;

class NatsClientFactory
{
    public function make(): Client
    {
        $host = (string) config('nats.host');
        $port = (int) config('nats.port');

        if ($host === '' || $port <= 0) {
            throw new Exception('NATS host/port not configured (nats.host/nats.port).');
        }

        $token = config('nats.token');
        $user  = config('nats.user');
        $pass  = config('nats.pass');

        $opts = ['host' => $host, 'port' => $port];

        if (!empty($token)) {
            $opts['token'] = (string) $token;
        } elseif (!empty($user) || !empty($pass)) {
            if (empty($user) || empty($pass)) {
                throw new Exception('NATS user/pass requires BOTH nats.user and nats.pass.');
            }
            $opts['user'] = (string) $user;
            $opts['pass'] = (string) $pass;
        } else {
            throw new Exception('NATS auth not configured (set token OR user+pass).');
        }

        return new Client(new Configuration($opts));
    }
}
