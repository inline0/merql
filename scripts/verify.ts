import { fileURLToPath } from "node:url";
import * as Console from "effect/Console";
import * as Effect from "effect/Effect";
import { main, script, type ScriptInput } from "./lib/cli.ts";
import * as Command from "./lib/command.ts";

export const root = fileURLToPath(new URL("../", import.meta.url));
export const checks = [
    { "name": "TypeScript tooling", "command": "composer", "args": ["run-script", "verify:tooling", "--no-interaction"] },
    {
        "name": "PHPStan",
        "command": "vendor/bin/phpstan",
        "args": [
            "analyse"
        ]
    },
    {
        "name": "PHPCS",
        "command": "vendor/bin/phpcs",
        "args": []
    },
    {
        "name": "PHPUnit (unit)",
        "command": "vendor/bin/phpunit",
        "args": [
            "--testsuite=unit"
        ]
    },
    {
        "name": "PHPUnit (integration)",
        "command": "vendor/bin/phpunit",
        "args": [
            "--testsuite=integration"
        ]
    },
    {
        "name": "Oracle Regression",
        "command": "php",
        "args": [
            "bin/test-regression"
        ]
    }
] as const;

export const verify = (input: ScriptInput, directory = root) => Effect.gen(function* () {
    for (const check of checks) {
        yield* Console.error(input.json ? JSON.stringify({ check: check.name, status: "running" }) : `=== ${check.name} ===`);
        yield* Command.run({ command: check.command, args: check.args, cwd: directory, inherit: true });
    }
    yield* Console.log(input.json ? JSON.stringify({ checks: checks.length, success: true, dryRun: input.dryRun }) : "All checks passed.");
});
export const command = script("verify", {
    description: "Run the complete repository verification sequence, stopping at the first failure.",
    flags: {}, run: verify,
});
if (import.meta.main) main(command);
