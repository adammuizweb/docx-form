(function () {
  'use strict';
  var form = document.querySelector('[data-dxf-form]');
  if (!form) return;
  var timer = 0;
  var request = null;
  var sequence = 0;
  var status = document.querySelector('[data-preview-status]');
  var shadow = document.querySelector('[data-shadow-preview]');
  var rendered = document.querySelector('[data-rendered-preview]');
  var previewUrl = form.getAttribute('data-preview-url') || '';
  function message(name) {
    return form.getAttribute(name) || '';
  }
  function showShadow(error) {
    if (shadow) shadow.hidden = false;
    if (rendered) {
      rendered.hidden = true;
      rendered.setAttribute('aria-busy', 'false');
    }
    if (status && error) status.textContent = message('data-preview-error');
  }
  function update(announce) {
    var fields = form.querySelectorAll('[data-preview-field]');
    fields.forEach(function (field, index) {
      var controls = field.querySelectorAll('input:not([type="hidden"]), select, textarea');
      var value = '';
      controls.forEach(function (control) {
        if ((control.type === 'radio' || control.type === 'checkbox') && !control.checked) return;
        if (control.type === 'checkbox') value = control.checked ? 'Yes' : 'No';
        else if (control.tagName === 'SELECT' && control.selectedIndex >= 0) value = control.value === '' ? '' : control.options[control.selectedIndex].text;
        else value = control.value;
      });
      var output = document.querySelector('[data-preview-output="' + index + '"]');
      if (output) output.textContent = value || message('data-preview-empty');
    });
    if (status && announce && previewUrl === '') status.textContent = message('data-preview-updated');
  }
  function positionPageArtwork(root) {
    root.querySelectorAll('.docx-wrapper section.docx').forEach(function (page) {
      var pageWidth = parseFloat(page.style.width);
      var pageHeight = parseFloat(page.style.minHeight);
      if (!pageWidth || !pageHeight) return;
      page.querySelectorAll('img').forEach(function (image) {
        var anchor = image.parentElement;
        var imageWidth = parseFloat(image.style.width);
        var imageHeight = parseFloat(image.style.height);
        if (!anchor || anchor.tagName !== 'DIV' || parseFloat(anchor.style.width) !== 0 || parseFloat(anchor.style.height) !== 0
          || !imageWidth || !imageHeight || imageWidth < pageWidth * 0.9 || imageHeight < pageHeight * 0.9) return;
        var article = page.querySelector(':scope > article');
        var crop = image.style.clipPath.match(/^rect\(\s*([\d.]+)%/);
        var scale = image.style.transform.match(/scale\(\s*[\d.]+\s*,\s*([\d.]+)\s*\)/);
        anchor.classList.add('dxf-docx-page-artwork');
        anchor.style.position = 'absolute';
        anchor.style.left = '0';
        anchor.style.top = '0';
        anchor.style.zIndex = '0';
        anchor.style.pointerEvents = 'none';
        image.style.position = 'relative';
        if (crop && scale && image.style.height.endsWith('pt')) {
          image.style.transformOrigin = 'top left';
          image.style.top = ((parseFloat(image.style.top) || 0) - imageHeight * parseFloat(crop[1]) / 100 * parseFloat(scale[1])) + 'pt';
        }
        page.insertBefore(anchor, page.firstChild);
        page.style.overflow = 'hidden';
        if (article) {
          article.style.position = 'relative';
          article.style.zIndex = '1';
        }
      });
    });
  }
  function fitRenderedPages() {
    if (!rendered || rendered.hidden) return;
    var wrapper = rendered.querySelector('.docx-wrapper');
    if (!wrapper) return;
    wrapper.style.zoom = '1';
    var availableWidth = Math.max(0, rendered.clientWidth - 16);
    var documentWidth = wrapper.scrollWidth;
    if (!availableWidth || !documentWidth) return;
    wrapper.style.zoom = String(Math.min(1, availableWidth / documentWidth));
  }
  function renderDocument() {
    if (!previewUrl || !rendered || form.querySelector('fieldset[disabled]')) return;
    if (!window.fetch || !window.FormData || !window.AbortController || !window.docx || typeof window.docx.renderAsync !== 'function') {
      showShadow(true);
      return;
    }
    if (request) request.abort();
    request = new AbortController();
    var current = ++sequence;
    var body = new FormData(form);
    body.delete('idempotency');
    rendered.setAttribute('aria-busy', 'true');
    if (status) status.textContent = message('data-preview-loading');
    window.fetch(previewUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' },
      signal: request.signal
    }).then(function (response) {
      var type = response.headers.get('Content-Type') || '';
      if (!response.ok || type.indexOf('application/vnd.openxmlformats-officedocument.wordprocessingml.document') !== 0) throw new Error('Preview response rejected');
      return response.arrayBuffer();
    }).then(function (buffer) {
      var next = document.createElement('div');
      next.className = 'dxf-rendered-root';
      return window.docx.renderAsync(buffer, next, next, {
        breakPages: true,
        ignoreLastRenderedPageBreak: false,
        renderHeaders: true,
        renderFooters: true,
        renderFootnotes: true,
        renderEndnotes: true,
        renderComments: false,
        renderAltChunks: false,
        useBase64URL: true
      }).then(function () {
        positionPageArtwork(next);
        return next;
      });
    }).then(function (next) {
      if (current !== sequence) return;
      while (rendered.firstChild) rendered.removeChild(rendered.firstChild);
      while (next.firstChild) rendered.appendChild(next.firstChild);
      rendered.hidden = false;
      rendered.setAttribute('aria-busy', 'false');
      fitRenderedPages();
      if (shadow) shadow.hidden = true;
      if (status) status.textContent = message('data-preview-rendered');
    }).catch(function (error) {
      if (error && error.name === 'AbortError') return;
      if (current === sequence) showShadow(true);
    });
  }
  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(renderDocument, 700);
  }
  form.addEventListener('input', function () {
    update(true);
    schedule();
  });
  form.addEventListener('change', function () {
    update(true);
    schedule();
  });
  form.addEventListener('submit', function () { if (request) request.abort(); });
  window.addEventListener('resize', fitRenderedPages);
  update(false);
  renderDocument();
}());
