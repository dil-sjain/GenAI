// TPM Tests using Page Object Model
import LoginPage from '../support/pageObjects/LoginPage'
import DashboardPage from '../support/pageObjects/DashboardPage'
import CaseManagementPage from '../support/pageObjects/CaseManagementPage'

describe('TPM Access Control with Page Objects', () => {
  beforeEach(() => {
    // Load test data
    cy.fixture('testData').as('testData')
    cy.fixture('users').as('users')
  })

  describe('Vendor User Access Tests', () => {
    it('TPM-T4101: Vendor user denied access to add case', function() {
      // Login as vendor user
      LoginPage.visit()
        .login(this.testData.vendorUser.username, this.testData.vendorUser.password)
        .shouldRedirectToDashboard()

      // Navigate to Order Diligence and expect access denied
      DashboardPage.shouldBeVisible()
        .clickOrderDiligenceTab()
        .shouldShowAccessDenied()
    })

    it('TPM-T4105: Vendor user denied access to accept/reject case', function() {
      // Login as vendor user
      LoginPage.visit()
        .login(this.testData.vendorUser.username, this.testData.vendorUser.password)

      // Navigate to case management
      DashboardPage.clickCaseManagementTab()
      
      CaseManagementPage.shouldShowCaseList()
        .selectFirstCase()
        .shouldNotShowAcceptRejectButtons()
    })
  })

  describe('Read-Only User Access Tests', () => {
    it('TPM-T4102: Read-only user denied access to edit case', function() {
      // Login as read-only user
      LoginPage.visit()
        .login(this.testData.readOnlyUser.username, this.testData.readOnlyUser.password)

      // Navigate to case management
      DashboardPage.clickCaseManagementTab()
      
      CaseManagementPage.shouldShowCaseList()
        .selectCaseNotInDraftOrRejected()
        .shouldNotShowEditButton()
    })
  })

  describe('Feature Flag Tests', () => {
    it('TPM-T4103: assoc3p flag controls Third Party Profile association', function() {
      // Login as admin
      LoginPage.visit()
        .login(this.testData.adminUser.username, this.testData.adminUser.password)

      DashboardPage.clickOrderDiligenceButton()
      
      CaseManagementPage.shouldNotShowAssociateThirdPartyField()
    })

    it('TPM-T4104: caseTypeSelection flag controls Case Type dropdown', function() {
      // Login as admin
      LoginPage.visit()
        .login(this.testData.adminUser.username, this.testData.adminUser.password)

      DashboardPage.clickThirdPartyManagementTab()
      
      // Navigate to 3P Profile and add case
      cy.getByDataCy('3p-profile').click()
      
      CaseManagementPage.clickAddCase()
        .shouldHaveDisabledCaseTypeDropdown()
        .shouldHaveCaseTypeSetToRecommended()
    })
  })
})
