(function () {
  'use strict';
  var form = document.querySelector('[data-dxf-form]');
  if (!form) return;
  var timer = 0;
  var status = document.querySelector('[data-preview-status]');
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
      if (output) output.textContent = value || form.getAttribute('data-preview-empty') || '';
    });
    if (status && announce) status.textContent = form.getAttribute('data-preview-updated') || '';
  }
  form.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () { update(true); }, 150);
  });
  form.addEventListener('change', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () { update(true); }, 150);
  });
  update(false);
}());
