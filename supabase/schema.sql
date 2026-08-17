-- ============================================================================
-- SENCE RCE Asistencia - Multi-tenant Supabase Schema (PostgreSQL)
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

DO $$ BEGIN
  CREATE TYPE plan_type AS ENUM ('free', 'starter', 'pro', 'enterprise');
EXCEPTION
  WHEN duplicate_object THEN null;
END $$;

-- 1. OTECs (Organismos Técnicos de Capacitación)
CREATE TABLE IF NOT EXISTS otecs (
  id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  nombre          TEXT NOT NULL,
  rut_otec        TEXT NOT NULL UNIQUE,
  email           TEXT NOT NULL UNIQUE,
  telefono        TEXT,
  plan            plan_type DEFAULT 'free',
  api_key         TEXT NOT NULL UNIQUE,
  is_active       BOOLEAN DEFAULT TRUE,
  token_sence     TEXT,
  test_env        BOOLEAN DEFAULT TRUE,
  notes           TEXT,
  plan_expires_at TIMESTAMPTZ,
  created_at      TIMESTAMPTZ DEFAULT NOW(),
  updated_at      TIMESTAMPTZ DEFAULT NOW()
);

-- 2. Cursos registrados por OTEC
CREATE TABLE IF NOT EXISTS courses (
  id                     BIGSERIAL PRIMARY KEY,
  otec_id                UUID NOT NULL REFERENCES otecs(id) ON DELETE CASCADE,
  wp_course_id           INTEGER NOT NULL,
  wp_site_url            TEXT NOT NULL,
  nombre_curso           TEXT,
  codigo_sence           TEXT,
  codigo_curso           TEXT,
  linea_capacitacion     INTEGER DEFAULT 3 CHECK (linea_capacitacion IN (1, 2, 3, 4, 5, 6)),
  asistencia_obligatoria BOOLEAN DEFAULT TRUE,
  solicitar_cierre       BOOLEAN DEFAULT FALSE,
  is_active              BOOLEAN DEFAULT TRUE,
  created_at             TIMESTAMPTZ DEFAULT NOW(),
  updated_at             TIMESTAMPTZ DEFAULT NOW(),
  UNIQUE(otec_id, wp_course_id, wp_site_url)
);

-- 3. Sesiones de Asistencia (Libro de Asistencia Digital)
CREATE TABLE IF NOT EXISTS sessions (
  id                  BIGSERIAL PRIMARY KEY,
  otec_id             UUID NOT NULL REFERENCES otecs(id) ON DELETE CASCADE,
  course_id           BIGINT REFERENCES courses(id) ON DELETE SET NULL,
  wp_user_id          INTEGER NOT NULL,
  wp_site_url         TEXT NOT NULL,
  run_alumno          TEXT NOT NULL,
  cod_sence           TEXT,
  codigo_curso        TEXT,
  id_sesion_alumno    TEXT UNIQUE,
  id_sesion_sence     TEXT,
  linea_capacitacion  INTEGER DEFAULT 3,
  fecha_hora_inicio   TEXT,
  zona_horaria        TEXT DEFAULT 'America/Santiago',
  session_opened_at   TIMESTAMPTZ DEFAULT NOW(),
  session_closed_at   TIMESTAMPTZ,
  fecha_hora_cierre   TEXT,
  tiempo_sesion_seg   INTEGER,
  error_code          TEXT,
  is_active           BOOLEAN DEFAULT TRUE
);

-- 4. Registro de Eventos y Telemetría para Facturación
CREATE TABLE IF NOT EXISTS usage_log (
  id          BIGSERIAL PRIMARY KEY,
  otec_id     UUID NOT NULL REFERENCES otecs(id) ON DELETE CASCADE,
  event_type  TEXT NOT NULL,
  metadata    JSONB,
  created_at  TIMESTAMPTZ DEFAULT NOW()
);

-- Índices de Rendimiento
CREATE INDEX IF NOT EXISTS idx_otecs_rut ON otecs(rut_otec);
CREATE INDEX IF NOT EXISTS idx_otecs_api_key ON otecs(api_key);
CREATE INDEX IF NOT EXISTS idx_courses_otec_id ON courses(otec_id);
CREATE INDEX IF NOT EXISTS idx_sessions_otec_id ON sessions(otec_id);
CREATE INDEX IF NOT EXISTS idx_sessions_id_sesion_alumno ON sessions(id_sesion_alumno);
CREATE INDEX IF NOT EXISTS idx_sessions_opened_at ON sessions(session_opened_at);
CREATE INDEX IF NOT EXISTS idx_sessions_run ON sessions(run_alumno);

-- Trigger de updated_at
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_otecs_updated_at ON otecs;
CREATE TRIGGER trg_otecs_updated_at BEFORE UPDATE ON otecs FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

DROP TRIGGER IF EXISTS trg_courses_updated_at ON courses;
CREATE TRIGGER trg_courses_updated_at BEFORE UPDATE ON courses FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Vista consolidada de asistencias
CREATE OR REPLACE VIEW session_summary AS
SELECT
  s.id AS session_id,
  s.otec_id,
  o.nombre AS otec_nombre,
  o.rut_otec,
  s.course_id,
  c.nombre_curso,
  COALESCE(s.cod_sence, c.codigo_sence) AS codigo_sence,
  COALESCE(s.codigo_curso, c.codigo_curso) AS codigo_curso,
  s.wp_user_id,
  s.wp_site_url,
  s.run_alumno,
  s.id_sesion_alumno,
  s.id_sesion_sence,
  s.linea_capacitacion,
  s.session_opened_at,
  s.session_closed_at,
  s.fecha_hora_inicio,
  s.fecha_hora_cierre,
  s.zona_horaria,
  s.tiempo_sesion_seg,
  CASE
    WHEN s.tiempo_sesion_seg IS NULL THEN
      CASE
        WHEN s.is_active THEN 'En curso (' || TO_CHAR(NOW() - s.session_opened_at, 'HH24:MI:SS') || ')'
        ELSE 'No registrado'
      END
    WHEN s.tiempo_sesion_seg >= 3600 THEN
      FLOOR(s.tiempo_sesion_seg / 3600)::TEXT || 'h ' ||
      LPAD(FLOOR((s.tiempo_sesion_seg % 3600) / 60)::TEXT, 2, '0') || 'm ' ||
      LPAD((s.tiempo_sesion_seg % 60)::TEXT, 2, '0') || 's'
    ELSE
      FLOOR(s.tiempo_sesion_seg / 60)::TEXT || 'm ' ||
      LPAD((s.tiempo_sesion_seg % 60)::TEXT, 2, '0') || 's'
  END AS tiempo_sesion_formateado,
  CASE
    WHEN s.error_code IS NOT NULL THEN 'Error: ' || s.error_code
    WHEN s.is_active = TRUE AND s.session_closed_at IS NULL THEN 'Activa / En curso'
    WHEN s.session_closed_at IS NOT NULL THEN 'Completada / Cerrada'
    ELSE 'Inactiva'
  END AS estado_sesion,
  s.error_code,
  s.is_active
FROM sessions s
LEFT JOIN courses c ON s.course_id = c.id
LEFT JOIN otecs o ON s.otec_id = o.id;
