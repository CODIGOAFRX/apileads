# Ajustes de la base de demostración que no se pueden hacer por la API.
# python preparar-bd.py manual.sqlite   (después de prepare.cjs)
import sqlite3, sys
c = sqlite3.connect(sys.argv[1] if len(sys.argv) > 1 else 'manual.sqlite')
# Elena entró «hoy 07:17» y rellenó el formulario la tarde anterior (horas en UTC).
c.execute("update leads set ultima_entrada='2026-10-27 06:17:00', creado_en='2026-10-27 06:17:00', fecha_origen='2026-10-26 19:42:00' where id=20")
c.execute("update leads set ultima_entrada='2026-10-27 08:05:00', creado_en='2026-10-27 08:05:00' where nombre='Raúl' and apellidos='Castro Molina'")
# Las ediciones de prepare.cjs no deben salir en el historial de las fichas.
c.execute("delete from lead_actividad where tipo='EDITADO'")
c.commit()
print('base preparada')
