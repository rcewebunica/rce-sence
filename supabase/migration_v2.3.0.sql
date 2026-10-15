-- ============================================================================
-- Migración v2.3.0
-- 1) Fechas de inicio y término por curso
-- 2) Limpieza del Libro de Asistencia Digital (inicio sin registros)
-- Ejecutar en Supabase -> SQL Editor
-- ============================================================================

ALTER TABLE courses ADD COLUMN IF NOT EXISTS fecha_inicio  DATE;
ALTER TABLE courses ADD COLUMN IF NOT EXISTS fecha_termino DATE;
ALTER TABLE courses ADD COLUMN IF NOT EXISTS email_respaldo TEXT;

-- Borra TODAS las asistencias registradas hasta ahora (opcional si se desea reiniciar en blanco)
-- DELETE FROM sessions;
-- DELETE FROM usage_log;
