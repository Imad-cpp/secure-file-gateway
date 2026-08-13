<?php

namespace App\Infrastructure;

use Throwable;

class ClamAvHealthProbe
{
    public function check(): bool
    {
        $socket = null;

        try {
            $host = (string) config('scanner.host');
            $port = (int) config('scanner.port');
            $timeout = (float) config('scanner.connect_timeout_seconds');
            $errno = 0;
            $error = '';

            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $host, $port),
                $errno,
                $error,
                $timeout,
                STREAM_CLIENT_CONNECT,
            );

            if (! is_resource($socket)) {
                return false;
            }

            stream_set_timeout($socket, (int) config('scanner.read_timeout_seconds'));

            if (fwrite($socket, "zPING\0") === false) {
                return false;
            }

            $reply = '';

            while (! feof($socket) && ! str_contains($reply, "\0") && strlen($reply) <= 64) {
                $chunk = fread($socket, 64);

                if ($chunk === false) {
                    return false;
                }

                $reply .= $chunk;

                $metadata = stream_get_meta_data($socket);

                if (($metadata['timed_out'] ?? false) === true) {
                    return false;
                }
            }

            return trim($reply, "\0\r\n ") === 'PONG';
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}
