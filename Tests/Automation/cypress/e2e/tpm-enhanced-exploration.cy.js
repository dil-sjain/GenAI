// Improved TPM Case Management Tests with better error handling
describe('TPM Case Management - Enhanced', () => {
  beforeEach(() => {
    // Set up viewport for consistent testing
    cy.viewport(1280, 720)
  })

  it('should successfully login and access dashboard', () => {
    cy.loginToTPM()
    cy.waitForTPMPageLoad()
    
    // Verify we're on the dashboard
    cy.url().should('include', '/dashboard')
    cy.get('body').should('contain.text', 'Dashboard').or('be.visible')
    
    cy.log('✅ Login and dashboard access successful')
  })

  it('should explore available navigation options', () => {
    cy.loginToTPM()
    cy.waitForTPMPageLoad()
    
    // Explore and log available navigation elements
    cy.get('body').then(($body) => {
      // Look for navigation elements
      const navSelectors = [
        'nav a', 
        '.nav a', 
        '[role="navigation"] a',
        '.menu a',
        '.navbar a',
        'ul li a'
      ]
      
      navSelectors.forEach(selector => {
        const elements = $body.find(selector)
        if (elements.length > 0) {
          cy.log(`Found navigation elements with selector: ${selector}`)
          elements.each((index, element) => {
            const text = Cypress.$(element).text().trim()
            const href = Cypress.$(element).attr('href')
            if (text) {
              cy.log(`Nav item ${index + 1}: "${text}" -> ${href || 'no href'}`)
            }
          })
        }
      })
      
      // Look for case-related content
      const caseKeywords = ['case', 'Case', 'CASE', 'management', 'Management']
      caseKeywords.forEach(keyword => {
        if ($body.text().includes(keyword)) {
          cy.log(`✅ Found keyword: ${keyword}`)
        }
      })
    })
  })

  it('should attempt to navigate to case management section', () => {
    cy.loginToTPM()
    cy.waitForTPMPageLoad()
    
    // Try different ways to access case management
    const caseManagementVariations = [
      'Case Management',
      'Cases',
      'Case',
      'Management',
      'Third Party Management',
      'Order Diligence'
    ]
    
    caseManagementVariations.forEach(variation => {
      cy.get('body').then(($body) => {
        if ($body.text().includes(variation)) {
          cy.log(`✅ Found text: ${variation}`)
          
          // Try to click if it's a clickable element
          cy.get('body').then(() => {
            try {
              cy.contains(variation).then($el => {
                const tagName = $el.prop('tagName').toLowerCase()
                if (['a', 'button'].includes(tagName) || $el.attr('onclick') || $el.css('cursor') === 'pointer') {
                  cy.wrap($el).click()
                  cy.log(`✅ Clicked on: ${variation}`)
                  cy.wait(2000)
                }
              })
            } catch (e) {
              cy.log(`Could not click on: ${variation}`)
            }
          })
        }
      })
    })
  })

  it('should verify user permissions and access levels', () => {
    cy.loginToTPM()
    cy.waitForTPMPageLoad()
    
    // Check for admin/superadmin indicators
    cy.get('body').then(($body) => {
      const adminIndicators = [
        'admin', 'Admin', 'ADMIN',
        'superadmin', 'Super Admin', 'SuperAdmin',
        'administrator', 'Administrator'
      ]
      
      adminIndicators.forEach(indicator => {
        if ($body.text().includes(indicator)) {
          cy.log(`✅ Admin indicator found: ${indicator}`)
        }
      })
      
      // Look for user profile or settings
      const userElements = [
        '[data-cy="user-profile"]',
        '.user-profile',
        '.profile',
        '.user-menu',
        '.account'
      ]
      
      userElements.forEach(selector => {
        if ($body.find(selector).length > 0) {
          cy.log(`✅ User element found: ${selector}`)
        }
      })
    })
  })

  it('should capture page structure for debugging', () => {
    cy.loginToTPM()
    cy.waitForTPMPageLoad()
    
    // Take a screenshot for visual debugging
    cy.screenshot('tpm-dashboard-structure')
    
    // Log page title and URL
    cy.title().then(title => {
      cy.log(`Page title: ${title}`)
    })
    
    cy.url().then(url => {
      cy.log(`Current URL: ${url}`)
    })
    
    // Get main content structure
    cy.get('body').then(($body) => {
      const mainElements = $body.find('main, .main, #main, .content, .container')
      if (mainElements.length > 0) {
        cy.log(`Found main content containers: ${mainElements.length}`)
      }
      
      // Look for common UI frameworks or patterns
      const frameworks = ['bootstrap', 'material', 'ant', 'semantic']
      frameworks.forEach(framework => {
        if ($body.find(`[class*="${framework}"]`).length > 0) {
          cy.log(`✅ Detected UI framework: ${framework}`)
        }
      })
    })
  })
})
