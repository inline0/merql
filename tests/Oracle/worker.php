<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Merql\Tests\Oracle\ScenarioRunner;

try {
    /** @var array{name: string, category: string, path: string, config: array<string, mixed>} $scenario */
    $scenario = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $result = ScenarioRunner::run($scenario);
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit($result['pass'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
