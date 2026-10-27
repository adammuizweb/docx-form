(function () {
  'use strict';
  var button = document.querySelector('[data-dxf-print]');
  if (button) button.addEventListener('click', function () { window.print(); });
}());
