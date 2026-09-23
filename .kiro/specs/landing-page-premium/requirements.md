# Requirements Document

## Introduction

This document specifies the requirements for a premium landing page for SEMAR (Sistem Informasi Manajemen Risiko) application. The landing page serves as the public-facing entry point that showcases the application's features and benefits before users log in. It must maintain the glassmorphism design aesthetic established in the existing login page while being modern, responsive, and secure.

The landing page will be accessible to unauthenticated users and must integrate seamlessly with the existing security infrastructure including CSRF protection, XSS prevention, and secure session management.

## Glossary

- **Landing_Page**: The public-facing entry page visible to unauthenticated users, designed to showcase application features and encourage user engagement
- **Hero_Section**: The prominent top section of the landing page containing the main value proposition and call-to-action
- **Glassmorphism**: A UI design style featuring frosted-glass effects with backdrop blur, transparency, and subtle borders
- **CSRF_Token**: Cross-Site Request Forgery token used to prevent unauthorized form submissions
- **Content_Security_Policy**: HTTP security header that prevents XSS and data injection attacks
- **Rate_Limiter**: A mechanism to limit the number of requests from a single IP address within a time window
- **Session**: Server-side storage for user authentication state and temporary data
- **Responsive_Design**: Design approach that adapts layout and content for different screen sizes (desktop, tablet, mobile)

## Requirements

### Requirement 1: Hero Section Display

**User Story:** As a visitor, I want to see an impactful hero section immediately upon arriving at the landing page, so that I understand what SEMAR offers and feel compelled to explore further.

#### Acceptance Criteria

1. WHEN a visitor accesses the landing page, THE Landing_Page SHALL display a hero section containing the application name "SEMAR", tagline "Sistem Informasi Manajemen Risiko", and institution name "Balai Besar Laboratorium Kesehatan Lingkungan"
2. THE Hero_Section SHALL display a primary call-to-action button labeled "Masuk ke Sistem" that links to the login page
3. THE Hero_Section SHALL display a secondary call-to-action button labeled "Pelajari Lebih Lanjut" that scrolls to the features section
4. THE Hero_Section SHALL apply glassmorphism styling with backdrop-filter blur, semi-transparent background, and subtle white borders consistent with the login page design
5. WHILE displaying on mobile devices with viewport width less than 768 pixels, THE Hero_Section SHALL stack elements vertically and maintain minimum font size of 14 pixels for readability

### Requirement 2: Features Section Display

**User Story:** As a visitor, I want to see a comprehensive features section, so that I understand the capabilities of the SEMAR application.

#### Acceptance Criteria

1. THE Landing_Page SHALL display a features section titled "Fitur Utama" listing at least 6 key features of the application
2. WHEN displaying features, THE Landing_Page SHALL show the following features with icons and descriptions:
   - Dashboard interaktif dengan statistik dan visualisasi data
   - Manajemen risiko dengan kalkulasi skor otomatis
   - Aksi mitigasi dengan upload bukti dan tanda tangan digital
   - Saran mitigasi otomatis berbasis AI
   - Laporan dengan export Excel dan PDF
   - Manajemen pengguna berbasis role
3. THE Landing_Page SHALL display each feature in a card component with glassmorphism styling
4. WHILE hovering over a feature card, THE card SHALL apply a subtle transform effect and shadow enhancement
5. WHEN displayed on tablet devices, THE features section SHALL display cards in a 2-column grid layout

### Requirement 3: Statistics Section Display

**User Story:** As a visitor, I want to see real-time statistics about the system usage, so that I can gauge the activity and scale of the application.

#### Acceptance Criteria

1. THE Landing_Page SHALL display a statistics section showing key metrics of the system
2. WHEN the page loads, THE Landing_Page SHALL fetch and display the following statistics from the database:
   - Total risiko teridentifikasi
   - Total aksi mitigasi
   - Total pengguna aktif
   - Total unit kerja terdaftar
3. THE statistics section SHALL use animated counters that increment from zero to the actual value when the section becomes visible in the viewport
4. THE statistics section SHALL apply a contrasting background to distinguish it from adjacent sections
5. WHILE displaying on mobile devices, THE statistics section SHALL display metrics in a 2-column grid

### Requirement 4: Benefits Section Display

**User Story:** As a visitor, I want to understand the benefits of using SEMAR, so that I can evaluate whether it meets my organization's needs.

#### Acceptance Criteria

1. THE Landing_Page SHALL display a benefits section titled "Mengapa Memilih SEMAR"
2. THE benefits section SHALL display at least 4 key benefits with supporting icons and descriptions
3. WHEN displaying benefits, THE Landing_Page SHALL include the following benefits:
   - Proses identifikasi risiko terstruktur dan terdokumentasi
   - Monitoring real-time status penanganan risiko
   - Laporan otomatis untuk audit dan pelaporan
   - Keamanan data dengan enkripsi dan kontrol akses
