# Implementation Plan: Landing Page Premium

## Overview

Implementasi landing page premium untuk SEMAR dengan desain glassmorphism yang konsisten dengan halaman login yang sudah ada. Landing page akan menjadi entry point publik untuk pengguna yang belum login, menampilkan fitur-fitur aplikasi, statistik sistem, dan testimoni pengguna.

## Tasks

- [ ] 1. Set up database schema for landing page
  - [~] 1.1 Create migration file for landing_testimonials table
    - Create `lib/migrations/YYYYMMDD_HHMMSS_create_landing_testimonials.sql`
    - Include columns: id, user_name, user_role, unit_kerja, testimonial_text, is_active, display_order, created_at, updated_at
    - Add index on (is_active, display_order) for query optimization
    - _Requirements: 5.1, 5.2, 5.3_

  - [~] 1.2 Create migration file for landing_statistics_cache table
    - Create `lib/migrations/YYYYMMDD_HHMMSS_create_landing_statistics_cache.sql`
    - Include columns: stat_key, stat_value, updated_at
    - Seed initial data for total_risiko, total_mitigasi, total_pengguna, total_unit_kerja
    - _Requirements: 3.1, 3.2_

  - [~] 1.3 Create migration file for contact_submissions table (optional)
    - Create `lib/migrations/YYYYMMDD_HHMMSS_create_contact_submissions.sql`
    - Include columns: id, name, email, subject, message, ip_address, user_agent, is_read, created_at
    - Add indexes on created_at and ip_address for rate limiting queries
    - _Requirements: 6.1, 13.1, 13.2_

  - [ ]* 1.4 Write unit tests for database migrations
    - Test table creation and column definitions
    - Test seed data insertion
    - _Requirements: 3.1, 5.1_

- [ ] 2. Implement rate limiting functions in functions.php
  - [~] 2.1 Add checkRateLimit function
    - Implement IP-based rate limiting with file storage
    - Support configurable max requests and time window
    - Use HMAC-hashed keys for security
    - Return boolean indicating if request is within limit
    - _Requirements: 13.1, 13.2, 13.4_

  - [~] 2.2 Add logRateLimitViolation function
    - Log rate limit violations with IP and context
    - Use error_log for audit trail
    - _Requirements: 13.3_

  - [~] 2.3 Add getIPAddress function enhancement for proxy support
    - Support Cloudflare CF_CONNECTING_IP header
    - Support X-Forwarded-For for trusted proxies
    - Validate IP format before returning
    - _Requirements: 13.1_

  - [ ]* 2.4 Write unit tests for rate limiting functions
    - Test rate limit threshold enforcement
    - Test rate limit window expiration
    - Test IP address extraction with various headers
    - _Requirements: 13.1, 13.2_

- [ ] 3. Implement landing page service functions
  - [~] 3.1 Add getLandingStatistics function in functions.php
    - Query aggregated counts from risiko, mitigasi, users tables
    - Implement 5-minute cache using landing_statistics_cache table
    - Return array with total_risiko, total_mitigasi, total_pengguna, total_unit_kerja
    - _Requirements: 3.1, 3.2_

  - [~] 3.2 Add getLandingStatisticsSafe wrapper function
    - Wrap getLandingStatistics with try-catch
    - Return default values on database error
    - Log errors for administrator review
    - _Requirements: 18.1, 18.4, 18.5_

  - [~] 3.3 Add getLandingTestimonials function in functions.php
    - Query active testimonials from landing_testimonials table
    - Support configurable limit parameter
    - Order by display_order and created_at
    - _Requirements: 5.1, 5.2, 5.3_

  - [~] 3.4 Add updateLandingStatsCache helper function
    - Update cache table with fresh statistics
    - Use UPSERT pattern for atomic updates
    - _Requirements: 3.1_

  - [~] 3.5 Add getLandingStatsCache helper function
    - Retrieve cached statistics if still valid
    - Return null if cache expired or missing
    - _Requirements: 3.1_

  - [ ]* 3.6 Write unit tests for landing service functions
    - Test getLandingStatistics with mock database
    - Test cache hit and miss scenarios
    - Test getLandingTestimonials ordering
    - Test graceful degradation on database errors
    - _Requirements: 3.1, 3.2, 18.1_

