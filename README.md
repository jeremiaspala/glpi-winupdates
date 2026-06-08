# GLPI-Win Updates

Seguro que en tu trabajo tenés algún equipo con Windows 10 corriendo parches de 2023, algún servidor 2012 que "funciona" y nadie toca, y un par de notebooks con el SO en EOL desde hace meses. El problema no es que no haya actualizaciones disponibles, sino que no tenés visibilidad de qué está al día y qué no. Este plugin lo resuelve.

**GLPI-Win Updates** es un plugin para GLPI 11 que toma los datos del inventario automático del GLPI Agent y te muestra el estado de actualizaciones de cada equipo — Windows y Linux — con un indicador claro de compliance. Y si encontrás algo desactualizado, podés lanzar la actualización remota directo desde la interfaz, sin WinRM, sin Wazuh, sin agentes adicionales. Solo el GLPI Agent que ya tenés desplegado.

---

## Qué hace

- Lee el build de cada equipo desde el inventario de GLPI Agent
- Lo compara contra una tabla configurable de builds de referencia (actualizables después de cada Patch Tuesday)
- Muestra un indicador por equipo:
  - 🟢 **Al día** — SO soportado, build ≥ al mínimo requerido
  - 🟡 **Actualizaciones pendientes** — SO soportado pero con parches sin aplicar
  - 🔴 **Sin soporte (EOL)** — el SO ya no recibe actualizaciones de seguridad
- Soporta Windows (10, 11, Server 2012 R2 hasta 2025) y Linux (Debian y Ubuntu)
- Permite **forzar una actualización remota** por equipo o en bulk, creando una tarea de deploy en GLPI Inventory que el agente levanta en su próximo check-in
- Historial de todos los deploys lanzados con estado por agente
- Exportación a PDF para auditorías o compliance
- **Detalle de actualizaciones (KB) instaladas y faltantes por equipo** — botón
  <i class="ti ti-list-details"></i> en cada fila Windows: muestra el listado completo de hotfixes
  (`KBxxxxxxx`) detectados por el inventario con su fecha de instalación, y estima si hay
  **actualizaciones faltantes** según la antigüedad del último parche reportado (más de ~45 días sin
  novedades = posible brecha de parcheo). En la tabla principal aparece una columna resumen
  "Parches detectados" con la cantidad de KBs y la fecha del último.

---

## Cómo funciona la actualización remota

No es magia, es el GLPI Agent que ya tenés. El plugin crea un paquete de deploy en GLPI Inventory con los comandos:

**Windows:**
```
UsoClient.exe StartScan → StartDownload → StartInstall
```

**Linux (Debian/Ubuntu):**
```bash
apt-get update -y && apt-get upgrade -y
```

El agente lo levanta en el próximo check-in. Si el agente está accesible en la red, el plugin le manda un wake-up HTTP al puerto 62354 para que lo ejecute de inmediato. El estado (Pendiente / Ejecutando / OK / Error) lo ves en la sección Historial.

---

## Detalle de actualizaciones instaladas / faltantes (auditoría)

El GLPI Agent reporta los **hotfixes de Windows instalados** como software con nombre `KBxxxxxxx`
(tabla `glpi_softwares`, vinculados al equipo vía `glpi_items_softwareversions`, con fecha de
instalación en `date_install`). El plugin usa esos datos para mostrar, por equipo (botón
<i class="ti ti-list-details"></i> en la columna **Acciones** del dashboard):

- **Listado de KBs instalados**, ordenados por fecha (más reciente primero), con su fecha de instalación
- **Última fecha de parcheo detectada** y cuántos días pasaron desde entonces (badge de frescura
  en la columna "Parches detectados" del dashboard: 🟢 ≤45 días / 🟡 46-120 / 🔴 más de 120 días)
- **Listado de actualizaciones faltantes**, calculado contra un **catálogo de referencia** que
  cargás vos (ver siguiente sección)

### Catálogo de actualizaciones de referencia y tipos a verificar

No existe forma de bajar el catálogo completo de Microsoft Update a una instalación on-premise,
así que el plugin **no puede inventar** qué KB falta — necesita que vos le digas qué KBs esperás
ver instalados en cada versión de Windows. Para eso, en **Win Updates → Configuración** hay dos
secciones nuevas:

1. **Tipos de actualización a verificar**: checkboxes (Seguridad, Críticas, Opcionales, Funcionalidades,
   Definiciones de antivirus, Controladores). Solo los tipos marcados se tienen en cuenta al calcular
   faltantes — por ejemplo, podés auditar nada más Seguridad + Críticas e ignorar el ruido de
   actualizaciones de Defender o drivers.
2. **Catálogo de actualizaciones de referencia**: una tabla editable (con botones para agregar/quitar
   filas) donde cargás, para cada **kernel/build base** (mismo valor que en la tabla de builds de
   arriba, ej. `10.0.26100`), el **número de KB**, su **tipo**, una descripción y la fecha de
   publicación. Tras cada Patch Tuesday agregás ahí la(s) acumulativa(s) del mes para las versiones
   que te interesa auditar.

El plugin compara, equipo por equipo, las entradas del catálogo que matchean su kernel y son de un
tipo verificado contra los KBs detectados en su inventario — lo que no aparece, se lista como
**faltante**. Si todavía no cargaste catálogo para una versión de Windows, el detalle del equipo te
avisa que no puede determinar faltantes para ese SO.

---
<img width="1875" height="876" alt="Win Updates" src="https://github.com/user-attachments/assets/80fbb431-c1f1-4917-98bd-df1c99e4ea1a" />
<img width="267" height="702" alt="Win Updates 4" src="https://github.com/user-attachments/assets/f4c710c1-4081-42c0-b843-dafc8be6b289" />
<img width="1059" height="828" alt="Win Updates 3" src="https://github.com/user-attachments/assets/26d72e64-97f6-495c-98fc-fdb56922afcb" />

## Requisitos

- GLPI >= 11.0.0 < 12.0.0
- Plugin [GLPI Inventory](https://github.com/glpi-project/glpi-inventory-plugin) instalado y activo, con el módulo DEPLOY habilitado
- [GLPI Agent](https://github.com/glpi-project/glpi-agent) desplegado en los equipos (módulo PackageDeployment)

---

## Instalación

**1. Copiá el plugin a GLPI:**
```bash
unzip winupdates-1.1.0.zip -d /var/www/html/glpi/plugins/
chown -R www-data:www-data /var/www/html/glpi/plugins/winupdates/
```

**2. Creá la tabla de configuración:**
```sql
CREATE TABLE IF NOT EXISTS glpi_plugin_winupdates_config (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(100) NOT NULL,
  value LONGTEXT,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**3. Activá el plugin desde GLPI:**

`Configuración → Plugins → Windows Update Status → Activar`

---

## Actualizar los builds de referencia

Microsoft publica actualizaciones el segundo martes de cada mes (Patch Tuesday). Después de cada ciclo:

1. Entrá a **Win Updates → Configuración**
2. Actualizá el campo **Build mínimo requerido** con el nuevo build
3. Los equipos por debajo del build pasan automáticamente a 🟡

Los builds los encontrás en el [Microsoft Security Update Guide](https://msrc.microsoft.com/update-guide/) o en el artículo de soporte del mes para cada versión de Windows.

---

## Versiones EOL detectadas (a junio 2026)

| SO | EOL desde |
|---|---|
| Windows 10 Home/Pro (todas) | Oct 2025 |
| Windows 11 21H2 | Oct 2023 |
| Windows 11 22H2 (Home/Pro) | Oct 2024 |
| Windows 11 23H2 (Home/Pro) | Nov 2025 |
| Windows Server 2012 R2 | Oct 2023 |
| Debian 11 Bullseye | Jun 2026 |
| Ubuntu 20.04 LTS | Abr 2025 |

---

## Estructura del plugin

```
winupdates/
├── setup.php                  # Registro y menú en GLPI 11
├── hook.php                   # Hooks
├── inc/
│   ├── report.class.php       # Lógica de status Windows/Linux y tabla de builds
│   └── deploy.class.php       # Integración con GLPI Inventory Deploy
└── front/
    ├── report.php             # Dashboard principal
    ├── push.php               # Endpoint AJAX para forzar actualización
    ├── updates.php            # Endpoint AJAX: detalle de KBs instalados/faltantes por equipo
    ├── history.php            # Historial de tareas de deploy
    ├── pdf.php                # Vista de compliance/impresión
    └── config.php             # Configuración de builds de referencia
```

---

## Autor

**Jeremías Palazzesi**
Blog: [nerdadas.com](https://nerdadas.com)

---

## Licencia

GPL v2+