4. THE benefits section SHALL use alternating left-right layout for desktop displays
5. WHILE displaying on mobile devices, THE benefits section SHALL stack all content vertically

### Requirement 5: Testimonials Section Display

**User Story:** As a visitor, I want to see testimonials from other users, so that I can build trust in the application's effectiveness.

#### Acceptance Criteria

1. THE Landing_Page SHALL display a testimonials section titled "Testimoni Pengguna"
2. THE testimonials section SHALL display at least 3 testimonial cards from users
3. WHEN testimonials are displayed, THE Landing_Page SHALL show the user's name, role, organization unit, and testimonial text
4. THE testimonials section SHALL include a carousel or slider for navigating between testimonials on mobile devices
5. IF there are more than 3 testimonials, THE section SHALL provide navigation controls to browse additional testimonials

### Requirement 6: Contact and Call-to-Action Section

**User Story:** As a visitor, I want to see contact information and a final call-to-action, so that I know how to reach support or get started.

#### Acceptance Criteria

1. THE Landing_Page SHALL display a footer section with contact information
2. THE footer section SHALL include the institution name "Balai Besar Laboratorium Kesehatan Lingkungan", address, and contact details
3. THE footer section SHALL include quick links to relevant pages
4. THE footer section SHALL include a copyright notice with the current year
5. THE Landing_Page SHALL display a final call-to-action section before the footer with a prominent "Mulai Sekarang" button

### Requirement 7: Responsive Design

**User Story:** As a visitor using any device, I want the landing page to display optimally on my screen, so that I can access information regardless of my device type.

#### Acceptance Criteria

1. WHILE displaying on desktop devices with viewport width greater than 1024 pixels, THE Landing_Page SHALL display content in a full-width layout with maximum content width of 1400 pixels
2. WHILE displaying on tablet devices with viewport width between 768 and 1024 pixels, THE Landing_Page SHALL adjust grid layouts to 2 columns where applicable
3. WHILE displaying on mobile devices with viewport width less than 768 pixels, THE Landing_Page SHALL display all sections in a single-column layout
4. WHEN the viewport width changes, THE Landing_Page SHALL smoothly transition layout changes without content overflow or horizontal scrolling
5. THE Landing_Page SHALL use CSS media queries to apply responsive styles without JavaScript dependency

### Requirement 8: Navigation and Routing

**User Story:** As a visitor, I want clear navigation options, so that I can easily move between the landing page and login page.

#### Acceptance Criteria

1. THE Landing_Page SHALL display a fixed navigation bar at the top of the page
2. THE navigation bar SHALL contain the application logo, name, and a "Masuk" button linking to the login page
3. WHEN a user scrolls down the page, THE navigation bar SHALL remain visible and maintain a glassmorphism background
4. WHEN the "Masuk" button is clicked, THE system SHALL redirect to the login page
5. THE Landing_Page SHALL be accessible via the root URL without requiring authentication

### Requirement 9: Authentication Integration

**User Story:** As an administrator, I want the landing page to integrate with the existing authentication system, so that logged-in users bypass the landing page and go directly to the dashboard.

#### Acceptance Criteria

1. WHEN a user who is already logged in accesses the root URL, THE system SHALL redirect the user to the dashboard page
2. WHEN a user who is not logged in accesses the root URL, THE system SHALL display the Landing_Page
3. THE Landing_Page SHALL NOT load session data for unauthenticated users
4. WHEN the session expires while viewing the Landing_Page, THE system SHALL NOT display error messages to the visitor
5. THE router in index.php SHALL be modified to support the landing page route before authentication check

### Requirement 10: CSRF Protection for Contact Form

**User Story:** As a security-conscious administrator, I want any forms on the landing page to have CSRF protection, so that the application remains secure against cross-site request forgery attacks.

#### Acceptance Criteria

1. IF a contact form is present on the Landing_Page, THE form SHALL include a CSRF token hidden field
2. WHEN the contact form is submitted, THE system SHALL validate the CSRF token using the verifyCsrf() function
3. IF the CSRF token is invalid or missing, THE system SHALL reject the form submission and log the attempt
4. THE CSRF token SHALL be generated using the existing csrfToken() function from functions.php
5. THE CSRF token field SHALL be generated using the existing csrfField() function

### Requirement 11: XSS Prevention

**User Story:** As a security-conscious administrator, I want all user-visible content to be escaped, so that the application remains secure against cross-site scripting attacks.

#### Acceptance Criteria

1. WHEN displaying dynamic content from the database, THE Landing_Page SHALL escape all output using the xss() function
2. WHEN displaying user-generated content in testimonials, THE system SHALL sanitize and escape all text content
3. WHEN including URLs in links, THE system SHALL validate URLs using the safeInternalUrl() function for internal links
4. THE Landing_Page SHALL NOT use innerHTML or similar DOM manipulation methods with untrusted content
5. WHEN outputting data in JavaScript contexts, THE system SHALL use jsEncode() function for proper escaping