- [ ] 4. Modify router in index.php for landing page route
  - [~] 4.1 Add landing route before authentication check
    - Add landing page route handling before isLoggedIn check
    - Set default page to 'landing' instead of 'dashboard'
    - Include modules/landing.php for landing route
    - _Requirements: 8.5, 9.2_

  - [~] 4.2 Add authenticated user redirect logic
    - Redirect logged-in users accessing landing to dashboard
    - Preserve existing authentication flow for other pages
    - _Requirements: 9.1_

  - [~] 4.3 Handle POST requests for contact form
    - Add POST handler for landing page contact form
    - Integrate CSRF validation and rate limiting
    - _Requirements: 10.1, 10.2, 13.1_

  - [ ]* 4.4 Write integration tests for router changes
    - Test unauthenticated access to landing page
    - Test authenticated user redirect to dashboard
    - Test landing page loads without session
    - _Requirements: 9.1, 9.2, 9.3_

- [ ] 5. Create landing page CSS styles (landing.css)
  - [~] 5.1 Define CSS custom properties (design tokens)
    - Define --landing-bg-gradient, --landing-glass-bg, --landing-glass-border
    - Define --landing-title-size with clamp() for responsive typography
    - Define --landing-section-padding, --landing-container-max
    - Define --landing-transition for consistent animations
    - _Requirements: 1.4, 7.4_

  - [~] 5.2 Implement navigation bar styles
    - Fixed positioning with glassmorphism background
    - Backdrop-filter blur(20px) for frosted glass effect
    - Scroll state transition (darker background on scroll)
    - Responsive height adjustment for mobile (64px) and desktop (72px)
    - _Requirements: 1.4, 8.1, 8.3_

  - [~] 5.3 Implement hero section styles
    - Full viewport height with gradient background
    - Animated orb elements for visual interest
    - Glassmorphism card for content
    - Responsive text sizing with clamp()
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [~] 5.4 Implement features section styles
    - 3-column grid on desktop, 2 on tablet, 1 on mobile
    - Glassmorphism card styles with hover effects
    - Transform and shadow transitions
    - _Requirements: 2.1, 2.3, 2.4, 2.5_

  - [~] 5.5 Implement statistics section styles
    - Contrasting darker background
    - 4-column grid on desktop, 2 on mobile
    - Animated counter number styles
    - _Requirements: 3.3, 3.4, 3.5_

  - [~] 5.6 Implement benefits section styles
    - Alternating left-right layout for desktop
    - Vertical stacking for mobile
    - Icon and text alignment
    - _Requirements: 4.1, 4.2, 4.4, 4.5_

  - [~] 5.7 Implement testimonials section styles
    - Card-based carousel layout
    - Navigation controls for mobile
    - User info and testimonial text styling
    - _Requirements: 5.1, 5.4, 5.5_

  - [~] 5.8 Implement footer and CTA section styles
    - Final call-to-action with prominent button
    - Footer with contact info and quick links
    - Copyright notice styling
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [~] 5.9 Implement responsive media queries
    - Mobile-first approach with min-width breakpoints
    - Tablet: 768px breakpoint
    - Desktop: 1025px breakpoint
    - Large desktop: 1400px breakpoint
    - Smooth transitions without content overflow
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [ ]* 5.10 Write CSS validation tests
    - Validate CSS syntax with stylelint
    - Test responsive breakpoints with visual regression
    - _Requirements: 7.1, 7.2, 7.3_

- [ ] 6. Create landing page JavaScript (landing.js)
  - [~] 6.1 Implement navigation scroll effect
    - Add scroll event listener for nav background change
    - Toggle 'scrolled' class based on scroll position
    - Use passive event listener for performance
    - _Requirements: 8.3_

  - [~] 6.2 Implement smooth scroll for anchor links
    - Prevent default anchor click behavior
    - Smooth scroll to target section
    - Account for fixed nav height offset
    - _Requirements: 1.3_

  - [~] 6.3 Implement animated counter for statistics
    - Use Intersection Observer API for visibility detection
    - Animate numbers from 0 to target value
    - Use requestAnimationFrame for smooth animation
    - _Requirements: 3.3_

  - [~] 6.4 Implement testimonial carousel/slider
    - Navigation controls for browsing testimonials
    - Touch swipe support for mobile
    - Auto-advance with pause on hover
    - _Requirements: 5.4, 5.5_

  - [~] 6.5 Implement analytics event tracking
    - Track CTA button clicks with data attributes
    - Use gtag() for Google Analytics integration
    - Respect user privacy preferences
    - _Requirements: 17.2, 17.3_

  - [ ]* 6.6 Write unit tests for JavaScript interactions
    - Test scroll effect behavior
    - Test animated counter with mock intersection observer
    - Test carousel navigation
    - _Requirements: 3.3, 8.3_

