const { defineConfig } = require('cypress')

module.exports = defineConfig({
  e2e: {
    baseUrl: 'https://dev3.steeleglobal.net', // Updated to your TPM application URL
    viewportWidth: 1280,
    viewportHeight: 720,
    defaultCommandTimeout: 10000,
    requestTimeout: 15000,
    responseTimeout: 30000,
    pageLoadTimeout: 60000, // Increased for slower loading pages
    video: false,
    screenshot: false,
    screenshotOnRunFailure: false,
    trashAssetsBeforeRuns: true,
    chromeWebSecurity: false, // May help with cross-origin issues
    failOnStatusCode: false, // Don't fail on HTTP error status codes
    setupNodeEvents(on, config) {
      // implement node event listeners here
      on('task', {
        log(message) {
          console.log(message)
          return null
        },
      })
    },
    specPattern: 'cypress/e2e/**/*.cy.{js,jsx,ts,tsx}',
    supportFile: 'cypress/support/e2e.js',
    fixturesFolder: 'cypress/fixtures',
    screenshotsFolder: 'cypress/screenshots',
    videosFolder: 'cypress/videos',
    downloadsFolder: 'cypress/downloads',
  },
  component: {
    devServer: {
      framework: 'react',
      bundler: 'webpack',
    },
    specPattern: 'src/**/*.cy.{js,jsx,ts,tsx}',
    supportFile: 'cypress/support/component.js',
  },
  env: {
    // Define environment variables here
    apiUrl: 'http://localhost:3000/api',
    username: 'testuser',
    password: 'testpassword',
  },
})
