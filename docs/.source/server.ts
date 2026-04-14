// @ts-nocheck
import { default as __fd_glob_18 } from "../content/docs/merge/meta.json?collection=docs"
import { default as __fd_glob_17 } from "../content/docs/apply/meta.json?collection=docs"
import { default as __fd_glob_16 } from "../content/docs/advanced/meta.json?collection=docs"
import { default as __fd_glob_15 } from "../content/docs/meta.json?collection=docs"
import * as __fd_glob_14 from "../content/docs/merge/three-way.mdx?collection=docs"
import * as __fd_glob_13 from "../content/docs/merge/conflicts.mdx?collection=docs"
import * as __fd_glob_12 from "../content/docs/merge/column-level.mdx?collection=docs"
import * as __fd_glob_11 from "../content/docs/merge/cell-level.mdx?collection=docs"
import * as __fd_glob_10 from "../content/docs/apply/sql-generation.mdx?collection=docs"
import * as __fd_glob_9 from "../content/docs/apply/dry-run.mdx?collection=docs"
import * as __fd_glob_8 from "../content/docs/apply/drivers.mdx?collection=docs"
import * as __fd_glob_7 from "../content/docs/advanced/testing.mdx?collection=docs"
import * as __fd_glob_6 from "../content/docs/advanced/schema.mdx?collection=docs"
import * as __fd_glob_5 from "../content/docs/advanced/identity.mdx?collection=docs"
import * as __fd_glob_4 from "../content/docs/advanced/filters.mdx?collection=docs"
import * as __fd_glob_3 from "../content/docs/index.mdx?collection=docs"
import * as __fd_glob_2 from "../content/docs/getting-started.mdx?collection=docs"
import * as __fd_glob_1 from "../content/docs/cli.mdx?collection=docs"
import * as __fd_glob_0 from "../content/docs/api.mdx?collection=docs"
import { server } from 'fumadocs-mdx/runtime/server';
import type * as Config from '../source.config';

const create = server<typeof Config, import("fumadocs-mdx/runtime/types").InternalTypeConfig & {
  DocData: {
  }
}>({"doc":{"passthroughs":["extractedReferences"]}});

export const docs = await create.docs("docs", "content/docs", {"meta.json": __fd_glob_15, "advanced/meta.json": __fd_glob_16, "apply/meta.json": __fd_glob_17, "merge/meta.json": __fd_glob_18, }, {"api.mdx": __fd_glob_0, "cli.mdx": __fd_glob_1, "getting-started.mdx": __fd_glob_2, "index.mdx": __fd_glob_3, "advanced/filters.mdx": __fd_glob_4, "advanced/identity.mdx": __fd_glob_5, "advanced/schema.mdx": __fd_glob_6, "advanced/testing.mdx": __fd_glob_7, "apply/drivers.mdx": __fd_glob_8, "apply/dry-run.mdx": __fd_glob_9, "apply/sql-generation.mdx": __fd_glob_10, "merge/cell-level.mdx": __fd_glob_11, "merge/column-level.mdx": __fd_glob_12, "merge/conflicts.mdx": __fd_glob_13, "merge/three-way.mdx": __fd_glob_14, });