- [ ] 7. Create landing page module (landing.php)
  - [~] 7.1 Create HTML document structure with SEO meta tags
    - Include title tag with "SEMAR - Sistem Informasi Manajemen Risiko"
    - Include meta description and keywords
    - Include Open Graph and Twitter Card meta tags
    - Include JSON-LD structured data for WebApplication
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5_

  - [~] 7.2 Implement navigation bar component
    - Fixed top navigation with logo and "Masuk" button
    - Glassmorphism background with backdrop-filter
    - Link to login page with proper URL encoding
    - _Requirements: 8.1, 8.2, 8.4_

  - [~] 7.3 Implement hero section component
    - Display application name, tagline, and institution
    - Primary CTA button linking to login
    - Secondary CTA button for smooth scroll to features
    - Animated orb background elements
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_

  - [~] 7.4 Implement features section component
    - Display 6 feature cards with icons and descriptions
    - Use glassmorphism card styling
    - Implement responsive grid layout
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

  - [~] 7.5 Implement statistics section component
    - Call getLandingStatisticsSafe() to fetch data
    - Display animated counters for each metric
    - Handle graceful degradation with placeholder text
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 18.1_

  - [~] 7.6 Implement benefits section component
    - Display 4 benefits with icons and descriptions
    - Alternating left-right layout for desktop
    - Vertical stacking for mobile
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

  - [~] 7.7 Implement testimonials section component
    - Call getLandingTestimonials() to fetch data
    - Display testimonial cards with user info
    - Implement carousel for mobile navigation
    - Escape all output with xss() function
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 11.1, 11.2_

  - [~] 7.8 Implement footer and CTA section component
    - Display institution name, address, contact details
    - Display quick links and copyright notice
    - Display "Mulai Sekarang" CTA button
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [~] 7.9 Implement contact form with CSRF protection
    - Include CSRF token field using csrfField()
    - Validate CSRF on submission using verifyCsrf()
    - Apply rate limiting using checkRateLimit()
    - Validate and sanitize form input
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 13.1, 13.2_

  - [~] 7.10 Implement form validation and error handling
    - Validate name (2-100 characters), email format, message (10-2000 characters)
    - Display user-friendly error messages
    - Log errors for administrator review
    - _Requirements: 18.2, 18.3, 18.4, 18.5_

  - [~] 7.11 Integrate analytics tracking code
    - Conditionally output Google Analytics script if configured
    - Add data-track attributes to CTA buttons
    - _Requirements: 17.1, 17.2, 17.4, 17.5_

  - [ ]* 7.12 Write integration tests for landing page module
    - Test page renders without errors
    - Test all sections display correctly
    - Test contact form submission flow
    - Test CSRF validation on form submit
    - _Requirements: 10.1, 10.2, 18.2_

- [ ] 8. Update CSP headers for landing page resources
  - [~] 8.1 Configure Content Security Policy in .htaccess
    - Allow scripts from 'self', cdn.jsdelivr.net, cdnjs.cloudflare.com
    - Allow styles from 'self', fonts.googleapis.com, cdnjs.cloudflare.com
    - Allow fonts from 'self', fonts.gstatic.com, cdnjs.cloudflare.com
    - Allow images from 'self', data:, https:
    - Set frame-ancestors 'self' to prevent clickjacking
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5_

  - [~] 8.2 Add cache headers for static assets
    - Set 1-week expiry for CSS and JS files
    - Set 1-month expiry for images
    - Enable ExpiresActive in .htaccess
    - _Requirements: 14.4_

  - [ ]* 8.3 Write security integration tests
    - Test CSP headers are present
    - Test XSS prevention with malicious input
    - Test CSRF token validation
    - _Requirements: 10.1, 11.1, 12.1_

