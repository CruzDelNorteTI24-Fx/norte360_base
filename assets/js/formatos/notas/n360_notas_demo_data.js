(function (window) {
    'use strict';

    function nowParts() {
        if (window.N360NotasCommon && typeof window.N360NotasCommon.nowParts === 'function') {
            return window.N360NotasCommon.nowParts();
        }

        const date = new Date();
        const pad = n => String(n).padStart(2, '0');
        return {
            fecha: `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
            hora: `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`,
            impreso: `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
        };
    }

    function base(cfg, kind) {
        const now = nowParts();
        return {
            kind,
            ruc: cfg.ruc || '20403002101',
            fecha: now.fecha,
            hora: now.hora,
            impreso: now.impreso,
            responsible: cfg.userName || 'admin',
            dni: cfg.dni || '72953637',
            footerLabel: 'NORTE 360'
        };
    }

    function salidaAlmacen(cfg) {
        return Object.assign(base(cfg, 'NS'), {
            correlativo: 18,
            module: 'Almac\u00e9n',
            space: 'ALMACEN (ALM)',
            unitText: 'BUS 158 (ABC-321)',
            actor: 'Taller de mantenimiento',
            reason: 'Atencion de mantenimiento preventivo de unidad.',
            products: [
                {qty: '2', description: 'FILTRO DE ACEITE MOTOR 15W40 - UNIDAD'},
                {qty: '1', description: 'FAJA DE ALTERNADOR PARA BUS INTERPROVINCIAL'}
            ]
        });
    }

    function tanqueada(cfg) {
        return Object.assign(base(cfg, 'CM'), {
            correlativo: 24,
            module: 'Combustible',
            space: 'GRIFO PRINCIPAL (DIESEL)',
            unitText: 'BUS 158 ABC-321',
            actor: 'Juan Perez Gomez',
            reason: 'Tanqueo a unidad BUS 158 para salida operativa.',
            products: [
                {qty: '45.0000', unit: 'GLN', description: 'DIESEL B5 S-50', unitPrice: '14.8500', amount: '668.2500'}
            ],
            total: 'S/. 668.2500'
        });
    }

    function entradaAlmacen(cfg) {
        return Object.assign(base(cfg, 'NE'), {
            correlativo: 31,
            module: 'Almac\u00e9n',
            space: 'ALMACEN (ALM)',
            provider: 'REPUESTOS DEL NORTE S.A.C.',
            documentRef: 'F001-000245',
            reason: 'Ingreso de compra regular de almacen.',
            products: [
                {qty: '6', description: 'ACEITE MOTOR 15W40 GALON'}
            ]
        });
    }

    function salidaBienes(cfg) {
        return Object.assign(base(cfg, 'RS'), {
            correlativo: 12,
            module: 'RRHH',
            space: 'RRHH',
            unitText: '',
            actor: 'Juan Perez Gomez (72953637)',
            reason: 'Asignacion de bien controlado para uso operativo.',
            products: [
                {qty: '1', description: '(SI1061) BOTAS DE PERSONAL - UN'},
                {qty: '1', description: '(SI1077) CASCO DE SEGURIDAD - UN'}
            ]
        });
    }

    function entradaBienes(cfg) {
        return Object.assign(base(cfg, 'RE'), {
            correlativo: 9,
            module: 'RRHH',
            space: 'RRHH',
            provider: 'Compra interna / stock inicial',
            documentRef: 'RRHH-REF-001',
            reason: 'Ingreso de bienes controlados para personal.',
            products: [
                {qty: '5', description: '(SI1061) BOTAS DE PERSONAL - UN'}
            ]
        });
    }
    function abastecimiento(cfg) {
        return Object.assign(base(cfg, 'AB'), {
            correlativo: 11,
            module: 'Combustible',
            space: 'GRIFO NORTE 360',
            provider: 'E & C AUTOSERVICIOS S.A.C.',
            reason: 'Abastecimiento de combustible a tanque principal.',
            products: [
                {qty: '350.0000', description: 'DIESEL B5 S-50', unitPrice: '14.1200', amount: '4942.0000'}
            ]
        });
    }

    function get(kind, cfg) {
        const type = String(kind || '').toUpperCase();
        const config = cfg || {};
        const map = {
            NS: salidaAlmacen,
            CM: tanqueada,
            NE: entradaAlmacen,
            RE: entradaBienes,
            RS: salidaBienes,
            AB: abastecimiento
        };

        if (!map[type]) {
            throw new Error(`No existe data demo para el formato ${type}.`);
        }

        return map[type](config);
    }

    window.N360NotasDemoData = {
        get,
        salidaAlmacen,
        tanqueada,
        entradaAlmacen,
        entradaBienes,
        salidaBienes,
        abastecimiento
    };
})(window);
