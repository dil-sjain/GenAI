// Page Object Model for Case Management Page
class CaseManagementPage {
  // Selectors
  get caseList() { return cy.get('[data-cy="case-list"]') }
  get caseItems() { return cy.get('[data-cy="case-item"]') }
  get addCaseButton() { return cy.get('[data-cy="add-case-btn"]') }
  get editCaseButton() { return cy.get('[data-cy="edit-case-btn"]') }
  get acceptCaseButton() { return cy.get('[data-cy="accept-case-btn"]') }
  get rejectCaseButton() { return cy.get('[data-cy="reject-case-btn"]') }
  get caseTypeDropdown() { return cy.get('[data-cy="case-type-dropdown"]') }
  get associateThirdPartyField() { return cy.get('[data-cy="associate-third-party"]') }

  // Actions
  visit() {
    cy.visit('/case-management')
    return this
  }

  clickAddCase() {
    this.addCaseButton.click()
    return this
  }

  clickEditCase() {
    this.editCaseButton.click()
    return this
  }

  clickAcceptCase() {
    this.acceptCaseButton.click()
    return this
  }

  clickRejectCase() {
    this.rejectCaseButton.click()
    return this
  }

  selectFirstCase() {
    this.caseItems.first().click()
    return this
  }

  selectCaseNotInDraftOrRejected() {
    this.caseItems
      .not('[data-status="Draft"]')
      .not('[data-status="Rejected By Investigator"]')
      .first()
      .click()
    return this
  }

  // Assertions
  shouldShowCaseList() {
    this.caseList.should('be.visible')
    return this
  }

  shouldNotShowEditButton() {
    this.editCaseButton.should('not.exist')
    return this
  }

  shouldNotShowAcceptRejectButtons() {
    this.acceptCaseButton.should('not.exist')
    this.rejectCaseButton.should('not.exist')
    return this
  }

  shouldHaveDisabledCaseTypeDropdown() {
    this.caseTypeDropdown.should('be.disabled')
    return this
  }

  shouldHaveCaseTypeSetToRecommended() {
    this.caseTypeDropdown.should('have.value', 'recommended')
    return this
  }

  shouldNotShowAssociateThirdPartyField() {
    this.associateThirdPartyField.should('not.exist')
    return this
  }
}

export default new CaseManagementPage()
