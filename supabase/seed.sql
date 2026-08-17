-- Datos de prueba para Supabase
INSERT INTO otecs (
  id,
  nombre,
  rut_otec,
  email,
  telefono,
  plan,
  api_key,
  is_active,
  token_sence,
  test_env
) VALUES
(
  '11111111-1111-1111-1111-111111111111',
  'OTEC Demo Chile SpA',
  '76842190-3',
  'contacto@otecdemoschile.cl',
  '+56 9 8765 4321',
  'starter',
  'sk-demo-otec1-7f9a8b1c2d3e4f5a',
  TRUE,
  '550e8400-e29b-41d4-a716-446655440000',
  TRUE
),
(
  '22222222-2222-2222-2222-222222222222',
  'Centro Capacitación Norte Ltda',
  '77309412-K',
  'admin@capnorte.cl',
  '+56 55 234 5678',
  'free',
  'sk-demo-otec2-9a8b7c6d5e4f3a2b',
  TRUE,
  '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
  TRUE
)
ON CONFLICT (api_key) DO NOTHING;

INSERT INTO courses (
  otec_id,
  wp_course_id,
  wp_site_url,
  nombre_curso,
  codigo_sence,
  codigo_curso,
  linea_capacitacion,
  asistencia_obligatoria,
  solicitar_cierre,
  is_active
) VALUES
(
  '11111111-1111-1111-1111-111111111111',
  101,
  'https://campus.otecdemoschile.cl',
  'Excel Avanzado y Análisis de Datos con Power BI',
  '1237984501',
  'EXC-2026-A1',
  3,
  TRUE,
  FALSE,
  TRUE
)
ON CONFLICT (otec_id, wp_course_id, wp_site_url) DO NOTHING;
