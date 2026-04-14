import { defineConfig } from "onedocs/config";
import {
  Columns3,
  Database,
  FileJson,
  GitMerge,
  Layers,
  Play,
  Shield,
  Terminal,
} from "lucide-react";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Merql",
  description:
    "Pure PHP three-way database merge with column-level conflict resolution. Git-style merge semantics applied to MySQL and SQLite tables.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: { light: "/icon.png", dark: "/icon-dark.png" },
  nav: {
    github: "inline0/merql",
  },
  footer: {
    links: [{ label: "Inline0.com", href: "https://inline0.com" }],
  },
  homepage: {
    features: [
      {
        title: "Three-Way Merge",
        description:
          "Base, ours, theirs. Same algorithm git uses, applied to relational data. Detects inserts, updates, and deletes across two branches.",
        icon: <GitMerge className={iconClass} />,
      },
      {
        title: "Column-Level Resolution",
        description:
          "When both sides change the same row, merql compares column by column. Only conflicts when the same column is changed to different values.",
        icon: <Columns3 className={iconClass} />,
      },
      {
        title: "Cell-Level Merge",
        description:
          "TEXT columns merge line-by-line using Myers diff. JSON columns merge key-by-key. Resolves cases that column-level merge would flag as conflicts.",
        icon: <Layers className={iconClass} />,
      },
      {
        title: "Multi-Database",
        description:
          "Pluggable driver system with MySQL and SQLite built in. Add any PDO-supported database by implementing five methods.",
        icon: <Database className={iconClass} />,
      },
      {
        title: "Conflict Detection",
        description:
          "Update vs update, update vs delete, insert vs insert. Every conflict type identified with table, row, and column precision.",
        icon: <Shield className={iconClass} />,
      },
      {
        title: "SQL Generation",
        description:
          "Parameterized INSERT, UPDATE, DELETE statements with FK-aware ordering. Dry-run preview or apply in a transaction.",
        icon: <Play className={iconClass} />,
      },
      {
        title: "CLI",
        description:
          "Snapshot, diff, and merge from the command line. Supports MySQL and SQLite via environment variables.",
        icon: <Terminal className={iconClass} />,
      },
      {
        title: "Oracle-Tested",
        description:
          "32 regression scenarios across 6 categories, 195 unit and integration tests, PHPStan level 8.",
        icon: <FileJson className={iconClass} />,
      },
    ],
  },
});
