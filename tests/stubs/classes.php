<?php

declare(strict_types=1);

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(string $code = ''): string
    {
        $code = $code !== '' ? $code : $this->get_error_code();

        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        $code = $code !== '' ? $code : $this->get_error_code();

        return $this->error_data[$code] ?? null;
    }
}

#[AllowDynamicProperties]
class WP_Comment
{
    public string $comment_ID = '0';
    public string $comment_post_ID = '0';
    public string $comment_author = '';
    public string $comment_author_email = '';
    public string $comment_author_url = '';
    public string $comment_author_IP = '';
    public string $comment_date = '';
    public string $comment_date_gmt = '';
    public string $comment_content = '';
    public string $comment_karma = '0';
    public string $comment_approved = '1';
    public string $comment_agent = '';
    public string $comment_type = 'comment';
    public string $comment_parent = '0';
    public string $user_id = '0';

    /** @param array<string, mixed> $row */
    public function __construct(array $row)
    {
        foreach ($row as $k => $v) {
            $this->$k = (string) $v;
        }
    }
}

#[AllowDynamicProperties]
class WP_Post
{
    public int $ID = 0;
    public string $post_author = '0';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'publish';
    public string $post_type = 'post';
    public string $post_password = '';
    public string $comment_status = 'open';

    /** @param array<string, mixed> $row */
    public function __construct(array $row)
    {
        foreach ($row as $k => $v) {
            $this->$k = $k === 'ID' ? (int) $v : (string) $v;
        }
    }
}

#[AllowDynamicProperties]
class WP_User
{
    public int $ID = 0;
    public string $display_name = '';
    public string $user_email = '';
    public string $user_url = '';
    /** @var list<string> */
    public array $caps = [];

    /** @param array<string, mixed> $row */
    public function __construct(array $row)
    {
        foreach ($row as $k => $v) {
            $this->$k = $v;
        }
    }

    public function exists(): bool
    {
        return $this->ID > 0;
    }
}

class WP_REST_Request implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(private string $method = 'GET', private string $route = '')
    {
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function get_params(): array
    {
        return $this->params;
    }

    public static function canonical(string $key): string
    {
        return str_replace('-', '_', strtolower($key));
    }

    public function get_header(string $key): ?string
    {
        return $this->headers[self::canonical($key)] ?? null;
    }

    public function set_header(string $key, string $value): void
    {
        $this->headers[self::canonical($key)] = $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->params[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->params[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
    }
}

class WP_REST_Response
{
    /** @var array<string, string> */
    public array $headers = [];

    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

class WPDieException extends RuntimeException
{
}

class WPRedirectException extends RuntimeException
{
}
