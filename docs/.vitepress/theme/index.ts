import DefaultTheme from "vitepress/theme";
import "./custom.css";
import InlineSvg from "./components/InlineSvg.vue";

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component("InlineSvg", InlineSvg);
  },
};
