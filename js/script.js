document.addEventListener('DOMContentLoaded', function () {

  /* ---- Mobile nav toggle ---- */
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.querySelector('.main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* ---- Active nav link ---- */
  var current = (window.location.pathname.split('/').pop() || 'index.html');
  document.querySelectorAll('.main-nav a[href]').forEach(function (link) {
    var href = link.getAttribute('href');
    if (href === current || (current === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });

  /* ---- Scroll reveal ---- */
  var revealEls = document.querySelectorAll('[data-reveal]');
  if ('IntersectionObserver' in window && revealEls.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('in'); });
  }

  /* ---- Back to top ---- */
  var backToTop = document.querySelector('.back-to-top');
  if (backToTop) {
    window.addEventListener('scroll', function () {
      backToTop.classList.toggle('show', window.scrollY > 600);
    }, { passive: true });
    backToTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  /* ---- Header shadow on scroll ---- */
  var header = document.querySelector('.site-header');
  if (header) {
    var onScroll = function () {
      header.style.boxShadow = window.scrollY > 8 ? '0 8px 24px rgba(0,0,0,0.35)' : 'none';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---- Pre-select service on contact form via ?service= query param ---- */
  var serviceSelect = document.getElementById('service');
  if (serviceSelect) {
    var params = new URLSearchParams(window.location.search);
    var wanted = params.get('service');
    if (wanted) {
      var opt = Array.from(serviceSelect.options).find(function (o) {
        return o.value.toLowerCase() === wanted.toLowerCase();
      });
      if (opt) serviceSelect.value = opt.value;
    }
  }

  /* ---- Quote form: submit to /api/send-quote ---- */
  var form = document.getElementById('quote-form');
  if (form) {
    var success = document.getElementById('form-success');
    var errorEl = document.getElementById('form-error');
    var submitBtn = form.querySelector('button[type="submit"]');
    var submitLabelEl = submitBtn ? submitBtn.querySelector('.btn-label') : null;
    var submitLabel = submitLabelEl ? submitLabelEl.textContent : '';

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      if (errorEl) {
        errorEl.textContent = '';
        errorEl.classList.remove('show');
      }
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('is-loading');
        if (submitLabelEl) submitLabelEl.textContent = 'Sending…';
      }

      var payload = {
        name: form.name.value.trim(),
        phone: form.phone.value.trim(),
        email: form.email.value.trim(),
        service: form.service.value,
        message: form.message.value.trim(),
        consent: form.consent.checked,
      };

      fetch('send-quote.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
        .then(function (res) {
          return res.json().catch(function () { return {}; }).then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (result) {
          if (!result.ok || !result.data || result.data.ok !== true) {
            var msg = (result.data && result.data.error) || 'Something went wrong. Please try again or call us directly.';
            throw new Error(msg);
          }
          form.style.display = 'none';
          if (success) success.classList.add('show');
        })
        .catch(function (err) {
          if (errorEl) {
            errorEl.textContent = err.message || 'Something went wrong. Please try again or call us directly.';
            errorEl.classList.add('show');
          }
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('is-loading');
            if (submitLabelEl) submitLabelEl.textContent = submitLabel;
          }
        });
    });
  }

  /* ---- Footer year ---- */
  var yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();

});