### Requirement 12: Content Security Policy Headers

**User Story:** As a security-conscious administrator, I want appropriate Content Security Policy headers, so that the landing page is protected against injection attacks.

#### Acceptance Criteria

1. WHEN the Landing_Page is served, THE system SHALL include Content-Security-Policy HTTP header with appropriate directives
2. THE Content-Security-Policy header SHALL allow scripts only from trusted sources including CDN domains for fonts and libraries
3. THE Content-Security-Policy header SHALL allow styles only from trusted sources including inline styles for glassmorphism effects
4. THE Content-Security-Policy header SHALL allow images from the application domain and data URIs for icons
5. THE Content-Security-Policy header SHALL include frame-ancestors directive set to 'self' to prevent clickjacking

### Requirement 13: Rate Limiting for Form Submissions

**User Story:** As a security-conscious administrator, I want rate limiting on form submissions, so that the application is protected against abuse and denial of service attacks.

#### Acceptance Criteria

1. WHEN a contact form submission is received, THE system SHALL check the submission rate for the source IP address
2. IF more than 5 submissions are received from the same IP address within 60 seconds, THE system SHALL reject subsequent submissions with HTTP 429 Too Many Requests status
3. WHEN a submission is rate-limited, THE system SHALL log the IP address and timestamp for security monitoring
4. THE rate limiting mechanism SHALL use session or file-based storage for tracking submission counts
5. THE rate limiter SHALL NOT affect authenticated users submitting forms within the application

### Requirement 14: Performance Optimization

**User Story:** As a visitor, I want the landing page to load quickly, so that I have a good user experience without waiting for slow-loading content.

#### Acceptance Criteria

1. WHEN the Landing_Page loads, THE initial render shall complete within 3 seconds on a standard broadband connection
2. THE Landing_Page SHALL load CSS and JavaScript files in a non-blocking manner
3. THE Landing_Page SHALL use lazy loading for images below the fold
4. THE Landing_Page SHALL include appropriate cache headers for static assets
5. THE Landing_Page SHALL minify and combine CSS and JavaScript files for production

### Requirement 15: Accessibility Compliance

**User Story:** As a visitor with disabilities, I want the landing page to be accessible, so that I can navigate and understand the content using assistive technologies.

#### Acceptance Criteria

1. THE Landing_Page SHALL include proper heading hierarchy (h1, h2, h3) for all sections
2. THE Landing_Page SHALL include alt text for all images and icons
3. THE Landing_Page SHALL ensure sufficient color contrast ratio of at least 4.5:1 for normal text
4. WHEN navigating via keyboard, THE Landing_Page SHALL provide visible focus indicators for all interactive elements
5. THE Landing_Page SHALL use semantic HTML elements (nav, main, section, article, footer) for content structure

### Requirement 16: SEO Optimization

**User Story:** As a marketing team member, I want the landing page to be optimized for search engines, so that the application can be discovered by potential users.

#### Acceptance Criteria

1. THE Landing_Page SHALL include a descriptive title tag containing "SEMAR - Sistem Informasi Manajemen Risiko"
2. THE Landing_Page SHALL include a meta description tag summarizing the application's purpose and features
3. THE Landing_Page SHALL include Open Graph meta tags for social media sharing
4. THE Landing_Page SHALL include structured data markup using JSON-LD format for organization information
5. THE Landing_Page SHALL use clean, semantic URLs without query parameters for the main landing page route

### Requirement 17: Analytics Integration

**User Story:** As a marketing team member, I want to track visitor interactions on the landing page, so that I can measure engagement and optimize the user experience.

#### Acceptance Criteria

1. THE Landing_Page SHALL provide a configuration point for analytics tracking code (e.g., Google Analytics)
2. WHEN analytics is configured, THE Landing_Page SHALL track page views and button clicks
3. THE analytics implementation SHALL respect user privacy preferences and cookie consent
4. THE Landing_Page SHALL not load analytics scripts if not configured in the environment
5. THE analytics configuration SHALL be loaded from environment variables for security

### Requirement 18: Error Handling

**User Story:** As a visitor, I want to see user-friendly error messages, so that I understand when something goes wrong and know what to do next.

#### Acceptance Criteria

1. IF the database connection fails while loading statistics, THE Landing_Page SHALL display placeholder text instead of crashing
2. IF a form submission fails validation, THE Landing_Page SHALL display a clear error message explaining the issue
3. IF an unexpected error occurs, THE Landing_Page SHALL display a generic error message without exposing system details
4. WHEN an error occurs, THE system SHALL log the error details for administrator review
5. THE error handling SHALL use the existing error handling pattern from config.php with environment-aware detail display