- [~] 9. Checkpoint - Verify core implementation
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 10. Implement accessibility features
  - [~] 10.1 Add semantic HTML structure
    - Use nav, main, section, article, footer elements
    - Ensure proper heading hierarchy (h1 > h2 > h3)
    - Add skip-to-content link for keyboard users
    - _Requirements: 15.1, 15.5_

  - [~] 10.2 Add alt text and ARIA labels
    - Add alt attributes to all images
    - Add aria-label to icon-only buttons
    - Add aria-live regions for dynamic content
    - _Requirements: 15.2_

  - [~] 10.3 Implement keyboard navigation
    - Ensure all interactive elements are focusable
    - Add visible focus indicators
    - Implement logical tab order
    - _Requirements: 15.4_

  - [~] 10.4 Verify color contrast compliance
    - Ensure 4.5:1 contrast ratio for normal text
    - Ensure 3:1 contrast ratio for large text
    - Test with accessibility tools (WAVE, axe)
    - _Requirements: 15.3_

  - [ ]* 10.5 Write accessibility validation tests
    - Run automated accessibility audit with axe-core
    - Test keyboard navigation flow
    - Test screen reader compatibility
    - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5_

- [ ] 11. Implement performance optimizations
  - [~] 11.1 Optimize asset loading
    - Preload critical CSS for above-the-fold content
    - Defer non-critical JavaScript loading
    - Use async loading for non-blocking scripts
    - _Requirements: 14.2_

  - [~] 11.2 Implement lazy loading for images
    - Add loading="lazy" to below-the-fold images
    - Use WebP format with JPEG fallback
    - Implement responsive images with srcset
    - _Requirements: 14.3_

  - [~] 11.3 Minify and optimize CSS/JS
    - Remove unused CSS rules
    - Minify CSS and JavaScript for production
    - Enable GZIP compression in .htaccess
    - _Requirements: 14.5_

  - [ ]* 11.4 Write performance validation tests
    - Run Lighthouse audit for performance metrics
    - Verify First Contentful Paint < 1.5s
    - Verify Largest Contentful Paint < 2.5s
    - Verify total page size < 500KB
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5_

- [ ] 12. Create migration script and documentation
  - [~] 12.1 Create migration runner script
    - Add migration files to lib/migrations/ directory
    - Document migration execution steps
    - _Requirements: 3.1, 5.1_

  - [~] 12.2 Update README with landing page documentation
    - Add landing page feature description
    - Document configuration options (analytics, etc.)
    - Document new database tables
    - _Requirements: 16.1, 17.1_

  - [~] 12.3 Create deployment checklist
    - List all new files to deploy
    - List files to modify
    - Document post-deployment verification steps
    - _Requirements: 14.4, 14.5_

- [~] 13. Final checkpoint - Complete implementation verification
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Rate limiting uses file-based storage to avoid database dependency for unauthenticated users
- All dynamic output must be escaped using xss() function
- CSRF protection is required for contact form submissions
- Performance targets: FCP < 1.5s, LCP < 2.5s, Total page size < 500KB
- Accessibility target: WCAG 2.1 AA compliance

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "2.1", "2.2", "2.3"] },
    { "id": 1, "tasks": ["1.3", "1.4", "2.4", "3.1", "3.2", "3.3", "3.4", "3.5"] },
    { "id": 2, "tasks": ["3.6", "4.1", "4.2", "5.1"] },
    { "id": 3, "tasks": ["4.3", "4.4", "5.2", "5.3", "5.4", "5.5", "5.6", "5.7", "5.8", "5.9"] },
    { "id": 4, "tasks": ["5.10", "6.1", "6.2", "6.3", "6.4", "6.5"] },
    { "id": 5, "tasks": ["6.6", "7.1", "7.2", "7.3", "7.4", "7.5"] },
    { "id": 6, "tasks": ["7.6", "7.7", "7.8", "7.9", "7.10", "7.11", "7.12"] },
    { "id": 7, "tasks": ["8.1", "8.2", "8.3", "10.1", "10.2", "10.3", "10.4"] },
    { "id": 8, "tasks": ["10.5", "11.1", "11.2", "11.3"] },
    { "id": 9, "tasks": ["11.4", "12.1", "12.2", "12.3"] }
  ]
}
```
