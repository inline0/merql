import assert from "node:assert/strict";
import { existsSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { test } from "node:test";
import * as Effect from "effect/Effect";
import * as NodeServices from "@effect/platform-node/NodeServices";
import * as Command from "./lib/command.ts";
import { coverage, coveredFiles } from "./verify-merge-primitives-coverage.ts";

test("coverage previews skip directories and retain the PHPUnit and threshold command arguments", async () => {
    const directory = mkdtempSync(join(tmpdir(), "merql-coverage-"));
    const calls: Command.CommandCall[] = [];
    try {
        await Effect.runPromise(coverage({ json: true, dryRun: true }, directory).pipe(
            Effect.provideService(Command.Command, { run: (call) => { calls.push(call); return Effect.succeed({ exitCode: 0, stdout: "", stderr: "" }); } }),
            Effect.provide(NodeServices.layer),
        ));
        assert.equal(existsSync(join(directory, "build")), false);
        assert.deepEqual(calls[0].env, { XDEBUG_MODE: "coverage" });
        assert.deepEqual(calls[1].args?.slice(2), coveredFiles);
        assert.equal(coveredFiles.length, 20);
    } finally { rmSync(directory, { recursive: true, force: true }); }
});
