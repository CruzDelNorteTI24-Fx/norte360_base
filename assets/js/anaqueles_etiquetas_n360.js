(function () {
  'use strict';

  const state = {
    url: '',
    code: '',
    name: '',
    file: 'QR_ANAQUEL.png'
  };

  const config = window.N360_ANA_QR || {};
  const modal = document.getElementById('anaQrModal');
  const canvas = document.getElementById('anaQrCanvas');
  const title = document.getElementById('anaQrTitle');
  const text = document.getElementById('anaQrText');
  const downloadButton = document.querySelector('[data-ana-qr-download]');

  function safeName(value) {
    const textValue = String(value || '').trim();
    return (textValue || 'ANAQUEL')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-zA-Z0-9_-]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'ANAQUEL';
  }

  function clearCanvas(targetCanvas, fill = '#ffffff') {
    const ctx = targetCanvas.getContext('2d');
    ctx.fillStyle = fill;
    ctx.fillRect(0, 0, targetCanvas.width, targetCanvas.height);
  }

  function drawCanvasMessage(targetCanvas, headline, detail) {
    clearCanvas(targetCanvas, '#f8fbff');
    const ctx = targetCanvas.getContext('2d');
    ctx.textAlign = 'center';
    ctx.fillStyle = '#b91c1c';
    ctx.font = '900 15px Segoe UI, Arial, sans-serif';
    ctx.fillText(headline, targetCanvas.width / 2, 116);
    ctx.fillStyle = '#52667d';
    ctx.font = '700 12px Segoe UI, Arial, sans-serif';
    drawCenteredText(ctx, detail, targetCanvas.width / 2, 142, targetCanvas.width - 34, 16, { maxLines: 3 });
  }

  function supportsToCanvas() {
    return typeof window.QRCode !== 'undefined'
      && window.QRCode
      && typeof window.QRCode.toCanvas === 'function';
  }

  function supportsConstructor() {
    return typeof window.QRCode === 'function';
  }

  function cloneCanvas(sourceCanvas, size) {
    const target = document.createElement('canvas');
    target.width = size;
    target.height = size;
    const ctx = target.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.drawImage(sourceCanvas, 0, 0, size, size);
    return target;
  }

  function imageToCanvas(img, size) {
    const target = document.createElement('canvas');
    target.width = size;
    target.height = size;
    const ctx = target.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.drawImage(img, 0, 0, size, size);
    return target;
  }

  function waitImage(img) {
    return new Promise((resolve) => {
      if (!img) {
        resolve(null);
        return;
      }

      if (img.complete && img.naturalWidth > 0) {
        resolve(img);
        return;
      }

      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
    });
  }

  async function generateQrCanvas(value, size) {
    const qrText = String(value || '').trim();
    if (!qrText) {
      throw new Error('URL vacia para QR.');
    }

    if (supportsToCanvas()) {
      const qrCanvas = document.createElement('canvas');
      await window.QRCode.toCanvas(qrCanvas, qrText, {
        width: size,
        margin: 2,
        errorCorrectionLevel: 'M',
        color: {
          dark: '#071b31',
          light: '#ffffff'
        }
      });
      return qrCanvas;
    }

    if (supportsConstructor()) {
      const holder = document.createElement('div');
      holder.style.position = 'fixed';
      holder.style.left = '-99999px';
      holder.style.top = '0';
      holder.style.width = `${size}px`;
      holder.style.height = `${size}px`;
      document.body.appendChild(holder);

      try {
        const level = window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : undefined;
        new window.QRCode(holder, {
          text: qrText,
          width: size,
          height: size,
          colorDark: '#071b31',
          colorLight: '#ffffff',
          correctLevel: level
        });

        await new Promise((resolve) => window.setTimeout(resolve, 60));

        const generatedCanvas = holder.querySelector('canvas');
        if (generatedCanvas) {
          return cloneCanvas(generatedCanvas, size);
        }

        const generatedImage = await waitImage(holder.querySelector('img'));
        if (generatedImage) {
          return imageToCanvas(generatedImage, size);
        }
      } finally {
        holder.remove();
      }
    }

    throw new Error('No se cargo una libreria QR compatible.');
  }

  function openModal(button) {
    if (!modal || !canvas) return;

    state.url = button.dataset.qrUrl || '';
    state.code = button.dataset.anaCode || '';
    state.name = button.dataset.anaName || '';
    state.file = button.dataset.anaFile || `QR_ANAQUEL_${safeName(state.code)}.png`;

    if (title) {
      title.textContent = `${state.code} - ${state.name || 'Anaquel'}`;
    }

    if (text) {
      text.textContent = 'Escanear este QR abre el contenido del anaquel con validacion de sesion y permiso de Almacen.';
    }

    modal.hidden = false;
    renderPreview();
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
  }

  async function renderPreview() {
    if (!canvas || !state.url) return;
    clearCanvas(canvas);

    try {
      const qrCanvas = await generateQrCanvas(state.url, 260);
      const ctx = canvas.getContext('2d');
      ctx.drawImage(qrCanvas, 0, 0, canvas.width, canvas.height);
      if (text) {
        text.textContent = 'Escanear este QR abre el contenido del anaquel con validacion de sesion y permiso de Almacen.';
      }
    } catch (error) {
      drawCanvasMessage(canvas, 'No se pudo generar QR', 'Recarga la pagina con Ctrl + F5. Si continua, revisa que el CDN de QR cargue correctamente.');
      if (text) {
        text.textContent = 'No se pudo cargar el generador de QR en el navegador.';
      }
    }
  }

  function loadImage(src) {
    return new Promise((resolve) => {
      if (!src) {
        resolve(null);
        return;
      }

      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
      img.src = src;
    });
  }

  function drawCenteredText(ctx, textValue, x, y, width, lineHeight, options = {}) {
    const words = String(textValue || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';

    words.forEach((word) => {
      const candidate = line ? `${line} ${word}` : word;
      if (ctx.measureText(candidate).width > width && line) {
        lines.push(line);
        line = word;
      } else {
        line = candidate;
      }
    });

    if (line) lines.push(line);

    const maxLines = options.maxLines || lines.length;
    lines.slice(0, maxLines).forEach((lineText, index) => {
      const finalText = index === maxLines - 1 && lines.length > maxLines
        ? `${lineText.replace(/\.*$/, '')}...`
        : lineText;
      ctx.fillText(finalText, x, y + (index * lineHeight));
    });

    return Math.min(lines.length, maxLines) * lineHeight;
  }

  async function downloadQr() {
    if (!state.url) return;

    let qrCanvas;
    try {
      qrCanvas = await generateQrCanvas(state.url, 420);
    } catch (error) {
      renderPreview();
      return;
    }

    const output = document.createElement('canvas');
    output.width = 760;
    output.height = 940;
    const ctx = output.getContext('2d');

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, output.width, output.height);
    ctx.strokeStyle = '#cddbea';
    ctx.lineWidth = 3;
    ctx.strokeRect(24, 24, output.width - 48, output.height - 48);

    const logo = await loadImage(config.logo) || await loadImage(config.fallbackLogo);
    if (logo) {
      const ratio = Math.min(420 / logo.width, 96 / logo.height);
      const logoWidth = logo.width * ratio;
      const logoHeight = logo.height * ratio;
      ctx.drawImage(logo, (output.width - logoWidth) / 2, 54, logoWidth, logoHeight);
    }

    ctx.textAlign = 'center';
    ctx.fillStyle = '#071b31';
    ctx.font = '900 28px Segoe UI, Arial, sans-serif';
    ctx.fillText(config.brand || 'NORTE 360', output.width / 2, 180);

    ctx.font = '900 22px Segoe UI, Arial, sans-serif';
    ctx.fillText('QR DE ANAQUEL', output.width / 2, 220);

    ctx.fillStyle = '#123047';
    ctx.fillRect(130, 246, output.width - 260, 58);
    ctx.fillStyle = '#ffffff';
    ctx.font = '900 24px Segoe UI, Arial, sans-serif';
    ctx.fillText(state.code || 'ANAQUEL', output.width / 2, 283);

    ctx.fillStyle = '#071b31';
    ctx.font = '800 22px Segoe UI, Arial, sans-serif';
    drawCenteredText(ctx, state.name || 'Anaquel', output.width / 2, 340, 600, 28, { maxLines: 2 });

    ctx.drawImage(qrCanvas, (output.width - 420) / 2, 410, 420, 420);

    ctx.fillStyle = '#52667d';
    ctx.font = '700 18px Segoe UI, Arial, sans-serif';
    ctx.fillText(config.footer || 'Acceso protegido', output.width / 2, 870);

    ctx.font = '600 13px Consolas, monospace';
    drawCenteredText(ctx, state.url, output.width / 2, 898, 640, 16, { maxLines: 2 });

    const link = document.createElement('a');
    link.href = output.toDataURL('image/png');
    link.download = state.file || `QR_ANAQUEL_${safeName(state.code)}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  document.addEventListener('click', (event) => {
    const qrButton = event.target.closest('[data-ana-qr]');
    if (qrButton) {
      openModal(qrButton);
      return;
    }

    if (event.target.closest('[data-ana-qr-close]')) {
      closeModal();
    }
  });

  if (downloadButton) {
    downloadButton.addEventListener('click', downloadQr);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();
