(function () {
  'use strict';
  const text = (value) => String(value ?? '').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g, '').trim();
  const detail = (value) => text(value).replace(/\s+/g, ' ');
  const location = (sede, value) => {
    const extra = detail(value);
    return text(sede) + (text(sede) && extra ? ' — ' : '') + extra;
  };
  const observation = (value, sede, extra) => {
    const base = text(value);
    return base + (detail(extra) ? (base ? ' | ' : '') + 'Ubicación: ' + location(sede, extra) : '');
  };
  window.N360AssetLocation = Object.freeze({ text, detail, location, observation, length: (value) => Array.from(value).length });
})();
