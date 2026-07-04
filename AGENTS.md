# AGENTS.md - Norte360 Web

Este proyecto corresponde al sitio web de Norte360 desarrollado en PHP clásico + MySQL/MariaDB.

Este AGENTS.md solo define reglas técnicas del sitio web.  
La lógica central del negocio, base de datos, permisos, flujos y reglas compartidas vive en:

- ../00_core_norte360/AGENTS.md
- ../00_core_norte360/docs/contexto_general_norte360.md
- ../00_core_norte360/docs/base_datos.md
- ../00_core_norte360/docs/reglas_negocio.md
- ../00_core_norte360/docs/permisos.md
- ../00_core_norte360/docs/diferencias_python_vs_web.md
- ../00_core_norte360/docs/bitacora_cambios.md

## Alcance de esta carpeta

Esta carpeta contiene el sistema web Norte360.

Estructura principal:

- index.php: panel principal.
- login/: autenticación web.
- layout/: componentes reutilizables como sidebar, header y estructuras comunes.
- assets/: CSS, JS, imágenes y recursos del sistema web.
- 01_almacen/: inventario, movimientos, catálogo, scanner, notas, ubicaciones y auditorías.
- 01_flota/: programación de buses, conductores, placas, pizarra, cron y gestión operativa.
- 01_contratos/: RRHH, trabajadores, contratos y documentación.
- 01_entrevistas/: entrevistas para personal.
- 01_mantenimiento/: mantenimiento, checklists, limpieza y fumigación.
- php/: utilidades PHP compartidas.
- css/ y css2/: estilos antiguos o específicos pendientes de ordenar.

## Sistema clon EYC

Existe otro sistema paralelo/clon llamado EYC.

EYC no debe asumirse como un Norte360 completo.  
EYC funciona principalmente para el flujo de Combustible, especialmente movimientos, grifos, precios, conciliación contable y reportes relacionados.

Cuando se toque lógica de combustible, revisar impacto en ambos sistemas:

- Norte360 principal.
- Sistema EYC.

Especial cuidado con:

- tb_alm_movimientos.
- tb_conta_combustible.
- tb_hist_pu_promedio_combustible.
- tb_pasadoprecio_combustibles.
- tb_pasadoextraprecio_combustibles.
- view_listprod_combustibles.
- view_listespacios_combustibles.
- view_combustibles_por_grifo.
- view_consumo_actual_grifos.
- view_consumo_actual_vehiculos.
- view_precios_salida_grifos.
- Triggers de promedio de precio unitario.
- Notas de salida vinculadas a combustible.
- PDFs de combustible o conciliación contable.

No copiar cambios de Norte360 a EYC sin validar que EYC solo usa combustible.

## Reglas obligatorias

- No modificar archivos sin mostrar primero un plan.
- No tocar .env, .config.env, .configbd2.env, credenciales, backups, trash, uploads ni archivos sensibles.
- No cambiar nombres de tablas, columnas, rutas, permisos ni sesiones sin autorización.
- Mantener compatibilidad con Hostinger shared hosting.
- Mantener PHP clásico. No convertir a Laravel sin autorización.
- Usar componentes reutilizables en layout/, assets/css/ y assets/js/.
- No duplicar lógica central si ya está documentada en ../00_core_norte360.
- No usar emojis en interfaces.
- Usar Bootstrap y Bootstrap Icons cuando ayude al diseño profesional.
- Priorizar parches puntuales antes que reescrituras completas.
- Cuando se proponga un cambio, indicar archivo, función/sección, problema y reemplazo exacto.
- Solo aplicar cambios cuando el usuario diga explícitamente y en mayúsculas: APLICAR EL CAMBIO.

## Estilo visual web

El sistema debe mantener una apariencia:

- Profesional.
- Corporativa.
- Limpia.
- Gerencial.
- Fácil de explicar.
- Reutilizable por módulos.

Preferencias visuales:

- Cards ordenadas.
- Filtros compactos.
- Modales profesionales.
- Tablas que usen el ancho disponible.
- Sidebar reutilizable.
- Acordeones por módulo cuando haya varias vistas.
- Iconografía profesional con Bootstrap Icons.

Evitar:

- Interfaces recargadas.
- Emojis en botones o títulos.
- Iconos desproporcionados.
- Código CSS duplicado sin necesidad.
- Tablas angostas cuando tienen muchas columnas.

## Navegación y permisos

La navegación debe respetar:

- $_SESSION['web_rol']
- $_SESSION['permisos']
- $_SESSION['vistas']
- Usuario autenticado.
- Módulos y vistas permitidas.

La barra lateral web debe ser reutilizable y renderizar módulos según permisos del usuario.

## Flujo de trabajo esperado

1. Leer este AGENTS.md.
2. Leer el core si la tarea toca lógica de negocio.
3. Analizar los archivos involucrados.
4. Proponer plan.
5. Esperar aprobación.
6. Aplicar solo cambios aprobados.