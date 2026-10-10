# Instalar el CRM de Evolvix Global en Hostalia

Esta versión no usa Supabase: la base de datos, el panel y la lógica que
protege quién puede hacer qué viven **enteros en tu propio hosting de
Hostalia**, en MySQL y PHP (lo que trae de serie cualquier plan de Hostalia
con web). No hace falta terminal ni SSH: todo se hace desde el panel de
Hostalia (Plesk o cPanel) y desde el navegador.

## Paso 1 · Crear la base de datos MySQL

En el panel de Hostalia: **Bases de datos → Crear base de datos MySQL**.
Apunta estos cuatro datos que te da Hostalia al crearla — los necesitarás en
el paso 3:

- Servidor (host): a veces es `localhost`, a veces algo como `mysqlXXX.hostalia.com`
- Nombre de la base de datos
- Usuario
- Contraseña

## Paso 2 · Crear las tablas

Entra en **phpMyAdmin** desde el panel de Hostalia, elige tu base de datos
nueva, pestaña **SQL**, y pega entero el contenido de `sql/01-esquema.sql`.
Pulsa **Continuar/Ejecutar**.

Verás al final una fila de comprobación: `marcas >= 3` y `tablas_creadas = 9`.
Esto crea las tablas y siembra las marcas del grupo, ya listas:
**Grupo Evolvix Global** (facturas a nombre de la sociedad, `EVO-2026-0001`…),
**Labs24k** (`LAB-2026-0001`…) e **ICC (Integral Connection Consulting)**
(`ICC-2026-0001`…).

> **¿Ya lo tenías instalado?** Vuelve a ejecutar `sql/01-esquema.sql` entero
> igual que la primera vez. No borra ni cambia nada de lo que ya hay: solo
> añade las tablas nuevas (`documentos` y `documento_eventos`) y la marca
> Grupo Evolvix Global si falta (si ya la tenías como «Evolvix Global», la
> renombra).

## Paso 3 · Subir los archivos

Sube **todo** el contenido de esta carpeta (`index.html`, `.htaccess`,
`.user.ini`, `evolvix-icono.png`, `instalar.php` y las carpetas `api/`,
`vendor/` y `archivos/`) a la raíz de tu dominio en Hostalia (normalmente
`httpdocs` o `public_html`), con el administrador de archivos o por FTP.
Los archivos que empiezan por punto (`.htaccess`, `.user.ini`,
`archivos/.htaccess`) a veces están ocultos en el ordenador: asegúrate de
que también se suben.

- `vendor/pdfjs/` es el lector de PDF que usa el panel para leer las
  facturas que arrastras. Va dentro de tu propio hosting a propósito: las
  facturas no se envían a ningún servicio de fuera.
- `archivos/` es donde se guardan los PDF y fotos de las facturas. Su
  `.htaccess` impide que nadie los abra directamente; solo se ven desde el
  panel, con sesión iniciada. Si Hostalia te deja una carpeta **fuera** de
  `httpdocs`, todavía mejor: indícala en `api/config.php`
  (`EVOLVIX_DIR_ARCHIVOS`, viene explicado dentro).
- `.user.ini` sube el límite de tamaño de archivo a 20 MB. Si tu plan no lo
  admite, cambia `upload_max_filesize` y `post_max_size` en **Plesk →
  Configuración de PHP**. (Las fotos del móvil el panel ya las reduce solo
  antes de subirlas.) La carpeta `sql/` también puedes subirla como referencia, aunque
ya no hace falta después del paso 2 (el `.htaccess` impide que nadie pueda
descargar los `.sql` directamente).

Abre `api/config.php` con el editor de archivos de Hostalia (o descárgalo,
edítalo con el Bloc de notas, y vuelve a subirlo) y rellena los cuatro datos
del paso 1:

```php
define('EVOLVIX_DB_HOST', 'el-servidor-que-te-dio-hostalia');
define('EVOLVIX_DB_NOMBRE', 'el-nombre-de-tu-base-de-datos');
define('EVOLVIX_DB_USUARIO', 'tu-usuario-mysql');
define('EVOLVIX_DB_CLAVE', 'tu-contraseña-mysql');
```

## Paso 4 · Crear tu cuenta de administrador

Abre en el navegador `https://tudominio.com/instalar.php`, rellena el
formulario (nombre, correo, contraseña de al menos 10 caracteres con
mayúscula, minúscula, número y símbolo) y pulsa **Crear administrador**.

