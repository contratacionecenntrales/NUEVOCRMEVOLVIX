-- =============================================================================
-- EVOLVIX GLOBAL · Facturación de Grupo — Esquema MySQL/MariaDB para Hostalia
--
-- Sustituye al esquema de Supabase/Postgres: aquí no hay RLS (seguridad por
-- fila dentro de la propia base de datos), así que esa parte la hace el
-- código PHP de la carpeta api/ — cada archivo explica, en un comentario,
-- qué regla de seguridad está aplicando y por qué.
--
-- Ejecútalo entero, de una vez, desde phpMyAdmin (pestaña SQL) en tu base de
-- datos de Hostalia. Se puede volver a ejecutar sin romper nada: todas las
-- tablas usan "IF NOT EXISTS".
-- =============================================================================

CREATE TABLE IF NOT EXISTS perfiles (
  id          CHAR(36)      NOT NULL PRIMARY KEY,
  nombre      VARCHAR(120)  NOT NULL,
  apellidos   VARCHAR(120)  NOT NULL DEFAULT '',
  email       VARCHAR(190)  NOT NULL,
  clave_hash  VARCHAR(255)  NOT NULL,
  rol         ENUM('admin','contable') NOT NULL DEFAULT 'contable',
  estado      ENUM('Activo','Suspendido') NOT NULL DEFAULT 'Activo',
  creado      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_perfiles_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marcas (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(160)  NOT NULL,
  prefijo   VARCHAR(3)    NOT NULL,
  cif       VARCHAR(40)   NOT NULL DEFAULT '',
  email     VARCHAR(190)  NOT NULL DEFAULT '',
  color     VARCHAR(10)   NOT NULL DEFAULT '#EF7F14',
  activa    TINYINT(1)    NOT NULL DEFAULT 1,
  creado    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_marcas_nombre (nombre),
  UNIQUE KEY uq_marcas_prefijo (prefijo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  marca_id    INT           NOT NULL,
  nombre      VARCHAR(200)  NOT NULL,
  cif         VARCHAR(40)   NOT NULL DEFAULT '',
  email       VARCHAR(190)  NOT NULL DEFAULT '',
  telefono    VARCHAR(40)   NOT NULL DEFAULT '',
  contacto    VARCHAR(160)  NOT NULL DEFAULT '',
  creado_por  CHAR(36)      NULL,
  creado      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_clientes_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_clientes_creador FOREIGN KEY (creado_por) REFERENCES perfiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un contador por marca y año. Lo usa api/numeracion.php con el truco
-- LAST_INSERT_ID(expr) de MySQL para repartir números sin que dos facturas
-- creadas a la vez puedan recibir el mismo número (seguro bajo concurrencia
-- real, no solo en pruebas con una sola petición a la vez).
CREATE TABLE IF NOT EXISTS numeracion_facturas (
  marca_id   INT NOT NULL,
  anio       INT NOT NULL,
  siguiente  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (marca_id, anio),
  CONSTRAINT fk_numeracion_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facturas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  numero         VARCHAR(40)   NOT NULL,
  marca_id       INT           NOT NULL,
  cliente_id     INT           NOT NULL,
  fecha_emision  DATE          NOT NULL,
  fecha_pago     DATE          NULL,
  base           DECIMAL(12,2) NOT NULL DEFAULT 0,
  iva            DECIMAL(5,2)  NOT NULL DEFAULT 21,
  total          DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado         ENUM('Pendiente','Pagada','Impagada','Anulada') NOT NULL DEFAULT 'Pendiente',
  creado_por     CHAR(36)      NULL,
  creado         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_facturas_numero (numero),
  CONSTRAINT fk_facturas_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facturas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_facturas_creador FOREIGN KEY (creado_por) REFERENCES perfiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS factura_lineas (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  factura_id       INT           NOT NULL,
  descripcion      VARCHAR(300)  NOT NULL,
  cantidad         DECIMAL(10,2) NOT NULL DEFAULT 1,
  precio_unitario  DECIMAL(12,2) NOT NULL DEFAULT 0,
  orden            INT           NOT NULL DEFAULT 0,
  CONSTRAINT fk_lineas_factura FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de cada factura. Nunca se actualiza ni se borra desde la
-- aplicación: api/facturas.php solo tiene INSERT hacia esta tabla, igual que
-- en la versión de Supabase el trigger solo podía insertar.
CREATE TABLE IF NOT EXISTS factura_eventos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  factura_id  INT           NOT NULL,
  tipo        VARCHAR(40)   NOT NULL,
  detalle     VARCHAR(300)  NOT NULL DEFAULT '',
  creado_por  CHAR(36)      NULL,
  creado      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_eventos_factura FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facturas que se SUBEN al sistema (PDF o foto), tanto emitidas como
-- recibidas (gastos). Entran como borrador ("por revisar", contabilizado = 0)
-- en cuanto se arrastran al panel, y solo cuentan en los libros y en el
-- resumen cuando alguien las revisa y pulsa Contabilizar.
-- Igual que con las facturas creadas en el CRM, una factura contabilizada
-- nunca se borra: solo se puede anular. Solo un borrador se puede descartar.
CREATE TABLE IF NOT EXISTS documentos (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  tipo               ENUM('emitida','recibida') NOT NULL,
  marca_id           INT           NOT NULL,
  numero             VARCHAR(60)   NOT NULL DEFAULT '',
  fecha              DATE          NULL,
  vencimiento        DATE          NULL,
  contraparte        VARCHAR(200)  NOT NULL DEFAULT '',
  nif                VARCHAR(40)   NOT NULL DEFAULT '',
  concepto           VARCHAR(300)  NOT NULL DEFAULT '',
  categoria          VARCHAR(60)   NOT NULL DEFAULT '',
  base               DECIMAL(12,2) NOT NULL DEFAULT 0,
  iva_pct            DECIMAL(5,2)  NOT NULL DEFAULT 21,
  cuota_iva          DECIMAL(12,2) NOT NULL DEFAULT 0,
  irpf_pct           DECIMAL(5,2)  NOT NULL DEFAULT 0,
  cuota_irpf         DECIMAL(12,2) NOT NULL DEFAULT 0,
  total              DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado             ENUM('Pendiente','Pagada','Impagada','Anulada') NOT NULL DEFAULT 'Pendiente',
  fecha_pago         DATE          NULL,
  contabilizado      TINYINT(1)    NOT NULL DEFAULT 0,
  contabilizado_por  CHAR(36)      NULL,
  contabilizado_en   DATETIME      NULL,
  archivo            VARCHAR(80)   NULL,
  archivo_nombre     VARCHAR(255)  NOT NULL DEFAULT '',
  archivo_tipo       VARCHAR(60)   NOT NULL DEFAULT '',
  archivo_bytes      INT           NOT NULL DEFAULT 0,
  archivo_sha256     CHAR(64)      NULL,
  notas              VARCHAR(500)  NOT NULL DEFAULT '',
  creado_por         CHAR(36)      NULL,
  creado             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_documentos_archivo (archivo_sha256),
  KEY ix_documentos_tipo_fecha (tipo, fecha),
  CONSTRAINT fk_documentos_marca FOREIGN KEY (marca_id) REFERENCES marcas(id) ON DELETE RESTRICT,
  CONSTRAINT fk_documentos_creador FOREIGN KEY (creado_por) REFERENCES perfiles(id) ON DELETE SET NULL,
  CONSTRAINT fk_documentos_contable FOREIGN KEY (contabilizado_por) REFERENCES perfiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de cada factura subida (subida, contabilizada, cobrada,
-- anulada...). Igual que factura_eventos: la aplicación solo inserta.
-- Sin clave foránea a propósito: si se descarta un borrador, su rastro
-- ("subido" y "descartado") se conserva.
CREATE TABLE IF NOT EXISTS documento_eventos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  documento_id  INT           NOT NULL,
  tipo          VARCHAR(40)   NOT NULL,
  detalle       VARCHAR(300)  NOT NULL DEFAULT '',
  creado_por    CHAR(36)      NULL,
  creado        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_documento_eventos_doc (documento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Siembra las marcas del grupo, si todavía no existen: la propia sociedad,
-- Grupo Evolvix Global (facturas a su nombre), y sus dos marcas registradas.
-- El prefijo es el que llevará la numeración de sus facturas
-- (EVO-2026-0001, LAB-2026-0001, ICC-2026-0001...).
INSERT INTO marcas (nombre, prefijo, cif, email, color, activa)
SELECT 'Grupo Evolvix Global', 'EVO', '', '', '#EF7F14', 1
WHERE NOT EXISTS (SELECT 1 FROM marcas WHERE nombre IN ('Grupo Evolvix Global', 'Evolvix Global') OR prefijo = 'EVO');

-- Instalaciones anteriores la crearon como «Evolvix Global»: se renombra.
UPDATE marcas SET nombre = 'Grupo Evolvix Global' WHERE prefijo = 'EVO' AND nombre = 'Evolvix Global';

INSERT INTO marcas (nombre, prefijo, cif, email, color, activa)
SELECT 'Labs24k', 'LAB', '', '', '#17C8C0', 1
WHERE NOT EXISTS (SELECT 1 FROM marcas WHERE nombre = 'Labs24k' OR prefijo = 'LAB');

INSERT INTO marcas (nombre, prefijo, cif, email, color, activa)
SELECT 'ICC (Integral Connection Consulting)', 'ICC', '', '', '#3B82F6', 1
WHERE NOT EXISTS (SELECT 1 FROM marcas WHERE nombre = 'ICC (Integral Connection Consulting)' OR prefijo = 'ICC');

-- =============================================================================
-- COMPROBACIÓN
-- =============================================================================
SELECT
  (SELECT COUNT(*) FROM marcas) AS marcas,
  (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN ('perfiles','marcas','clientes','numeracion_facturas',
                           'facturas','factura_lineas','factura_eventos',
                           'documentos','documento_eventos')) AS tablas_creadas;
-- Esperado: marcas >= 3, tablas_creadas = 9.
