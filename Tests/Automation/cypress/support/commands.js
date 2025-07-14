// ***********************************************
// This example commands.js shows you how to
// create various custom commands and overwrite
// existing commands.
//
// For more comprehensive examples of custom
// commands please read more here:
// https://on.cypress.io/custom-commands
// ***********************************************

// TPM Application specific login command
Cypress.Commands.add('loginToTPM', (username = 'automationsuperadmin@diligent.com', password = 'Welcome@12345') => {
  cy.visit('https://dev3.steeleglobal.net/')
  
  // Handle any uncaught exceptions during login
  cy.on('uncaught:exception', (err) => {
    if (err.message.includes('twoFactorRecoveryEnabled') || 
        err.message.includes('Cannot read properties of null')) {
      return false
    }
    return true
  })
  
  cy.get('input[id="loginID"]').type(username)
  cy.get('input[id="pw"]').type(password)
  cy.get('input[id="btnSubmit"]').click()
  
  // Wait for successful login and dashboard load
  cy.url().should('include', '/dashboard')
  cy.wait(3000) // Allow time for all dashboard components to load
  
  cy.log(`✅ Successfully logged in as ${username}`)
})

// Enhanced navigation command for TPM
Cypress.Commands.add('navigateToTPMSection', (sectionName) => {
  cy.get('body').then(($body) => {
    // Try multiple selector strategies
    const selectors = [
      `[data-cy="${sectionName.toLowerCase().replace(' ', '-')}-tab"]`,
      `a[href*="${sectionName.toLowerCase()}"]`,
      `button:contains("${sectionName}")`,
      `a:contains("${sectionName}")`
    ]
    
    let found = false
    selectors.forEach(selector => {
      if (!found && $body.find(selector).length > 0) {
        cy.get(selector).first().click()
        found = true
        cy.log(`✅ Navigated to ${sectionName} using selector: ${selector}`)
      }
    })
    
    if (!found) {
      cy.contains(sectionName).click()
      cy.log(`✅ Navigated to ${sectionName} using text search`)
    }
  })
})

// Wait for TPM page to fully load
Cypress.Commands.add('waitForTPMPageLoad', () => {
  // Wait for common TPM page indicators
  cy.get('body').should('be.visible')
  
  // Wait for any loading indicators to disappear
  cy.get('body').then(($body) => {
    if ($body.find('.loading, .spinner, [data-cy="loading"]').length > 0) {
      cy.get('.loading, .spinner, [data-cy="loading"]', { timeout: 10000 }).should('not.exist')
    }
  })
  
  // Additional wait for AJAX calls to complete
  cy.wait(2000)
  cy.log('✅ TPM page fully loaded')
})

// Login command
Cypress.Commands.add('login', (username, password) => {
  cy.visit('/login')
  cy.get('[data-cy="username"]').type(username)
  cy.get('[data-cy="password"]').type(password)
  cy.get('[data-cy="login-button"]').click()
  cy.url().should('not.include', '/login')
})

// Custom command to get element by data-cy attribute
Cypress.Commands.add('getByDataCy', (selector) => {
  return cy.get(`[data-cy=${selector}]`)
})

// Custom command to wait for API response
Cypress.Commands.add('waitForApi', (alias) => {
  cy.wait(alias).then((interception) => {
    expect(interception.response.statusCode).to.be.oneOf([200, 201, 204])
  })
})

// Custom command to check if element is visible and enabled
Cypress.Commands.add('shouldBeVisibleAndEnabled', { prevSubject: true }, (subject) => {
  cy.wrap(subject).should('be.visible').and('be.enabled')
})

// Custom command to select dropdown option
Cypress.Commands.add('selectDropdownOption', (selector, option) => {
  cy.get(selector).click()
  cy.get(`[data-cy="${option}"]`).click()
})

// Custom command to upload file
Cypress.Commands.add('uploadFile', (selector, fileName) => {
  cy.get(selector).selectFile(`cypress/fixtures/${fileName}`)
})

// Custom command to clear and type
Cypress.Commands.add('clearAndType', { prevSubject: true }, (subject, text) => {
  cy.wrap(subject).clear().type(text)
})

// Custom command to handle vendor user login
Cypress.Commands.add('loginAsVendor', () => {
  cy.login(Cypress.env('vendorUsername') || 'vendor_user', Cypress.env('vendorPassword') || 'vendor_pass')
})

// Custom command to handle admin user login
Cypress.Commands.add('loginAsAdmin', () => {
  cy.login(Cypress.env('adminUsername') || 'admin_user', Cypress.env('adminPassword') || 'admin_pass')
})

// Custom command to handle read-only user login
Cypress.Commands.add('loginAsReadOnly', () => {
  cy.login(Cypress.env('readOnlyUsername') || 'readonly_user', Cypress.env('readOnlyPassword') || 'readonly_pass')
})

// Command to check if user has access denied message
Cypress.Commands.add('checkAccessDenied', () => {
  cy.contains('Access denied').should('be.visible')
  // Or check for "Invalid Access!" message
  cy.get('body').then(($body) => {
    if ($body.text().includes('Invalid Access!')) {
      cy.contains('Invalid Access!').should('be.visible')
    }
  })
})
