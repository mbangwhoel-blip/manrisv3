/**
 * SEMAR Landing Page - JavaScript Interactions
 */

(function() {
    'use strict';
    
    // ─────────────────────────────────────────────────────────────
    // NAVIGATION
    // ─────────────────────────────────────────────────────────────
    
    const nav = document.getElementById('landingNav');
    const navToggle = document.getElementById('navToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    
    // Scroll effect for navigation
    let lastScrollY = window.scrollY;
    
    function handleNavScroll() {
        const currentScrollY = window.scrollY;
        
        if (currentScrollY > 50) {
            nav.classList.add('scrolled');
        } else {
            nav.classList.remove('scrolled');
        }
        
        lastScrollY = currentScrollY;
    }
    
    window.addEventListener('scroll', handleNavScroll, { passive: true });
    
    // Mobile menu toggle
    if (navToggle && mobileMenu) {
        navToggle.addEventListener('click', () => {
            mobileMenu.classList.toggle('active');
            const icon = navToggle.querySelector('i');
            if (mobileMenu.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
        
        // Close mobile menu on link click
        mobileMenu.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                mobileMenu.classList.remove('active');
                navToggle.querySelector('i').classList.remove('fa-times');
                navToggle.querySelector('i').classList.add('fa-bars');
            });
        });
    }
    
    // ─────────────────────────────────────────────────────────────
    // SMOOTH SCROLL
    // ─────────────────────────────────────────────────────────────
    
    document.querySelectorAll('a[href^=\"#\"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const target = document.querySelector(targetId);
            if (target) {
                const navHeight = nav ? nav.offsetHeight : 72;
                const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - navHeight;
                
                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // ─────────────────────────────────────────────────────────────
    // ANIMATED COUNTERS
    // ─────────────────────────────────────────────────────────────
    
    const statValues = document.querySelectorAll('.stat-value');
    
    function animateCounter(element, target, duration = 2000) {
        const startTime = performance.now();
        const startValue = 0;
        
        function updateCounter(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function for smooth animation
            const easeOutQuart = 1 - Math.pow(1 - progress, 4);
            
            const currentValue = Math.floor(startValue + (target - startValue) * easeOutQuart);
            element.textContent = currentValue.toLocaleString('id-ID');
            
            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                element.textContent = target.toLocaleString('id-ID');
            }
        }
        
        requestAnimationFrame(updateCounter);
    }
    
    // Intersection Observer for triggering animation when visible
    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                statValues.forEach(stat => {
                    const target = parseInt(stat.dataset.target, 10) || 0;
                    animateCounter(stat, target);
                });
                statsObserver.disconnect();
            }
        });
    }, { threshold: 0.3 });
    
    const statsSection = document.getElementById('statistics');
    if (statsSection) {
        statsObserver.observe(statsSection);
    }
    
    // ─────────────────────────────────────────────────────────────
    
    const carousel = document.getElementById('testimonialsCarousel');
    
    if (carousel) {
        const cards = carousel.querySelectorAll('.testimonial-card');
        const dots = carousel.querySelectorAll('.carousel-dot');
        const prevBtn = carousel.querySelector('.carousel-prev');
        const nextBtn = carousel.querySelector('.carousel-next');
        
        let currentIndex = 0;
        let autoPlayInterval;
        
        function showTestimonial(index) {
            cards.forEach((card, i) => {
                card.classList.remove('active');
                if (i === index) {
                    card.classList.add('active');
                }
            });
            
            dots.forEach((dot, i) => {
                dot.classList.remove('active');
                if (i === index) {
                    dot.classList.add('active');
                }
            });
            
            currentIndex = index;
        }
        
        function nextTestimonial() {
            const next = (currentIndex + 1) % cards.length;
            showTestimonial(next);
        }
        
        function prevTestimonial() {
            const prev = (currentIndex - 1 + cards.length) % cards.length;
            showTestimonial(prev);
        }
        
        function startAutoPlay() {
            autoPlayInterval = setInterval(nextTestimonial, 5000);
        }
        
        function stopAutoPlay() {
            clearInterval(autoPlayInterval);
        }
        
        // Button navigation
        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                stopAutoPlay();
                prevTestimonial();
                startAutoPlay();
            });
        }
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                stopAutoPlay();
                nextTestimonial();
                startAutoPlay();
            });
        }
        
        // Dot navigation
        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                stopAutoPlay();
                showTestimonial(index);
                startAutoPlay();
            });
        });
        
        // Start autoplay
        if (cards.length > 1) {
            startAutoPlay();
            
            // Pause on hover
            carousel.addEventListener('mouseenter', stopAutoPlay);
            carousel.addEventListener('mouseleave', startAutoPlay);
        }
    }
    
    // ─────────────────────────────────────────────────────────────
    // TOAST NOTIFICATION
    // ─────────────────────────────────────────────────────────────
    
    const toast = document.getElementById('flashToast');
    
    if (toast) {
        // Auto hide after 5 seconds
        setTimeout(() => {
            toast.style.animation = 'slideIn 0.3s ease reverse';
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 5000);
    }
    
    // ─────────────────────────────────────────────────────────────
    // ANALYTICS TRACKING (Optional)
    // ─────────────────────────────────────────────────────────────
    
    document.querySelectorAll('[data-track]').forEach(element => {
        element.addEventListener('click', function() {
            const trackId = this.dataset.track;
            
            // Google Analytics tracking (if configured)
            if (typeof gtag === 'function') {
                gtag('event', 'click', {
                    'event_category': 'Landing Page',
                    'event_label': trackId
                });
            }
            
            // Console log for debugging
            console.log('Track:', trackId);
        });
    });
    

    // ─────────────────────────────────────────────────────────────
    // REAL-TIME CLOCK & DATE (Top Bar)
    // ─────────────────────────────────────────────────────────────

    const topbarTime = document.getElementById('topbarTime');
    const topbarDate = document.getElementById('topbarDate');

    const hariID = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const bulanID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                     'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    function updateClock() {
        const now = new Date();

        // Jam: HH:MM:SS
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');
        if (topbarTime) topbarTime.textContent = hh + ':' + mm + ':' + ss;

        // Tanggal: Senin, 01 Januari 2025
        const hari   = hariID[now.getDay()];
        const tgl    = String(now.getDate()).padStart(2, '0');
        const bulan  = bulanID[now.getMonth()];
        const tahun  = now.getFullYear();
        if (topbarDate) topbarDate.textContent = hari + ', ' + tgl + ' ' + bulan + ' ' + tahun;
    }

    updateClock();
    setInterval(updateClock, 1000);
})();
