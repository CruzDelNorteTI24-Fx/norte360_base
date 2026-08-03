(function () {
  'use strict';

  if (window.N360BusLookupImage) return;

  /*
   * RUTAS OPCIONALES DE IMÁGENES
   * ------------------------------------------------------------
   * Puedes dejar estos valores vacíos y se mostrarán espacios
   * reservados dentro de la ficha.
   *
   * Recomendado: usar imágenes del mismo dominio para evitar que
   * el navegador bloquee la descarga del canvas por CORS.
   */
  const DEFAULT_ASSETS = {
    logoUrl: '/ht/img/infologo2.png',
    busUrl: '../img/IMG_3004.png',  // Ejemplo: '/img/buses/bus_referencia.png'
  };

  // Se usan cuando la respuesta del API no envía teléfonos en data.empresa.telefonos.
  const DEFAULT_CONTACTS = [
    '+51 967 747 285',
    '+51 950 260 600',
  ];

  const ROUTE_INFO = {
    paraderos: ['Plaza Norte', 'La Victoria', 'Bre\u00f1a', 'Chimbore', 'Trujillo'],
    precioPrimerNivel: 'S/. 80.00',
    precioSegundoNivel: 'S/. 60.00',
  };

  const COLORS = {
    navy: '#123149',
    navyDeep: '#0b263d',
    navy2: '#1f5d83',
    blue: '#2d75a6',
    blueSoft: '#dcecf7',
    bluePale: '#eef6fb',
    yellow: '#FFF212',
    red: '#ED3237',
    ink: '#10283c',
    muted: '#5f7283',
    line: '#b8cfdf',
    paper: '#FFFFFF',
    white: '#ffffff',
  };

  const FONT = 'Segoe UI, Arial, sans-serif';
  const A4_LANDSCAPE = {
    width: 1754,
    height: 1240,
  };

  const text = (value, fallback = '-') => {
    if (value === null || value === undefined) return fallback;
    const clean = String(value).trim();
    return clean ? clean : fallback;
  };

  const numberText = (value, fallback = '-') => {
    if (value === null || value === undefined || value === '') return fallback;
    const n = Number(value);
    if (Number.isNaN(n)) return text(value, fallback);
    return new Intl.NumberFormat('es-PE', { maximumFractionDigits: 0 }).format(n);
  };

  const slugify = (value) => text(value, 'unidad')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80)
    .toUpperCase();

  const notify = (options, message, type = 'ok') => {
    if (options && typeof options.onInfo === 'function') {
      options.onInfo(message, type);
    }
  };

  function createHiDPICanvas(width, height, ratio = 2) {
    const canvas = document.createElement('canvas');
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;

    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.textBaseline = 'top';
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';

    return { canvas, ctx };
  }

  function roundRectPath(ctx, x, y, w, h, r) {
    const radius = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.arcTo(x + w, y, x + w, y + h, radius);
    ctx.arcTo(x + w, y + h, x, y + h, radius);
    ctx.arcTo(x, y + h, x, y, radius);
    ctx.arcTo(x, y, x + w, y, radius);
    ctx.closePath();
  }

  function fillRound(ctx, x, y, w, h, r, color) {
    roundRectPath(ctx, x, y, w, h, r);
    ctx.fillStyle = color;
    ctx.fill();
  }

  function strokeRound(ctx, x, y, w, h, r, color, lineWidth = 1) {
    roundRectPath(ctx, x, y, w, h, r);
    ctx.strokeStyle = color;
    ctx.lineWidth = lineWidth;
    ctx.stroke();
  }

  function drawWrappedText(ctx, value, x, y, maxWidth, lineHeight, maxLines) {
    const words = text(value).split(/\s+/);
    const lines = [];
    let line = '';

    words.forEach((word) => {
      const testLine = line ? `${line} ${word}` : word;
      if (ctx.measureText(testLine).width > maxWidth && line) {
        lines.push(line);
        line = word;
      } else {
        line = testLine;
      }
    });

    if (line) lines.push(line);

    lines.slice(0, maxLines).forEach((item, index) => {
      let finalLine = item;
      if (index === maxLines - 1 && lines.length > maxLines) {
        while (finalLine.length && ctx.measureText(`${finalLine}...`).width > maxWidth) {
          finalLine = finalLine.slice(0, -1);
        }
        finalLine = `${finalLine.trim()}...`;
      }
      ctx.fillText(finalLine, x, y + (index * lineHeight));
    });

    return Math.min(lines.length, maxLines) * lineHeight;
  }

  function drawTextFit(ctx, value, x, y, maxWidth, startSize, minSize, weight = 900) {
    let size = startSize;
    const clean = text(value);

    while (size > minSize) {
      ctx.font = `${weight} ${size}px ${FONT}`;
      if (ctx.measureText(clean).width <= maxWidth) break;
      size -= 2;
    }

    ctx.fillText(clean, x, y);
    return size;
  }

  function drawCenteredText(ctx, value, x, y, w, options = {}) {
    ctx.font = `${options.weight || 800} ${options.size || 24}px ${FONT}`;
    ctx.fillStyle = options.color || COLORS.ink;
    ctx.textAlign = 'center';
    ctx.fillText(text(value), x + (w / 2), y);
    ctx.textAlign = 'left';
  }

  function drawPlaceholder(ctx, x, y, w, h, label, options = {}) {

    ctx.save();
    ctx.setLineDash([10, 8]);
    ctx.restore();

    drawCenteredText(ctx, label, x, y + (h / 2) - 13, w, {
      size: options.size || 20,
      weight: 800,
      color: options.fg || COLORS.white,
    });
  }

  function isImageReady(image) {
    return image
      && typeof image === 'object'
      && image.complete !== false
      && Number(image.naturalWidth || image.width || 0) > 0;
  }

  function drawImageContain(ctx, image, x, y, w, h, padding = 0) {
    if (!isImageReady(image)) return false;

    const iw = image.naturalWidth || image.width;
    const ih = image.naturalHeight || image.height;
    const aw = Math.max(1, w - padding * 2);
    const ah = Math.max(1, h - padding * 2);
    const scale = Math.min(aw / iw, ah / ih);
    const dw = iw * scale;
    const dh = ih * scale;
    const dx = x + padding + ((aw - dw) / 2);
    const dy = y + padding + ((ah - dh) / 2);

    ctx.drawImage(image, dx, dy, dw, dh);
    return true;
  }

  function drawImageCover(ctx, image, x, y, w, h, radius = 0) {
    if (!isImageReady(image)) return false;

    const iw = image.naturalWidth || image.width;
    const ih = image.naturalHeight || image.height;
    const scale = Math.max(w / iw, h / ih);
    const sw = w / scale;
    const sh = h / scale;
    const sx = Math.max(0, (iw - sw) / 2);
    const sy = Math.max(0, (ih - sh) / 2);

    ctx.save();
    if (radius > 0) {
      roundRectPath(ctx, x, y, w, h, radius);
      ctx.clip();
    }
    ctx.drawImage(image, sx, sy, sw, sh, x, y, w, h);
    ctx.restore();
    return true;
  }

  function loadImageSafe(source) {
    return new Promise((resolve) => {
      if (!source) {
        resolve(null);
        return;
      }

      if (isImageReady(source)) {
        resolve(source);
        return;
      }

      if (typeof source !== 'string') {
        resolve(null);
        return;
      }

      const img = new Image();
      img.decoding = 'async';

      if (!source.startsWith('data:') && !source.startsWith('blob:')) {
        img.crossOrigin = 'anonymous';
      }

      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
      img.src = source;
    });
  }

  function resolveAssetSources(data, options = {}) {
    const dataAssets = data.assets || {};
    const empresa = data.empresa || {};

    return {
      logo: options.logo
        || options.logoUrl
        || dataAssets.logo
        || dataAssets.logo_url
        || empresa.logo
        || empresa.logo_url
        || DEFAULT_ASSETS.logoUrl,
      bus: options.busImage
        || options.busUrl
        || dataAssets.bus
        || dataAssets.bus_url
        || data.bus?.imagen
        || data.bus?.imagen_url
        || DEFAULT_ASSETS.busUrl,
    };
  }

  function calculateLayout(data) {
    const conductores = Array.isArray(data.programacion?.conductores)
      ? data.programacion.conductores
      : [];
    const paraderos = ROUTE_INFO.paraderos;

    return {
      width: A4_LANDSCAPE.width,
      height: A4_LANDSCAPE.height,
      conductores,
      paraderos,
    };
  }

  function drawDiagonalBrand(ctx, x, y, h) {
    ctx.fillStyle = COLORS.red;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + 42, y);
    ctx.lineTo(x + 14, y + h);
    ctx.lineTo(x, y + h);
    ctx.closePath();
    ctx.fill();

    ctx.fillStyle = COLORS.yellow;
    ctx.beginPath();
    ctx.moveTo(x + 42, y);
    ctx.lineTo(x + 68, y);
    ctx.lineTo(x + 40, y + h);
    ctx.lineTo(x + 14, y + h);
    ctx.closePath();
    ctx.fill();
  }

  function drawCompanyHeader(ctx, data, assets, x, y, w) {
    const empresa = data.empresa || {};
    const patrimonio = data.patrimonio || {};

    const gradient = ctx.createLinearGradient(x, y, x + w, y);
    gradient.addColorStop(0, '#19547b');
    gradient.addColorStop(0.62, '#2d78aa');
    gradient.addColorStop(1, '#18557d');
    fillRound(ctx, x, y, w, 196, 20, gradient);

    drawDiagonalBrand(ctx, x, y, 196);

    const logoX = x + 82;
    const logoY = y - 50;
    const logoW = 300;
    const logoH = 300;

    if (!drawImageContain(ctx, assets.logo, logoX, logoY, logoW, logoH, 14)) {
      drawPlaceholder(ctx, logoX, logoY, logoW, logoH, 'LOGO', {
        radius: 16,
        size: 19,
      });
    }

    const busX = logoX + logoW + 16;
    const busY = y + 28;
    const busW = 238;
    const busH = 138;

    const titleX = busX + busW - 300;
    const titleW = w - (titleX - x) - 38;

    ctx.fillStyle = '#FFFFFF';
    ctx.font = `800 28px ${FONT}`;
    ctx.fillText('EMPRESA DE TRANSPORTE', titleX, y + 60);

    ctx.fillStyle = COLORS.yellow;
    ctx.shadowColor = 'rgba(0,0,0,.20)';
    ctx.shadowBlur = 4;
    ctx.shadowOffsetY = 2;
    drawTextFit(
      ctx,
      text('CRUZ DEL NORTE S.A.C.'),
      titleX,
      y + 90,
      titleW,
      50,
      30,
      950
    );
    ctx.shadowColor = 'transparent';

    ctx.fillStyle = '#d9efff';
    ctx.font = `800 20px ${FONT}`;
  }

  function drawRucStrip(ctx, data, x, y, w) {
    const empresa = data.empresa || {};
    const patrimonio = data.patrimonio || {};
    const marca = text(patrimonio.marca, 'Marca no registrada');
    const modelo = text(patrimonio.modelo, 'Modelo no registrado');

    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(x, y, w, 55);
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 2;
    ctx.strokeRect(x, y, w, 55);

    drawCenteredText(
      ctx,
      'R.U.C. N° 20403002101',
      x,
      y + 13,
      w,
      { size: 24, weight: 900, color: '#000000' }
    );

    ctx.font = `800 16px ${FONT}`;
    ctx.fillStyle = COLORS.muted;
    ctx.textAlign = 'right';
    ctx.textAlign = 'left';
  }

  function drawSectionLabel(ctx, label, x, y, w, h = 44) {
    ctx.fillStyle = COLORS.blue;
    ctx.fillRect(x, y, w, h);
    ctx.font = `900 23px ${FONT}`;
    ctx.fillStyle = COLORS.white;
    ctx.fillText(label, x + 18, y + 9);
  }

  function drawDrivers(ctx, conductores, x, y, w) {
    const visible = conductores.slice(0, 3);
    const rows = Math.max(visible.length, 2);
    const rowH = 108;
    const labelW = 270;

    for (let index = 0; index < rows; index += 1) {
      const item = visible[index] || {};
      const rowY = y + (index * rowH);

      ctx.fillStyle = index % 2 === 0 ? '#FFFFFF' : '#FFFFFF';
      ctx.fillRect(x, rowY, w, rowH);
      ctx.strokeStyle = COLORS.line;
      ctx.lineWidth = 2;
      ctx.strokeRect(x, rowY, w, rowH);

      drawSectionLabel(
        ctx,
        index === 0 ? '1.er CONDUCTOR' : index === 1 ? '2.do CONDUCTOR' : '3.er CONDUCTOR',
        x,
        rowY,
        labelW,
        42
      );

      ctx.fillStyle = COLORS.ink;
      drawTextFit(
        ctx,
        text(item.conductor, 'SIN CONDUCTOR ASIGNADO'),
        x + 34,
        rowY + 55,
        w - 520,
        32,
        22,
        900
      );

      ctx.font = `900 22px ${FONT}`;
      ctx.fillStyle = COLORS.ink;
      ctx.fillText('LIC. CONDUCIR:', x + w - 405, rowY + 61);

      ctx.font = `950 28px ${FONT}`;
      ctx.fillStyle = COLORS.navyDeep;
      drawTextFit(
        ctx,
        text(item.licencia, 'S/R'),
        x + w - 220,
        rowY + 57,
        190,
        28,
        20,
        950
      );
    }

    if (conductores.length > 3) {
      ctx.font = `800 17px ${FONT}`;
      ctx.fillStyle = COLORS.muted;
      ctx.fillText(`+ ${conductores.length - 3} conductor(es) adicional(es) en la programación.`, x + 18, y + (rows * rowH) + 8);
      return (rows * rowH) + 34;
    }

    return rows * rowH;
  }

  function drawVehicleSummary(ctx, data, x, y, w) {
    const bus = data.bus || {};
    const patrimonio = data.patrimonio || {};
    const capacity = patrimonio.capacidad_total || data.resumen?.capacidad_total;
    const cols = [0.34, 0.34, 0.32];
    const labels = ['N.° BUS', 'PLACA', 'CAPACIDAD'];
    const values = [text(bus.nombre), text(bus.placa), numberText(capacity)];

    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(x, y, w, 104);
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 2;
    ctx.strokeRect(x, y, w, 104);

    let cursorX = x;
    cols.forEach((ratio, index) => {
      const colW = w * ratio;
      if (index > 0) {
        ctx.beginPath();
        ctx.moveTo(cursorX, y + 14);
        ctx.stroke();
      }

      ctx.font = `900 22px ${FONT}`;
      ctx.fillStyle = COLORS.blue;
      ctx.fillText(labels[index], cursorX + 24, y + 18);

      ctx.font = `950 36px ${FONT}`;
      ctx.fillStyle = COLORS.ink;
      drawTextFit(ctx, values[index], cursorX + 24, y + 48, colW - 46, 36, 24, 950);
      cursorX += colW;
    });
  }

  function normalizeParadero(item) {
    if (typeof item === 'string' || typeof item === 'number') return text(item);
    return text(item?.nombre || item?.descripcion || item?.id, 'Paradero sin nombre');
  }

  function drawParaderos(ctx, paraderos, x, y, w) {
    const safe = paraderos.slice(0, 10).map(normalizeParadero);
    const cols = 3;
    const colGap = 28;
    const colW = (w - (colGap * (cols - 1)) - 60) / cols;
    const rows = Math.max(1, Math.ceil(Math.max(safe.length, 1) / cols));
    const areaH = 58 + (rows * 50) + 18;

    ctx.fillStyle = COLORS.paper;
    ctx.fillRect(x, y, w, areaH);
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 2;
    ctx.strokeRect(x, y, w, areaH);

    drawSectionLabel(ctx, 'PARADEROS AUTORIZADOS', x, y, 430, 46);

    if (!safe.length) {
      ctx.font = `850 23px ${FONT}`;
      ctx.fillStyle = COLORS.muted;
      ctx.fillText('SIN PARADEROS AUTORIZADOS REGISTRADOS', x + 30, y + 70);
      return areaH;
    }

    safe.forEach((label, index) => {
      const col = index % cols;
      const row = Math.floor(index / cols);
      const itemX = x + 30 + (col * (colW + colGap));
      const itemY = y + 62 + (row * 50);

      ctx.fillStyle = COLORS.blue;
      ctx.beginPath();
      ctx.arc(itemX + 8, itemY + 14, 7, 0, Math.PI * 2);
      ctx.fill();

      ctx.font = `850 23px ${FONT}`;
      ctx.fillStyle = COLORS.ink;
      drawWrappedText(ctx, label, itemX + 28, itemY, colW - 24, 27, 1);
    });

    if (paraderos.length > 10) {
      ctx.font = `800 16px ${FONT}`;
      ctx.fillStyle = COLORS.muted;
      ctx.fillText(`+ ${paraderos.length - 10} paradero(s) adicional(es) registrados.`, x + w - 420, y + areaH - 28);
    }

    return areaH;
  }

  function drawTechnicalStrip(ctx, data, x, y, w) {
    const empresa = data.empresa || {};
    const h = 128;
    const splitX = x + Math.round(w * 0.58);
    const leftW = splitX - x;
    const rightW = (x + w) - splitX;

    const price1 = ROUTE_INFO.precioPrimerNivel;
    const price2 = ROUTE_INFO.precioSegundoNivel;

    const configuredPhones = Array.isArray(empresa.telefonos)
      ? empresa.telefonos
      : [empresa.telefono1, empresa.telefono2].filter(Boolean);
    const phones = [...configuredPhones, ...DEFAULT_CONTACTS]
      .map((item) => text(item, ''))
      .filter(Boolean)
      .slice(0, 2);

    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(x, y, w, h);
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 2;
    ctx.strokeRect(x, y, w, h);

    // Separación central entre precios y teléfonos.
    ctx.beginPath();
    ctx.moveTo(splitX, y);
    ctx.lineTo(splitX, y + h);
    ctx.stroke();

    drawSectionLabel(ctx, 'PRECIOS', x, y, 190, 44);
    drawSectionLabel(ctx, 'QUEJAS Y RECLAMOS', splitX, y, rightW, 44);

    ctx.font = `900 21px ${FONT}`;
    ctx.fillStyle = COLORS.ink;
    ctx.fillText('1.er NIVEL', x + 30, y + 58);
    ctx.fillText('2.do NIVEL', x + 30, y + 91);

    ctx.font = `950 28px ${FONT}`;
    ctx.fillStyle = COLORS.navyDeep;
    drawTextFit(ctx, price1, x + 205, y + 53, leftW - 235, 28, 20, 950);
    drawTextFit(ctx, price2, x + 205, y + 86, leftW - 235, 28, 20, 950);

    ctx.textAlign = 'center';
    ctx.font = `950 25px ${FONT}`;
    ctx.fillStyle = COLORS.navyDeep;
    ctx.fillText(phones[0] || 'S/R', splitX + (rightW / 2), y + 55);
    ctx.fillText(phones[1] || 'S/R', splitX + (rightW / 2), y + 87);
    ctx.textAlign = 'left';

    return h;
  }

  function drawFooter(ctx, x, y, w) {
    ctx.strokeStyle = COLORS.line;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x + w, y);
    ctx.stroke();

    ctx.font = `800 16px ${FONT}`;
    ctx.fillStyle = COLORS.muted;
    ctx.fillText(`Generado: ${new Date().toLocaleString('es-PE')}`, x, y + 18);

    ctx.textAlign = 'right';
    ctx.fillText('Norte 360 · ERP Operativo de Transporte', x + w, y + 18);
    ctx.textAlign = 'left';
  }

  function drawCard(ctx, data, assets, layout) {
    const { width, height, conductores, paraderos } = layout;
    const outer = 32;
    const cardX = 48;
    const cardY = 42;
    const cardW = width - 96;
    const cardH = height - 84;
    const innerX = cardX + 22;
    const innerW = cardW - 44;

    const bg = ctx.createLinearGradient(0, 0, 0, height);
    bg.addColorStop(0, '#FFFFFF');
    bg.addColorStop(1, '#FFFFFF');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, width, height);

    ctx.save();
    ctx.shadowColor = 'rgba(15,47,70,.18)';
    ctx.shadowBlur = 24;
    ctx.shadowOffsetY = 8;
    fillRound(ctx, cardX, cardY, cardW, cardH, 28, COLORS.paper);
    ctx.restore();
    strokeRound(ctx, cardX, cardY, cardW, cardH, 28, '#b7ccda', 2);

    let y = cardY + outer;
    drawCompanyHeader(ctx, data, assets, innerX, y, innerW);
    y += 196;

    drawRucStrip(ctx, data, innerX, y, innerW);
    y += 55;

    const driversH = drawDrivers(ctx, conductores, innerX, y, innerW);
    y += driversH;

    drawVehicleSummary(ctx, data, innerX, y, innerW);
    y += 104;

    const paraderosH = drawParaderos(ctx, paraderos, innerX, y, innerW);
    y += paraderosH;

    drawTechnicalStrip(ctx, data, innerX, y, innerW);

    drawFooter(ctx, innerX, cardY + cardH - 60, innerW);
  }

  /*
   * Versión síncrona. Útil para previsualización inmediata.
   * Para dibujar imágenes, pásalas ya cargadas como options.logo/options.busImage.
   */
  function buildCanvas(data, options = {}) {
    const layout = calculateLayout(data || {});
    const { canvas, ctx } = createHiDPICanvas(layout.width, layout.height, options.pixelRatio || 2);
    drawCard(ctx, data || {}, {
      logo: options.logo || null,
      bus: options.busImage || null,
    }, layout);
    return canvas;
  }

  /*
   * Versión asíncrona. Carga logo y bus mediante URL y luego genera la ficha.
   */
  async function buildCanvasWithAssets(data, options = {}) {
    const layout = calculateLayout(data || {});
    const { canvas, ctx } = createHiDPICanvas(layout.width, layout.height, options.pixelRatio || 2);
    const sources = resolveAssetSources(data || {}, options);
    const [logo, bus] = await Promise.all([
      loadImageSafe(sources.logo),
      loadImageSafe(sources.bus),
    ]);

    drawCard(ctx, data || {}, { logo, bus }, layout);
    return canvas;
  }

  function esDispositivoAppleConSafariMovil() {
    const ua = navigator.userAgent || '';
    const platform = navigator.platform || '';
    const isIOS = /iPad|iPhone|iPod/i.test(ua);
    const isIPadOSDesktopMode = platform === 'MacIntel' && Number(navigator.maxTouchPoints || 0) > 1;
    return isIOS || isIPadOSDesktopMode;
  }

  function canvasToPngBlob(canvas) {
    return new Promise((resolve) => {
      if (!canvas || !canvas.toBlob) {
        resolve(null);
        return;
      }
      canvas.toBlob((blob) => resolve(blob), 'image/png');
    });
  }

  async function descargarCanvas(canvas, nombreArchivo, options = {}) {
    const esAppleMovil = esDispositivoAppleConSafariMovil();

    if (!esAppleMovil) {
      const a = document.createElement('a');
      a.href = canvas.toDataURL('image/png');
      a.download = nombreArchivo;
      document.body.appendChild(a);
      a.click();
      a.remove();
      notify(options, 'Ficha operativa descargada.');
      return;
    }

    const blob = await canvasToPngBlob(canvas);

    if (blob && window.File && navigator.share && navigator.canShare) {
      const file = new File([blob], nombreArchivo, { type: 'image/png' });

      if (navigator.canShare({ files: [file] })) {
        try {
          await navigator.share({
            files: [file],
            title: nombreArchivo,
            text: 'Ficha operativa de unidad Norte 360',
          });
          notify(options, 'Imagen lista para compartir o guardar.');
          return;
        } catch (error) {
          if (error && error.name === 'AbortError') return;
        }
      }
    }

    const url = blob ? URL.createObjectURL(blob) : canvas.toDataURL('image/png');
    const nuevaVentana = window.open(url, '_blank');

    if (!nuevaVentana) {
      window.location.href = url;
    }

    notify(
      options,
      'En iPhone/iPad se abrió la imagen en una pestaña. Usa Compartir o mantén presionada la imagen para guardarla.',
      'ok'
    );

    if (blob) {
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    }
  }

  async function download(data, options = {}) {
    if (!data || !data.bus) {
      throw new Error('No hay una unidad seleccionada para generar imagen.');
    }

    const bus = data.bus;
    const dateStamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const nombreArchivo = `FICHA_OPERATIVA_${slugify(bus.nombre)}_${slugify(bus.placa)}_${dateStamp}.png`;
    const canvas = await buildCanvasWithAssets(data, options);
    await descargarCanvas(canvas, nombreArchivo, options);
  }

  window.N360BusLookupImage = {
    buildCanvas,
    buildCanvasWithAssets,
    download,
    descargarCanvas,
  };
})();
