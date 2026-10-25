import { fileURLToPath } from "node:url";
import { join } from "node:path";
import * as Console from "effect/Console";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { main, script, type ScriptInput } from "./lib/cli.ts";
import * as Command from "./lib/command.ts";

export const root = fileURLToPath(new URL("../", import.meta.url));
export const coveredFiles = [
    "src/Apply/GuardedApplier.php",
    "src/Identity/IdentityConflict.php",
    "src/Identity/IdentityRule.php",
    "src/Identity/IdentityRuleSet.php",
    "src/Plan/ChangeGroupSelection.php",
    "src/Plan/MergePlan.php",
    "src/Plan/MergePlanBuilder.php",
    "src/Plan/MergePlanChangeGroup.php",
    "src/Plan/MergePlanConflict.php",
    "src/Plan/MergePlanOperation.php",
    "src/Plan/MergePlanSerializer.php",
    "src/Plan/MergePlanSummary.php",
    "src/Plan/OperationSelection.php",
    "src/Plan/SelectedMergeResultFactory.php",
    "src/Rollback/RollbackDrift.php",
    "src/Rollback/RollbackOperation.php",
    "src/Rollback/RollbackPlan.php",
    "src/Rollback/RollbackPlanApplier.php",
    "src/Rollback/RollbackPlanBuilder.php",
    "src/Rollback/RollbackPlanSerializer.php"
] as const;
export const coverage = (input: ScriptInput, directory = root) => Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    if (!input.dryRun) yield* fs.makeDirectory(join(directory, "build/coverage"), { recursive: true });
    yield* Command.run({ command: "vendor/bin/phpunit", cwd: directory, inherit: true,
        env: { XDEBUG_MODE: "coverage" }, args: [
            "--coverage-clover", "build/coverage/merge-primitives.xml", "--coverage-filter", "src",
            "tests/Unit/Plan", "tests/Unit/Rollback", "tests/Unit/Identity", "tests/Unit/Apply", "tests/Unit/Snapshot",
        ],
    });
    yield* Command.run({ command: "php", cwd: directory, inherit: true,
        args: ["bin/check-coverage", "build/coverage/merge-primitives.xml", ...coveredFiles],
    });
    yield* Console.log(input.json ? JSON.stringify({ success: true, dryRun: input.dryRun, files: coveredFiles.length }) : "Merge primitive coverage passed.");
});
export const command = script("verify-merge-primitives-coverage", {
    description: "Collect and enforce merge primitive coverage.", flags: {}, run: coverage,
});
if (import.meta.main) main(command);