**Después de usarlo, borra `instalar.php` del servidor** con el
administrador de archivos de Hostalia. El propio script se niega a
funcionar una segunda vez en cuanto existe cualquier cuenta, pero es más
limpio no dejarlo ahí.

## Comprobar que ha funcionado

Abre `https://tudominio.com/index.html`, entra con el correo y la
contraseña del paso 4. Deberías ver el **Resumen del Grupo** con Grupo
Evolvix Global, Labs24k e ICC ya creadas, sin facturas todavía.

**Consejo:** en **Marcas**, pon el CIF de Grupo Evolvix Global SL en las marcas
(desde phpMyAdmin, tabla `marcas`, columna `cif`). Con el CIF, el panel
distingue todavía mejor solo si una factura que arrastras es emitida o
recibida.

## Cómo se trabaja

- **Subir facturas.** Arrastra los PDF (o fotos) a cualquier parte del
  panel, o pulsa la zona de subida para elegirlos. Desde el móvil también
  puedes hacer una foto. Cada factura se sube al momento y el panel lee sus
  datos: si es emitida o recibida, de qué marca es, número, fechas, cliente
  o proveedor, NIF, base, IVA, IRPF y total. También propone una categoría
  de gasto. Lo que ha leído sale en **turquesa** para que lo compruebes.
- **Revisar y contabilizar.** Corrige lo que haga falta (se va guardando
  solo) y pulsa **Contabilizar**, o **Contabilizar las N completas** para
  hacerlas todas de golpe. Hasta entonces la factura está «por revisar» y no
  cuenta en ningún sitio. Si en el PDF el total no cuadra con la base y los
  impuestos, el panel te avisa.
- **Emitidas / Recibidas.** Son los libros de facturas de todo el grupo,
  con filtros por año, trimestre, marca y estado, los totales del filtro y
  el PDF original de cada una. Las facturas creadas desde el propio CRM
  (**Nueva factura**) también salen en Emitidas.
- **Cobros y pagos.** Lo que os deben y lo que debéis, ordenado por
  vencimiento, con lo vencido en rojo. Se marca como cobrada o pagada con un
  clic.
- **Asesoría fiscal.** IVA repercutido y soportado del trimestre
  (estimación del modelo 303), retenciones de IRPF, resultado y gastos por
  categoría. Desde aquí se descargan los **libros en Excel (CSV)** y un
  **ZIP con todos los PDF** del periodo, con nombres ordenados por fecha.

Una factura contabilizada no se borra nunca: solo se anula. Si hay que
corregir un dato, un **administrador** puede **reabrirla** (vuelve a «por
revisar») y queda apuntado en el histórico. Un borrador que no se ha
contabilizado sí se puede **descartar** (por ejemplo, si se subió por error).

## Añadir más cuentas (admin o asesoría) más adelante

No hace falta volver a tocar `instalar.php`. Abre
`https://tudominio.com/api/generar-hash.php`, escribe la contraseña que
quieras darle a la persona, y te da dos cosas: el valor cifrado, y una
plantilla de `INSERT` ya lista para pegar en phpMyAdmin (pestaña SQL) —
solo tienes que cambiar el correo, el nombre y el rol (`'admin'` o
`'contable'`).

**Para la asesora fiscal o laboral**, usa el rol `'contable'` (en el panel
sale como «Asesoría / contabilidad»). Puede subir, revisar y contabilizar
facturas, marcar cobros y pagos, crear facturas y clientes, y descargar todo
para presentar los impuestos. Lo único que no puede es crear marcas ni
reabrir facturas ya contabilizadas: eso queda para un administrador.

## Añadir más marcas del grupo

Solo un administrador puede hacerlo, desde el propio panel: **Marcas →
Nueva marca**. No hace falta tocar la base de datos ni ningún archivo.

---

## Qué protege cada capa, y una diferencia honesta con la versión de Supabase

En la versión de Supabase, la propia base de datos (con RLS, seguridad por
fila) garantizaba estas reglas aunque alguien se saltara la aplicación. En
MySQL compartido de Hostalia esa garantía no existe de la misma forma: el
usuario de base de datos que te da Hostalia normalmente tiene permiso total
sobre su propia base. Así que aquí estas reglas las hace cumplir el código
PHP de `api/`, no MySQL:

- **Sesión por cookie**, segura (`HttpOnly`, `Secure` en HTTPS, `SameSite=Strict`)
  y un token anti-CSRF que se comprueba en cada acción que cambia datos.
