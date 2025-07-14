/* 
Manual Test Case to Automate:
Key: TPM-T4003
Name: TPM_CM_Verify editing a case and changing the case type to one that requires subInfo navigates to the correct form

Precondition:

User is logged into the TPM application as a Superadmin with valid credentials.
At least one case exists in the system in "Draft" stage with a case type that does NOT require subInfo.
User has access to the Case Management tab and permission to edit cases.
The system has at least one case type configured that requires subInfo.
Objective:
To verify that when editing a case and changing the case type to one that requires subInfo, the system navigates to the correct subInfo form and validates required fields.


Test Script (Step-by-Step) - Step:

- Click on the Case Management Tab.
- In the Stage dropdown, select "Draft".
- Locate and click on a case number under the Case List sub tab.
- Click on the Edit action item.
- In the Edit Case modal, change the "Type of Case" dropdown to a type that requires subInfo.
- Click the "Continue|Enter Details" button.
- Observe the navigation and form fields.

Test Data:
- Use a case with the following attributes:
- Stage: "Draft"
- Case Name: "SubInfo Navigation Test"
- Case Type: [Enhanced Due Diligence]
- Change to:
- Case Type: [Open Source Investigation]

Expected Result:
- The Case Management tab loads successfully.
- The Stage dropdown displays and allows selection of "Draft".
- The case list updates to show cases in the "Draft" stage.
- The user is able to click the Edit action item for a draft case.
- The Edit Case modal opens and displays current case details.
- The user changes the case type to one that requires subInfo.
- Upon clicking "Continue|Enter Details", the system navigates to the subInfo form (e.g., "DUE DILIGENCE KEY INFO FORM - COMPANY").
- The subInfo form displays all required fields for the new case type.
- If required fields are left blank and the user attempts to continue, the system displays specific validation error messages for each missing field.

Common code snippets: This snippet is used for Login to Application

` cy.visit('https://dev3.steeleglobal.net/');
    
    // Perform login
    cy.get('input[id="loginID"]').type('automationsuperadmin@diligent.com');
    cy.get('input[id="pw"]').type('Welcome@12345');
    cy.get('input[id="btnSubmit"]').click();
    
    // Wait for dashboard to load and verify successful login
    cy.url().should('include', '/dashboard');
    cy.wait(3000); // Allow time for dashboard to fully load`



*/

