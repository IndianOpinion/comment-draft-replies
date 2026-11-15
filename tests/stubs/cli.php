<?php

declare(strict_types=1);

namespace {
    class WP_CLI_Halt extends RuntimeException
    {
    }

    class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var array<string, mixed> */
        public static array $commands = [];

        public static function line(string $m = ''): void
        {
            self::$out[] = $m;
        }

        public static function success(string $m): void
        {
            self::$out[] = 'Success: ' . $m;
        }

        public static function warning(string $m): void
        {
            self::$out[] = 'Warning: ' . $m;
        }

        public static function error(string $m): void
        {
            self::$out[] = 'Error: ' . $m;
            throw new WP_CLI_Halt($m, 1);
        }

        public static function halt(int $code): void
        {
            throw new WP_CLI_Halt('halt', $code);
        }

        public static function add_command(string $name, mixed $callable): void
        {
            self::$commands[$name] = $callable;
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) json_encode($items));
            return;
        }
        \WP_CLI::line(implode("\t", $fields));
        foreach ($items as $i) {
            \WP_CLI::line(implode("\t", array_map(static fn ($f) => (string) ($i[$f] ?? ''), $fields)));
        }
    }
}
