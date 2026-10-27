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
  const bindSuggestions = (input, list, sede) => {
    if (!input || !list || !sede) return () => {};
    const previous = Array.from(list.options).map((option) => ({ value: option.value, sede: option.dataset.sede }));
    const refresh = () => {
      const seen = new Set();
      const options = [];
      previous.forEach((item) => {
        if (item.sede !== String(sede.value) || seen.has(item.value)) return;
        seen.add(item.value);
        const option = document.createElement('option');
        option.value = item.value;
        options.push(option);
      });
      list.replaceChildren(...options);
    };
    sede.addEventListener('change', refresh);
    sede.form?.addEventListener('reset', () => queueMicrotask(refresh));
    refresh();
    return refresh;
  };
  window.N360AssetLocation = Object.freeze({ text, detail, location, observation, bindSuggestions, length: (value) => Array.from(value).length });
})();
