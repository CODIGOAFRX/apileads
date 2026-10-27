# Une portada y cuerpo, dibuja la línea de la marca y el pie, y quita las marcas del índice.
import pymupdf, re, sys
MM = 72 / 25.4
INK = (0x1F/255, 0x20/255, 0x1D/255)
out = sys.argv[1]
doc = pymupdf.open('portada.pdf')
body = pymupdf.open('cuerpo.pdf')
for i, p in enumerate(body):
    W, H = p.rect.width, p.rect.height
    # la línea
    p.draw_rect(pymupdf.Rect(11.3 * MM, 15 * MM, 13.3 * MM, H - 17 * MM), color=None, fill=INK, radius=0.5)
    p.draw_circle(pymupdf.Point(12.3 * MM, 24 * MM), 2.6 * MM, color=INK, fill=(1, 1, 1), width=2.2)
    # el pie
    p.insert_text((27 * MM, H - 10 * MM), 'LEADS  ·  MANUAL DEL EQUIPO COMERCIAL', fontname='helv', fontsize=6.5, color=INK)
    num = str(i + 1)
    w = pymupdf.get_text_length(num, fontname='hebo', fontsize=8)
    p.insert_text((W - 17 * MM - w, H - 10 * MM), num, fontname='hebo', fontsize=8, color=INK)
NOMBRES = ['Índice', '1. Qué es Leads', '2. Entrar y salir', '3. La pantalla principal', '4. Una fila, pieza a pieza', '5. Tu rutina: llamar',
    '6. Los 4 resultados de una llamada', '7. WhatsApp y correo', '8. La ficha del lead', '9. Buscar, filtrar y exportar',
    '10. Dar de alta un lead a mano', '11. Importar leads desde Excel', '12. Estación Diseño y Grupo EFP', '13. Trabajar en equipo',
    '14. Leads en el móvil', '15. Casos prácticos', '16. Preguntas y problemas', '17. Para administración', 'Chuleta']
paginas = [pg for lvl, t, pg in body.get_toc() if lvl == 1]
assert len(paginas) == len(NOMBRES), paginas
toc = [[1, 'Portada', 1]] + [[1, n, pg + 1] for n, pg in zip(NOMBRES, paginas)]
doc.insert_pdf(body)
doc.set_toc(toc)
doc.set_metadata({'title': 'Manual de Leads · Equipo comercial', 'author': 'Estación Diseño', 'subject': 'Cómo usar la web de leads · Edición octubre 2026', 'creator': '', 'producer': ''})
doc.save(out, garbage=4, deflate=True)
print('guardado', out, doc.page_count, 'páginas')
