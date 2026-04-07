import { defineConfig } from "vitepress";
import llmstxt from "vitepress-plugin-llms";

export default defineConfig({
  title: "Laravel Integrations",
  description:
    "API integrations without the boilerplate. Credentials, logging, retries, rate limiting, health monitoring, OAuth2, webhooks, and sync scheduling for Laravel.",

  lastUpdated: true,
  cleanUrls: true,

  srcExclude: ["**/README.md"],

  head: [
    ["link", { rel: "icon", href: "/favicon.ico" }],
    ["meta", { name: "author", content: "Bruno Moreira" }],

    // OpenGraph
    ["meta", { property: "og:type", content: "website" }],
    ["meta", { property: "og:site_name", content: "Laravel Integrations" }],
    [
      "meta",
      { property: "og:title", content: "Laravel Integrations" },
    ],
    [
      "meta",
      {
        property: "og:description",
        content:
          "API integrations without the boilerplate. Credentials, logging, retries, rate limiting, health monitoring, OAuth2, webhooks, and sync scheduling for Laravel.",
      },
    ],

    ["meta", { property: "og:image", content: "/og-image.png" }],

    // Twitter/X card
    ["meta", { name: "twitter:card", content: "summary_large_image" }],
    ["meta", { name: "twitter:site", content: "@pocketarc" }],
    ["meta", { name: "twitter:creator", content: "@pocketarc" }],
  ],

  themeConfig: {
    nav: [
      { text: "Guide", link: "/getting-started/introduction" },
      { text: "Reference", link: "/reference/contracts" },
      {
        text: "Packagist",
        link: "https://packagist.org/packages/pocketarc/laravel-integrations",
      },
      {
        text: "Blog",
        link: "https://pocketarc.com",
      },
    ],

    sidebar: [
      {
        text: "Getting Started",
        items: [
          { text: "Introduction", link: "/getting-started/introduction" },
          { text: "Installation", link: "/getting-started/installation" },
          { text: "Quick Start", link: "/getting-started/quick-start" },
          {
            text: "Scaffolding Providers",
            link: "/getting-started/scaffolding",
          },
        ],
      },
      {
        text: "Core Concepts",
        items: [
          { text: "Providers", link: "/core-concepts/providers" },
          {
            text: "Credentials & Metadata",
            link: "/core-concepts/credentials",
          },
          {
            text: "Making Requests",
            link: "/core-concepts/making-requests",
          },
          {
            text: "Response Caching",
            link: "/core-concepts/response-caching",
          },
          { text: "Retries", link: "/core-concepts/retries" },
          { text: "Rate Limiting", link: "/core-concepts/rate-limiting" },
          {
            text: "Health Monitoring",
            link: "/core-concepts/health-monitoring",
          },
          { text: "Logging", link: "/core-concepts/logging" },
        ],
      },
      {
        text: "Features",
        items: [
          { text: "OAuth2", link: "/features/oauth2" },
          { text: "Webhooks", link: "/features/webhooks" },
          { text: "Scheduled Syncs", link: "/features/scheduled-syncs" },
          { text: "ID Mapping", link: "/features/id-mapping" },
          { text: "Data Redaction", link: "/features/redaction" },
          { text: "Multi-tenancy", link: "/features/multi-tenancy" },
        ],
      },
      {
        text: "Testing",
        items: [{ text: "Testing", link: "/testing/testing" }],
      },
      {
        text: "Reference",
        items: [
          { text: "Contracts", link: "/reference/contracts" },
          { text: "Configuration", link: "/reference/configuration" },
          {
            text: "Artisan Commands",
            link: "/reference/artisan-commands",
          },
          { text: "Events", link: "/reference/events" },
          { text: "Database Schema", link: "/reference/database-schema" },
          { text: "Models", link: "/reference/models" },
        ],
      },
      {
        text: "Adapters",
        items: [
          { text: "Overview", link: "/adapters/overview" },
          { text: "GitHub", link: "/adapters/github" },
          { text: "Zendesk", link: "/adapters/zendesk" },
          {
            text: "Building Adapters",
            link: "/adapters/building-adapters",
          },
        ],
      },
      {
        text: "Advanced",
        items: [
          { text: "Custom Retry Logic", link: "/advanced/custom-retry" },
          {
            text: "Health Notifications",
            link: "/advanced/notifications",
          },
          { text: "Extending", link: "/advanced/extending" },
        ],
      },
    ],

    socialLinks: [
      {
        icon: "github",
        link: "https://github.com/pocketarc/laravel-integrations",
      },
      {
        icon: "x",
        link: "https://x.com/pocketarc",
      },
    ],

    search: {
      provider: "local",
    },

    editLink: {
      pattern:
        "https://github.com/pocketarc/laravel-integrations/edit/main/docs/:path",
      text: "Edit this page on GitHub",
    },

    footer: {
      message: "Released under the MIT License.",
      copyright:
        'Copyright 2026 <a href="https://pocketarc.com">Bruno Moreira</a>',
    },
  },

  vite: {
    plugins: [llmstxt()],
  },
});
