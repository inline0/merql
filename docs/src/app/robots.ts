import { generateRobots } from "onedocs/seo";

const baseUrl = "https://merql.dev";

export default function robots() {
  return generateRobots({ baseUrl });
}