- Solo un administrador puede crear marcas nuevas (`api/marcas.php`
  comprueba el rol antes de tocar la base de datos).
- Cualquier persona con sesión puede dar de alta clientes y facturas.
- **Ninguna factura se puede borrar nunca** — no porque la base de datos lo
  impida, sino porque `api/facturas.php` sencillamente no tiene ningún
  método para borrar una, y `api/documentos.php` se niega a borrar una
  factura subida en cuanto está contabilizada. Solo existe anular. Lo único
  que se puede descartar es un borrador subido que todavía no se ha
  contabilizado.
- Cada factura creada y cada cambio de estado quedan en un histórico
  (`factura_eventos` y `documento_eventos`) que la aplicación solo puede
  rellenar, nunca editar ni borrar.
- **Los PDF y fotos subidos** solo se admiten si de verdad lo son (el
  servidor mira el contenido del archivo, no su nombre: un `.php`
  disfrazado de `.pdf` se rechaza). Se guardan con un nombre aleatorio y sin
  extensión en `archivos/`, que no se puede abrir desde fuera, y solo salen
  por `api/documentos.php` con sesión iniciada. El mismo archivo no se
  puede subir dos veces, y la misma factura (mismo número del mismo NIF) no
  se puede contabilizar dos veces.
- **Las facturas se leen en tu navegador**, con el lector de PDF alojado en
  tu propio hosting (`vendor/pdfjs/`). Su contenido no se envía a ningún
  servicio externo.
- Reabrir una factura contabilizada para corregirla solo lo puede hacer un
  administrador.
- La numeración de cada factura (`LAB-2026-0001`, `ICC-2026-0001`, por marca y año) la
  calcula el propio servidor con un truco de MySQL pensado para que dos
  facturas creadas a la vez por personas distintas nunca choquen.

Si en algún momento tu plan de Hostalia te permite crear un segundo usuario
de MySQL con permisos restringidos (por ejemplo, sin `DELETE` en la tabla
`facturas`), dímelo y añado esa capa de más como refuerzo — pero el sistema
ya es seguro sin ella, porque esa puerta simplemente no existe en el código.

## Comprobaciones hechas antes de entregarte esto

Contra una base de datos MySQL/MariaDB real y el propio PHP (sin trucos ni
simulaciones):

- Un administrador crea una segunda marca; una cuenta de contable **no**
  puede (bloqueado por `api/marcas.php`, con 403).
- Una petición que cambia datos sin el token anti-CSRF se rechaza (403).
- Dos facturas seguidas, de marcas distintas, reciben numeración
  correlativa independiente (`LAB-2026-0001`, `ICC-2026-0001`).
- Cada factura creada deja su evento en el histórico; cada cambio de estado
  deja uno más.
- Intentar borrar una factura por la API da **405** (ese método no existe).
- El formulario de instalación se niega a crear una segunda cuenta en
  cuanto ya existe una.
- Volver a ejecutar `sql/01-esquema.sql` sobre una instalación con datos
  añade las tablas nuevas sin tocar lo que había.
- Una factura subida y contabilizada no se puede borrar (409); la asesora
  no puede reabrirla (403) y el administrador sí; sin sesión no se puede
  descargar ningún PDF (401); un archivo repetido se detecta (409); un PHP
  disfrazado de PDF se rechaza (415); y no se contabiliza una factura sin
  número, fecha, cliente/proveedor o importes.
- Las descargas para la asesoría (CSV y ZIP con los PDF) salen con los
  datos y archivos correctos del periodo elegido.

Y en un navegador real (Chromium, sin cabeza), con la misma
Content-Security-Policy que llevará en producción: inicio de sesión, todas
las vistas, crear una marca, un cliente y una factura con varios conceptos,
y arrastrar a la vez cuatro facturas de prueba (tres PDF y una foto). Se
comprobó que los datos leídos de cada PDF eran los correctos: emitida o
recibida, marca, número, fechas, proveedor/cliente, NIF, base, IVA, IRPF y
total cuadrado. Después se contabilizaron y se comprobó que los libros, el
resumen, cobros y pagos y la vista de asesoría las reflejan.

**Importante si cambias `index.html`:** el `.htaccess` solo deja ejecutar
el script del panel si coincide exactamente con su huella (`sha256-…` en
la línea `Content-Security-Policy`). Si se modifica el panel, hay que
actualizar esa huella; si no, el panel sale en blanco.

---

*Evolvix Global · Grupo Evolvix Global SL*
