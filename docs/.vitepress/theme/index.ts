import DefaultTheme from "vitepress/theme";
import "./custom.css";
import InlineSvg from "./components/InlineSvg.vue";
import CopyOrDownloadAsMarkdownButtons from "vitepress-plugin-llms/vitepress-components/CopyOrDownloadAsMarkdownButtons.vue";

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component("InlineSvg", InlineSvg);
    app.component(
      "CopyOrDownloadAsMarkdownButtons",
      CopyOrDownloadAsMarkdownButtons,
    );
  },
};
