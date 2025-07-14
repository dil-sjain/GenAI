// Page Object Model for Dashboard Page
class DashboardPage {
  // Selectors
  get dashboardContainer() { return cy.get('[data-cy="dashboard"]') }
  get orderDiligenceTab() { return cy.get('[data-cy="order-diligence-tab"]') }
  get orderDiligenceButton() { return cy.get('[data-cy="order-diligence-btn"]') }
  get caseManagementTab() { return cy.get('[data-cy="case-management-tab"]') }
  get thirdPartyManagementTab() { return cy.get('[data-cy="third-party-management-tab"]') }
  get userProfile() { return cy.get('[data-cy="user-profile"]') }
  get accessDeniedMessage() { return cy.contains('Invalid Access!') }

  // Actions
  visit() {
    cy.visit('/dashboard')
    return this
  }

  clickOrderDiligenceTab() {
    this.orderDiligenceTab.click()
    return this
  }

  clickOrderDiligenceButton() {
    this.orderDiligenceButton.click()
    return this
  }

  clickCaseManagementTab() {
    this.caseManagementTab.click()
    return this
  }

  clickThirdPartyManagementTab() {
    this.thirdPartyManagementTab.click()
    return this
  }

  // Assertions
  shouldBeVisible() {
    this.dashboardContainer.should('be.visible')
    return this
  }

  shouldShowAccessDenied() {
    this.accessDeniedMessage.should('be.visible')
    return this
  }

  shouldHaveUrl() {
    cy.url().should('include', '/dashboard')
    return this
  }
}

export default new DashboardPage()