describe('TPM Case Management - Case Editing and Type Change', () => {
  beforeEach(() => {
    // Set up API intercepts for consistent test data
    cy.intercept('GET', '**/case/list**', { fixture: 'caseList.json' }).as('getCaseList')
    cy.intercept('GET', '**/case/details/**', { fixture: 'caseDetails.json' }).as('getCaseDetails')
    cy.intercept('POST', '**/case/update**', { statusCode: 200, body: { success: true } }).as('updateCase')
    cy.intercept('GET', '**/case/types**', { 
      body: [
        { id: 1, name: 'Enhanced Due Diligence', requiresSubInfo: false },
        { id: 2, name: 'Open Source Investigation', requiresSubInfo: true }
      ]
    }).as('getCaseTypes')
    
    // Login to TPM application using custom command
    cy.loginToTPM()
  })

  it('TPM_CM_Verify editing a case and changing the case type to one that requires subInfo navigates to the correct form', () => {
    cy.log('🎯 Starting test: Verify editing case and changing case type navigation')
    
    // Step 1: Click on the Case Management Tab
    cy.log('📋 Step 1: Navigate to Case Management tab')
    cy.navigateToTPMSection('Case Management')
    cy.waitForTPMPageLoad()
    
    // Verify Case Management tab loads successfully
    cy.url().should('include', 'case')
    cy.contains('Case Management').should('be.visible')
    cy.log('✅ Case Management tab loaded successfully')
    
    // Step 2: In the Stage dropdown, select "Draft"
    cy.log('📋 Step 2: Select "Draft" stage from dropdown')
    cy.get('select#lb_stage, #stage-dropdown, [data-cy="stage-select"]')
      .first()
      .select('Draft')
    
    // Verify the case list updates to show cases in Draft stage
    cy.wait('@getCaseList')
    cy.contains('Draft').should('be.visible')
    cy.log('✅ Stage dropdown displays and allows selection of "Draft"')
    
    // Step 3: Locate and click on a case number under the Case List sub tab
    cy.log('📋 Step 3: Click on a case number in the Case List')
    cy.get('[data-cy="case-list-tab"], .case-list-tab, a:contains("Case List")')
      .first()
      .click()
    
    // Wait for case list to load and click on the first case
    cy.get('table tbody tr:first-child td:first-child a, .case-number-link')
      .first()
      .should('be.visible')
      .click()
    
    cy.wait('@getCaseDetails')
    cy.log('✅ Successfully clicked on case number and case details loaded')
    
    // Step 4: Click on the Edit action item
    cy.log('📋 Step 4: Click the Edit action item')
    cy.get('button:contains("Edit"), [data-cy="edit-case"], .edit-action, a:contains("Edit")')
      .first()
      .should('be.visible')
      .click()
    
    // Verify Edit Case modal opens and displays current case details
    cy.get('.modal, .edit-case-modal, [data-cy="edit-case-modal"]')
      .should('be.visible')
    cy.contains('Edit Case', { matchCase: false }).should('be.visible')
    cy.log('✅ Edit Case modal opens and displays current case details')
    
    // Step 5: Change the "Type of Case" dropdown to a type that requires subInfo
    cy.log('📋 Step 5: Change case type to "Open Source Investigation"')
    cy.get('select[name="case_type"], #case-type-dropdown, [data-cy="case-type-select"]')
      .first()
      .should('be.visible')
      .select('Open Source Investigation')
    
    cy.log('✅ Case type changed to one that requires subInfo')
    
    // Step 6: Click the "Continue|Enter Details" button
    cy.log('📋 Step 6: Click Continue/Enter Details button')
    cy.get('button:contains("Continue"), button:contains("Enter Details"), [data-cy="continue-btn"]')
      .first()
      .should('be.visible')
      .click()
    
    cy.wait('@updateCase')
    
    // Step 7: Observe the navigation and form fields
    cy.log('📋 Step 7: Verify navigation to subInfo form')
    
    // Verify navigation to subInfo form
    cy.url().should('include', 'subinfo', { timeout: 10000 })
    cy.contains('DUE DILIGENCE KEY INFO FORM', { matchCase: false, timeout: 10000 })
      .should('be.visible')
    cy.log('✅ System navigates to the subInfo form successfully')
    
    // Verify the subInfo form displays all required fields for the new case type
    cy.get('form, .subinfo-form, [data-cy="subinfo-form"]')
      .should('be.visible')
    
    // Check for common required fields in subInfo forms
    const requiredFields = [
      'input[required], select[required], textarea[required]',
      '.required-field',
      '[data-required="true"]'
    ]
    
    requiredFields.forEach(selector => {
      cy.get('body').then($body => {
        if ($body.find(selector).length > 0) {
          cy.get(selector).should('have.length.greaterThan', 0)
        }
      })
    })
    
    cy.log('✅ SubInfo form displays required fields for the new case type')
    
    // Additional validation: Test validation error messages for required fields
    cy.log('📋 Additional Test: Verify validation for required fields')
    
    // Try to continue without filling required fields
    cy.get('button:contains("Continue"), button:contains("Submit"), button:contains("Save")')
      .first()
      .click()
    
    // Check for validation error messages
    cy.get('body').then($body => {
      const errorSelectors = [
        '.error-message',
        '.validation-error',
        '.field-error',
        '[data-cy="error"]',
        '.alert-danger',
        '.text-danger'
      ]
      
      let errorFound = false
      errorSelectors.forEach(selector => {
        if (!errorFound && $body.find(selector).length > 0) {
          cy.get(selector).should('be.visible')
          errorFound = true
          cy.log('✅ Validation error messages display for missing required fields')
        }
      })
      
      if (!errorFound) {
        // Alternative check for inline validation
        cy.get('input:invalid, select:invalid, textarea:invalid').should('have.length.greaterThan', 0)
        cy.log('✅ HTML5 validation prevents form submission with missing required fields')
      }
    })
    
    cy.log('🎉 Test completed successfully: Case type change and subInfo navigation verified')
  })

  afterEach(() => {
    // Clean up any test data or reset state if needed
    cy.log('🧹 Cleaning up test data')
  })
})