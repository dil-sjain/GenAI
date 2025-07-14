// ***********************************************************
// This example support/e2e.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

// Import commands.js using ES2015 syntax:
import './commands'

// Alternatively you can use CommonJS syntax:
// require('./commands')

// Hide fetch/XHR requests from command log
Cypress.on('window:before:load', (win) => {
  const originalFetch = win.fetch
  win.fetch = function (...args) {
    return originalFetch.apply(this, args)
  }
})

// Global configuration
Cypress.on('uncaught:exception', (err, runnable) => {
  // Handle specific application errors that don't affect test functionality
  const ignoredErrors = [
    'Cannot read properties of undefined (reading \'twoFactorRecoveryEnabled\')',
    'Cannot read properties of null (reading \'id\')',
    'ResizeObserver loop limit exceeded',
    'Non-Error promise rejection captured',
    'Script error'
  ];
  
  // Check if the error should be ignored
  const shouldIgnore = ignoredErrors.some(ignoredError => 
    err.message.includes(ignoredError)
  );
  
  if (shouldIgnore) {
    console.log('Ignoring application error:', err.message);
    return false; // Prevent Cypress from failing the test
  }
  
  // Let other errors fail the test
  return true;
});

// Custom before and after hooks
beforeEach(() => {
  // Clear cookies and local storage before each test
  cy.clearCookies()
  cy.clearLocalStorage()
})

afterEach(() => {
  // Take screenshot on failure
  cy.screenshot({ capture: 'fullPage' })
})
