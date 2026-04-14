// @ts-nocheck
import { browser } from 'fumadocs-mdx/runtime/browser';
import type * as Config from '../source.config';

const create = browser<typeof Config, import("fumadocs-mdx/runtime/types").InternalTypeConfig & {
  DocData: {
  }
}>();
const browserCollections = {
  docs: create.doc("docs", {"api.mdx": () => import("../content/docs/api.mdx?collection=docs"), "cli.mdx": () => import("../content/docs/cli.mdx?collection=docs"), "getting-started.mdx": () => import("../content/docs/getting-started.mdx?collection=docs"), "index.mdx": () => import("../content/docs/index.mdx?collection=docs"), "advanced/filters.mdx": () => import("../content/docs/advanced/filters.mdx?collection=docs"), "advanced/identity.mdx": () => import("../content/docs/advanced/identity.mdx?collection=docs"), "advanced/schema.mdx": () => import("../content/docs/advanced/schema.mdx?collection=docs"), "advanced/testing.mdx": () => import("../content/docs/advanced/testing.mdx?collection=docs"), "apply/drivers.mdx": () => import("../content/docs/apply/drivers.mdx?collection=docs"), "apply/dry-run.mdx": () => import("../content/docs/apply/dry-run.mdx?collection=docs"), "apply/sql-generation.mdx": () => import("../content/docs/apply/sql-generation.mdx?collection=docs"), "merge/cell-level.mdx": () => import("../content/docs/merge/cell-level.mdx?collection=docs"), "merge/column-level.mdx": () => import("../content/docs/merge/column-level.mdx?collection=docs"), "merge/conflicts.mdx": () => import("../content/docs/merge/conflicts.mdx?collection=docs"), "merge/three-way.mdx": () => import("../content/docs/merge/three-way.mdx?collection=docs"), }),
};
export default browserCollections;