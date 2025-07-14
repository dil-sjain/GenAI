# Cypress Test Framework

This is a comprehensive Cypress testing framework set up for the TPM (Third Party Management) application.

## 📁 Project Structure

```
cypress/
├── e2e/                     # End-to-end test files
│   ├── example.cy.js        # Basic example tests
│   ├── tpm-access-control.cy.js   # TPM access control tests
│   └── tpm-pom-tests.cy.js       # Tests using Page Object Model
├── fixtures/                # Test data files
│   ├── testData.json        # Main test data
│   └── users.json          # User data
├── support/                 # Support files and utilities
│   ├── pageObjects/         # Page Object Model files
│   │   ├── LoginPage.js
│   │   ├── DashboardPage.js
│   │   └── CaseManagementPage.js
│   ├── commands.js          # Custom Cypress commands
│   ├── e2e.js              # E2E test configuration
│   ├── component.js        # Component test configuration
│   └── utils.js            # Utility functions
├── downloads/               # Downloaded files during tests
├── screenshots/             # Screenshots on test failures
└── videos/                  # Test execution videos
```

## 🚀 Getting Started

### Prerequisites
- Node.js (version 14 or higher)
- npm or yarn

### Installation
The framework is already set up with all dependencies installed.

### Configuration
Update the `cypress.config.js` file with your application's settings:
- `baseUrl`: Your application's URL
- `env` variables: API URLs, test user credentials

## 📝 Test Scripts

```bash
# Open Cypress Test Runner (Interactive mode)
npm run cy:open

# Run tests in headless mode
npm run cy:run

# Run tests in specific browser
npm run cy:run:chrome
npm run cy:run:firefox
npm run cy:run:edge

# Run specific test file
npm run test:spec cypress/e2e/tpm-access-control.cy.js
```

## 🧪 Test Cases Implemented

Based on the observation file, the following test cases are implemented:

### Access Control Tests
- **TPM-T4101**: Vendor user denied access to add new case
- **TPM-T4102**: Read-only user denied access to edit case
- **TPM-T4105**: Vendor user denied access to accept/reject case

### Feature Flag Tests  
- **TPM-T4103**: assoc3p flag controls Third Party Profile association
- **TPM-T4104**: caseTypeSelection flag controls Case Type dropdown

## 🏗️ Framework Features

### Custom Commands
- `cy.login(username, password)` - Login functionality
- `cy.loginAsVendor()` - Login as vendor user
- `cy.loginAsAdmin()` - Login as admin user
- `cy.loginAsReadOnly()` - Login as read-only user
- `cy.getByDataCy(selector)` - Get element by data-cy attribute
- `cy.checkAccessDenied()` - Check for access denied messages
- `cy.clearAndType(text)` - Clear and type text
- `cy.shouldBeVisibleAndEnabled()` - Check visibility and enabled state

### Page Object Model
Organized page objects for better maintainability:
- `LoginPage.js` - Login page interactions
- `DashboardPage.js` - Dashboard page interactions  
- `CaseManagementPage.js` - Case management page interactions

### Utility Functions
- Test data generation helpers
- Wait utilities
- Assertion helpers
- Form helpers
- API helpers
- File upload/download helpers

### Test Data Management
- Centralized test data in `fixtures/` folder
- Environment-specific configuration
- User roles and permissions data

## 📊 Reporting

### Built-in Features
- Automatic screenshots on test failures
- Video recordings of test runs
- Detailed error logs and stack traces
- Test execution reports

### Additional Reporting (Optional)
You can add additional reporting tools:
- Mochawesome for HTML reports
- Allure for comprehensive reporting
- Dashboard integration for CI/CD

## 🔧 Best Practices Implemented

### Test Organization
- Descriptive test names following the pattern: `TPM-T#### description`
- Grouped related tests using `describe` blocks
- Proper use of `beforeEach` and `afterEach` hooks

### Selector Strategy
- Preferring `data-cy` attributes for reliable element selection
- Avoiding brittle CSS selectors
- Using semantic selectors where possible

### Test Data
- Externalized test data in fixtures
- Environment variables for sensitive data
- Data-driven testing capabilities

### Error Handling
- Graceful handling of application errors
- Retry logic for flaky tests
- Proper cleanup after test failures

## 🌐 Environment Configuration

Update `cypress.config.js` with your environment settings:

```javascript
env: {
  apiUrl: 'https://your-api-url.com',
  vendorUsername: 'vendor_user',
  vendorPassword: 'vendor_password',
  adminUsername: 'admin_user', 
  adminPassword: 'admin_password',
  readOnlyUsername: 'readonly_user',
  readOnlyPassword: 'readonly_password'
}
```

## 🐛 Debugging

### Debug Mode
Run tests with debug information:
```bash
DEBUG=cypress:* npm run cy:run
```

### Browser DevTools
Use `cy.debug()` or `cy.pause()` in tests to pause execution and inspect the application state.

### Logs
Check console logs and network requests in the Cypress Test Runner for debugging test failures.

## 📈 Continuous Integration

The framework is ready for CI/CD integration. Example configurations:

### GitHub Actions
```yaml
- name: Run Cypress tests
  run: npm run cy:run
```

### Jenkins
```bash
npm install
npm run cy:run
```

## 🔄 Maintenance

### Regular Updates
- Keep Cypress updated to the latest version
- Update page objects when UI changes
- Review and update test data regularly
- Add new test cases as features are developed

### Code Quality
- Follow consistent naming conventions
- Add comments for complex test logic
- Regular code reviews
- Maintain DRY (Don't Repeat Yourself) principles
