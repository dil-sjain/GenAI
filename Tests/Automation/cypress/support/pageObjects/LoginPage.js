// Page Object Model for Login Page
class LoginPage {
  // Selectors
  get usernameField() { return cy.get('[data-cy="username"]') }
  get passwordField() { return cy.get('[data-cy="password"]') }
  get loginButton() { return cy.get('[data-cy="login-button"]') }
  get errorMessage() { return cy.get('[data-cy="error-message"]') }

  // Actions
  visit() {
    cy.visit('/login')
    return this
  }

  fillUsername(username) {
    this.usernameField.clearAndType(username)
    return this
  }

  fillPassword(password) {
    this.passwordField.clearAndType(password)
    return this
  }

  clickLogin() {
    this.loginButton.click()
    return this
  }

  login(username, password) {
    this.fillUsername(username)
    this.fillPassword(password)
    this.clickLogin()
    return this
  }

  // Assertions
  shouldShowErrorMessage(message) {
    this.errorMessage.should('contain.text', message)
    return this
  }

  shouldRedirectToDashboard() {
    cy.url().should('not.include', '/login')
    cy.url().should('include', '/dashboard')
    return this
  }
}

export default new LoginPage()
