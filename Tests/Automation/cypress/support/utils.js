// Utility functions for Cypress tests

/**
 * Generate random test data
 */
export const generateTestData = {
  randomEmail: () => `test${Date.now()}@example.com`,
  randomUsername: () => `user${Date.now()}`,
  randomString: (length = 10) => Math.random().toString(36).substring(2, length + 2),
  randomNumber: (min = 1, max = 1000) => Math.floor(Math.random() * (max - min + 1)) + min,
}

/**
 * Wait utilities
 */
export const waitUtils = {
  forPageLoad: () => cy.get('body').should('be.visible'),
  forApiResponse: (alias) => cy.wait(alias),
  forElement: (selector, timeout = 10000) => cy.get(selector, { timeout }).should('be.visible'),
}

/**
 * Assertion helpers
 */
export const assertionHelpers = {
  shouldBeVisible: (element) => cy.get(element).should('be.visible'),
  shouldNotExist: (element) => cy.get(element).should('not.exist'),
  shouldContainText: (element, text) => cy.get(element).should('contain.text', text),
  shouldHaveClass: (element, className) => cy.get(element).should('have.class', className),
}

/**
 * Form helpers
 */
export const formHelpers = {
  fillForm: (formData) => {
    Object.keys(formData).forEach(field => {
      cy.get(`[data-cy="${field}"]`).clearAndType(formData[field])
    })
  },
  submitForm: (formSelector = 'form') => {
    cy.get(formSelector).submit()
  },
}

/**
 * API helpers
 */
export const apiHelpers = {
  interceptApi: (method, url, alias) => {
    cy.intercept(method, url).as(alias)
  },
  mockApiResponse: (method, url, response, alias) => {
    cy.intercept(method, url, response).as(alias)
  },
}

/**
 * File upload helpers
 */
export const fileHelpers = {
  uploadFile: (selector, fileName) => {
    cy.get(selector).selectFile(`cypress/fixtures/${fileName}`)
  },
  downloadFile: (downloadSelector) => {
    cy.get(downloadSelector).click()
    cy.readFile('cypress/downloads').should('exist')
  },
}

/**
 * Database helpers (if using database plugin)
 */
export const dbHelpers = {
  queryDb: (query) => {
    return cy.task('queryDb', query)
  },
  seedDb: (data) => {
    return cy.task('seedDb', data)
  },
  clearDb: () => {
    return cy.task('clearDb')
  },
}
