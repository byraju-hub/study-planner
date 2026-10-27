<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($type, 'application/json') !== false) {
                $raw = (string) file_get_contents('php://input');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        throw new HttpException(400, 'bad_json', '요청 형식이 올바르지 않습니다.');
                    }
                    $body = $decoded;
                }
            } else {
                $body = $_POST;
            }
        }

        return new self($method, $path, $_GET, $body, $_FILES, $_SERVER);
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? '');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
