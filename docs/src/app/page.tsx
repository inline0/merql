import { HomePage, CTASection } from "onedocs";
import config from "../../onedocs.config";

export default function Home() {
  return (
    <HomePage config={config}>
      <CTASection
        title="Ready to search smarter?"
        description="Install the Composer package and run your first text or AST search in seconds."
        cta={{ label: "Read the Docs", href: "/docs" }}
      />
    </HomePage>
  );
}
