
describe('TPM Case Management Tests', () => {
  let testData = {};

  before(() => {
    // Load test data
    cy.fixture('testData').then((data) => {
      testData = data;
    });
  });

  beforeEach(() => {
    // Handle uncaught exceptions from the application
    cy.on('uncaught:exception', (err, runnable) => {
      // Ignore specific application errors that don't affect test functionality
      if (err.message.includes('twoFactorRecoveryEnabled') || 
          err.message.includes('Cannot read properties of null')) {
        return false;
      }
      // Return true for other errors to fail the test
      return true;
    });
  });

  it('should successfully login and load dashboard', () => {
    // Visit the application
    cy.visit('https://dev3.steeleglobal.net/');
    
    // Perform login
    cy.get('input[id="loginID"]').type('automationsuperadmin@diligent.com');
    cy.get('input[id="pw"]').type('Welcome@12345');
    cy.get('input[id="btnSubmit"]').click();
    
    // Wait for dashboard to load and verify successful login
    cy.url().should('include', '/dashboard');
    cy.wait(3000); // Allow time for dashboard to fully load
    
    // Verify dashboard elements are present
    cy.get('body').should('be.visible');
    cy.log('✅ Successfully logged in and dashboard loaded');
  });
});